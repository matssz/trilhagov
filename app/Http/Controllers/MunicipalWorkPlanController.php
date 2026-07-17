<?php

namespace App\Http\Controllers;

use App\Models\MunicipalAdmissibilityReview;
use App\Models\MunicipalWorkPlan;
use App\Models\ParliamentaryAmendment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\MunicipalWorkPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MunicipalWorkPlanController extends Controller
{
    public function index(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalWorkPlanService $workPlanService,
    ): View {
        $amendment = $this->amendment($request, $emenda, $currentMunicipality)
            ->load([
                'municipality',
                'responsibleUser',
                'municipalWorkPlan.stages',
                'municipalWorkPlan.reviews.reviewer',
            ]);
        abort_unless($amendment->supportsTcespCompliance(), 404);

        $plan = $amendment->municipalWorkPlan;
        $canEdit = $request->user()->canEditMunicipality($amendment->municipality_id);
        $canReview = $request->user()->roleForMunicipality($amendment->municipality_id) === 'manager';

        return view('amendments.work-plan', [
            'amendment' => $amendment,
            'plan' => $plan,
            'canEdit' => $canEdit,
            'canReview' => $canReview,
            'readiness' => $plan ? $workPlanService->readiness($plan, $amendment) : null,
            'beneficiaryTypes' => MunicipalWorkPlan::beneficiaryTypes(),
            'engineeringStatuses' => MunicipalWorkPlan::engineeringStatuses(),
            'pcaStatuses' => MunicipalWorkPlan::pcaStatuses(),
            'criteria' => $workPlanService->admissibilityCriteria(),
            'criterionStatuses' => MunicipalAdmissibilityReview::criterionStatuses(),
            'conclusions' => MunicipalAdmissibilityReview::conclusions(),
            'createToken' => $canEdit && $plan === null
                ? $formSubmission->issue($request, "municipal-work-plan-create-{$amendment->id}")
                : null,
            'updateToken' => $canEdit && $plan?->isEditable()
                ? $formSubmission->issue($request, "municipal-work-plan-update-{$plan->id}")
                : null,
            'submitToken' => $canEdit && $plan?->isEditable()
                ? $formSubmission->issue($request, "municipal-work-plan-submit-{$plan->id}")
                : null,
            'stageCreateToken' => $canEdit && $plan?->isEditable()
                ? $formSubmission->issue($request, "municipal-work-plan-stage-create-{$plan->id}")
                : null,
            'stageUpdateTokens' => $canEdit && $plan?->isEditable()
                ? $plan->stages->mapWithKeys(fn ($stage) => [
                    $stage->id => $formSubmission->issue($request, "municipal-work-plan-stage-update-{$stage->id}"),
                ])
                : collect(),
            'stageDeleteTokens' => $canEdit && $plan?->isEditable()
                ? $plan->stages->mapWithKeys(fn ($stage) => [
                    $stage->id => $formSubmission->issue($request, "municipal-work-plan-stage-delete-{$stage->id}"),
                ])
                : collect(),
            'reviewToken' => $canReview && $plan?->status === MunicipalWorkPlan::STATUS_UNDER_REVIEW
                ? $formSubmission->issue($request, "municipal-admissibility-review-{$plan->id}-{$plan->revision_number}")
                : null,
        ]);
    }

    public function store(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $amendment = $this->amendment($request, $emenda, $currentMunicipality)->load('municipality');
        abort_unless($amendment->supportsTcespCompliance(), 404);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "municipal-work-plan-create-{$amendment->id}")) {
            return back()->with('warning', 'A criação deste plano já foi processada.');
        }

        if ($amendment->municipalWorkPlan()->exists()) {
            return back()->with('warning', 'Esta emenda já possui um plano de trabalho.');
        }

        DB::transaction(function () use ($request, $amendment, $auditTrail): void {
            $plan = $amendment->municipalWorkPlan()->create([
                'municipality_id' => $amendment->municipality_id,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'beneficiary_type' => 'municipal_body',
                'beneficiary_name' => $amendment->responsible_department,
                'object_description' => $amendment->object,
                'planned_start_at' => $amendment->indicated_at,
                'planned_end_at' => $amendment->execution_deadline,
            ]);
            $auditTrail->recordOperation($request, $amendment, 'municipal_work_plan_created', [
                'work_plan_status' => $plan->status,
            ]);
        });

        return back()->with('status', 'Plano de trabalho iniciado. Complete os campos e o cronograma.');
    }

    public function update(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $amendment = $this->amendment($request, $emenda, $currentMunicipality)->load(['municipality', 'municipalWorkPlan']);
        abort_unless($amendment->supportsTcespCompliance(), 404);
        $plan = $amendment->municipalWorkPlan ?? abort(404);
        abort_unless($plan->isEditable(), 409);

        $request->merge([
            'beneficiary_cnpj' => preg_replace('/\D/', '', (string) $request->input('beneficiary_cnpj')) ?: null,
            'health_related' => $request->boolean('health_related'),
            'health_reserve_verified' => $request->boolean('health_reserve_verified'),
            'includes_engineering' => $request->boolean('includes_engineering'),
        ]);
        $validated = $request->validate($this->rules());

        if (! $formSubmission->consume($request, "municipal-work-plan-update-{$plan->id}")) {
            return back()->with('warning', 'Esta atualização do plano já foi processada.');
        }

        if (! $validated['health_related']) {
            $validated['health_reserve_verified'] = false;
        }
        if (! $validated['includes_engineering']) {
            $validated['engineering_project_status'] = 'not_applicable';
            $validated['environmental_license_status'] = 'not_applicable';
        }

        DB::transaction(function () use ($request, $amendment, $plan, $validated, $auditTrail): void {
            $plan->update([...$validated, 'updated_by' => $request->user()->id]);
            $auditTrail->recordOperation($request, $amendment, 'municipal_work_plan_updated', [
                'work_plan_status' => $plan->status,
                'work_plan_revision' => $plan->revision_number,
                'work_plan_fields' => implode(', ', array_keys($plan->getChanges())),
            ]);
        });

        return back()->with('status', 'Plano de trabalho salvo.');
    }

    public function submit(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalWorkPlanService $workPlanService,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $amendment = $this->amendment($request, $emenda, $currentMunicipality)->load(['municipality', 'municipalWorkPlan.stages']);
        abort_unless($amendment->supportsTcespCompliance(), 404);
        $plan = $amendment->municipalWorkPlan ?? abort(404);
        abort_unless($plan->isEditable(), 409);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "municipal-work-plan-submit-{$plan->id}")) {
            return back()->with('warning', 'Este envio para análise já foi processado.');
        }

        $workPlanService->ensureReadyForSubmission($plan, $amendment);

        DB::transaction(function () use ($request, $amendment, $plan, $auditTrail): void {
            $locked = MunicipalWorkPlan::query()->lockForUpdate()->findOrFail($plan->id);
            abort_unless($locked->isEditable(), 409);
            $locked->update([
                'status' => MunicipalWorkPlan::STATUS_UNDER_REVIEW,
                'revision_number' => $locked->revision_number + 1,
                'submitted_at' => now(),
                'updated_by' => $request->user()->id,
            ]);
            $auditTrail->recordOperation($request, $amendment, 'municipal_work_plan_submitted', [
                'work_plan_status' => $locked->status,
                'work_plan_revision' => $locked->revision_number,
            ]);
        });

        return back()->with('status', 'Plano enviado para parecer de admissibilidade.');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            '_submission_token' => ['required', 'string'],
            'beneficiary_type' => ['required', Rule::in(array_keys(MunicipalWorkPlan::beneficiaryTypes()))],
            'beneficiary_name' => ['required', 'string', 'max:255'],
            'beneficiary_cnpj' => ['nullable', 'required_unless:beneficiary_type,municipal_body', 'digits:14'],
            'beneficiary_contact' => ['required', 'string', 'max:255'],
            'object_description' => ['required', 'string', 'max:5000'],
            'public_need' => ['required', 'string', 'max:5000'],
            'physical_target' => ['required', 'string', 'max:3000'],
            'finalistic_target' => ['required', 'string', 'max:3000'],
            'budget_program' => ['required', 'string', 'max:255'],
            'budget_action' => ['required', 'string', 'max:255'],
            'application_plan' => ['required', 'string', 'max:5000'],
            'cost_memory' => ['required', 'string', 'max:5000'],
            'maintenance_plan' => ['required', 'string', 'max:5000'],
            'health_related' => ['required', 'boolean'],
            'health_reserve_verified' => ['required', 'boolean'],
            'includes_engineering' => ['required', 'boolean'],
            'engineering_project_status' => ['required', Rule::in(array_keys(MunicipalWorkPlan::engineeringStatuses()))],
            'environmental_license_status' => ['required', Rule::in(array_keys(MunicipalWorkPlan::engineeringStatuses()))],
            'pca_status' => ['required', Rule::in(array_keys(MunicipalWorkPlan::pcaStatuses()))],
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['required', 'date', 'after_or_equal:planned_start_at'],
        ];
    }

    private function amendment(Request $request, int $id, CurrentMunicipality $currentMunicipality): ParliamentaryAmendment
    {
        return $currentMunicipality->get($request)->amendments()->findOrFail($id);
    }
}
