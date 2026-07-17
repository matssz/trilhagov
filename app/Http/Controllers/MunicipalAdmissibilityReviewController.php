<?php

namespace App\Http\Controllers;

use App\Models\MunicipalAdmissibilityReview;
use App\Models\MunicipalWorkPlan;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\MunicipalWorkPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MunicipalAdmissibilityReviewController extends Controller
{
    public function store(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalWorkPlanService $workPlanService,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $amendment = $currentMunicipality->get($request)
            ->amendments()
            ->with(['municipality', 'municipalWorkPlan.stages'])
            ->findOrFail($emenda);
        abort_unless($amendment->supportsTcespCompliance(), 404);
        $plan = $amendment->municipalWorkPlan ?? abort(404);
        abort_unless($plan->status === MunicipalWorkPlan::STATUS_UNDER_REVIEW, 409);

        $criteriaDefinitions = $workPlanService->admissibilityCriteria();
        $rules = [
            '_submission_token' => ['required', 'string'],
            'conclusion' => ['required', Rule::in(array_keys(MunicipalAdmissibilityReview::conclusions()))],
            'rationale' => ['required', 'string', 'max:5000'],
            'corrections_requested' => ['nullable', 'required_if:conclusion,adjustments_requested', 'string', 'max:5000'],
        ];
        foreach (array_keys($criteriaDefinitions) as $criterion) {
            $rules["criteria.{$criterion}"] = ['required', Rule::in(array_keys(MunicipalAdmissibilityReview::criterionStatuses()))];
        }
        $validated = $request->validate($rules, [
            'corrections_requested.required_if' => 'Descreva os ajustes que deverão ser realizados.',
            'criteria.*.required' => 'Avalie todos os critérios antes de emitir o parecer.',
        ]);

        if (! $formSubmission->consume($request, "municipal-admissibility-review-{$plan->id}-{$plan->revision_number}")) {
            return back()->with('warning', 'Este parecer já foi processado.');
        }

        $hasNotMet = collect($validated['criteria'])->contains('not_met');
        if ($validated['conclusion'] === MunicipalAdmissibilityReview::CONCLUSION_APPROVED && $hasNotMet) {
            throw ValidationException::withMessages([
                'conclusion' => 'Um plano com critério não atendido não pode ser aprovado.',
            ]);
        }
        if (in_array($validated['conclusion'], [
            MunicipalAdmissibilityReview::CONCLUSION_ADJUSTMENTS,
            MunicipalAdmissibilityReview::CONCLUSION_REJECTED,
        ], true) && ! $hasNotMet) {
            throw ValidationException::withMessages([
                'conclusion' => 'Marque ao menos um critério como não atendido para esta conclusão.',
            ]);
        }

        DB::transaction(function () use ($request, $amendment, $plan, $validated, $workPlanService, $auditTrail): void {
            $locked = MunicipalWorkPlan::query()->lockForUpdate()->with('stages')->findOrFail($plan->id);
            abort_unless($locked->status === MunicipalWorkPlan::STATUS_UNDER_REVIEW, 409);

            $review = $locked->reviews()->create([
                'municipality_id' => $amendment->municipality_id,
                'parliamentary_amendment_id' => $amendment->id,
                'reviewed_by' => $request->user()->id,
                'plan_revision' => $locked->revision_number,
                'conclusion' => $validated['conclusion'],
                'criteria' => $validated['criteria'],
                'rationale' => $validated['rationale'],
                'corrections_requested' => $validated['corrections_requested'] ?? null,
                'plan_snapshot' => $workPlanService->snapshot($locked, $amendment),
            ]);

            $locked->update([
                'status' => $review->conclusion,
                'approved_at' => $review->conclusion === MunicipalAdmissibilityReview::CONCLUSION_APPROVED ? now() : null,
            ]);
            $auditTrail->recordOperation($request, $amendment, 'municipal_admissibility_review_created', [
                'work_plan_revision' => $review->plan_revision,
                'admissibility_conclusion' => $review->conclusion,
                'admissibility_rationale' => $review->rationale,
            ]);
        });

        return back()->with('status', 'Parecer de admissibilidade emitido e preservado no histórico.');
    }
}
