<?php

namespace App\Http\Controllers;

use App\Models\MunicipalWorkPlan;
use App\Models\ParliamentaryAmendment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MunicipalWorkPlanStageController extends Controller
{
    public function store(Request $request, int $emenda, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        [$amendment, $plan] = $this->context($request, $emenda, $currentMunicipality);
        $validated = $request->validate($this->rules());

        if (! $formSubmission->consume($request, "municipal-work-plan-stage-create-{$plan->id}")) {
            return back()->with('warning', 'A inclusão desta etapa já foi processada.');
        }

        DB::transaction(function () use ($request, $amendment, $plan, $validated, $auditTrail): void {
            $stage = $plan->stages()->create([
                ...$validated,
                'municipality_id' => $amendment->municipality_id,
                'parliamentary_amendment_id' => $amendment->id,
                'created_by' => $request->user()->id,
            ]);
            $auditTrail->recordOperation($request, $amendment, 'municipal_work_plan_stage_created', [
                'work_plan_stage' => $stage->title,
                'planned_amount' => $stage->planned_amount,
            ]);
        });

        return back()->with('status', 'Etapa adicionada ao cronograma.');
    }

    public function update(Request $request, int $emenda, int $etapa, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        [$amendment, $plan] = $this->context($request, $emenda, $currentMunicipality);
        $stage = $plan->stages()->findOrFail($etapa);
        $validated = $request->validate($this->rules());

        if (! $formSubmission->consume($request, "municipal-work-plan-stage-update-{$stage->id}")) {
            return back()->with('warning', 'A alteração desta etapa já foi processada.');
        }

        DB::transaction(function () use ($request, $amendment, $stage, $validated, $auditTrail): void {
            $oldValues = $stage->only(array_keys($validated));
            $stage->update($validated);
            $auditTrail->recordOperation($request, $amendment, 'municipal_work_plan_stage_updated', [
                'work_plan_stage' => $stage->title,
                'planned_amount' => $stage->planned_amount,
            ], [
                'work_plan_stage' => $oldValues['title'],
                'planned_amount' => $oldValues['planned_amount'],
            ]);
        });

        return back()->with('status', 'Etapa atualizada.');
    }

    public function destroy(Request $request, int $emenda, int $etapa, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        [$amendment, $plan] = $this->context($request, $emenda, $currentMunicipality);
        $stage = $plan->stages()->findOrFail($etapa);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "municipal-work-plan-stage-delete-{$stage->id}")) {
            return back()->with('warning', 'A remoção desta etapa já foi processada.');
        }

        DB::transaction(function () use ($request, $amendment, $stage, $auditTrail): void {
            $auditTrail->recordOperation($request, $amendment, 'municipal_work_plan_stage_deleted', [
                'work_plan_stage' => $stage->title,
                'planned_amount' => $stage->planned_amount,
            ]);
            $stage->delete();
        });

        return back()->with('status', 'Etapa removida do cronograma.');
    }

    /** @return array{ParliamentaryAmendment, MunicipalWorkPlan} */
    private function context(Request $request, int $id, CurrentMunicipality $currentMunicipality): array
    {
        $amendment = $currentMunicipality->get($request)->amendments()->with(['municipality', 'municipalWorkPlan'])->findOrFail($id);
        abort_unless($amendment->supportsTcespCompliance(), 404);
        $plan = $amendment->municipalWorkPlan ?? abort(404);
        abort_unless($plan->isEditable(), 409);

        return [$amendment, $plan];
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            '_submission_token' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'physical_delivery' => ['required', 'string', 'max:3000'],
            'planned_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['required', 'date', 'after_or_equal:planned_start_at'],
            'sort_order' => ['required', 'integer', 'between:0,999'],
        ];
    }
}
