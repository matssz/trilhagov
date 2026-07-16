<?php

namespace App\Http\Controllers;

use App\Models\ExecutionStage;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExecutionStageController extends Controller
{
    public function store(Request $request, int $emenda, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail, IntegrityAlertService $integrityAlertService): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $validated = $this->validated($request, $municipality->id);

        if (! $formSubmission->consume($request, "execution-stage-create-{$amendment->id}")) {
            return back()->with('warning', 'Esta etapa já foi processada.');
        }

        $stage = DB::transaction(function () use ($request, $validated, $municipality, $amendment, $auditTrail) {
            $stage = $amendment->executionStages()->create([
                ...$this->normalized($validated),
                'municipality_id' => $municipality->id,
                'created_by' => $request->user()->id,
            ]);
            $auditTrail->recordOperation($request, $amendment, 'execution_stage_created', [
                'stage' => $stage->title,
                'stage_status' => $stage->statusLabel(),
                'progress_percentage' => $stage->progress_percentage,
            ]);

            return $stage;
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', "Etapa “{$stage->title}” criada com sucesso.");
    }

    public function update(Request $request, int $emenda, int $etapa, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail, IntegrityAlertService $integrityAlertService): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $stage = $amendment->executionStages()->findOrFail($etapa);
        $validated = $this->validated($request, $municipality->id);

        if (! $formSubmission->consume($request, "execution-stage-update-{$stage->id}")) {
            return back()->with('warning', 'Esta atualização de etapa já foi processada.');
        }

        DB::transaction(function () use ($request, $validated, $amendment, $stage, $auditTrail): void {
            $oldValues = [
                'title' => $stage->title,
                'stage_status' => $stage->statusLabel(),
                'progress_percentage' => $stage->progress_percentage,
                'planned_end_at' => $stage->planned_end_at,
            ];
            $stage->update($this->normalized($validated));
            $auditTrail->recordOperation($request, $amendment, 'execution_stage_updated', [
                'title' => $stage->title,
                'stage_status' => $stage->statusLabel(),
                'progress_percentage' => $stage->progress_percentage,
                'planned_end_at' => $stage->planned_end_at,
            ], $oldValues);
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Etapa atualizada com sucesso.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $municipalityId): array
    {
        return $request->validate([
            '_submission_token' => ['required', 'string'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'responsible_user_id' => ['nullable', 'integer', Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query
                ->where('municipality_id', $municipalityId)
                ->whereIn('role', ['manager', 'editor']))],
            'status' => ['required', Rule::in(array_keys(ExecutionStage::statuses()))],
            'progress_percentage' => ['required', 'integer', 'between:0,100'],
            'planned_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'planned_start_at' => ['nullable', 'date'],
            'planned_end_at' => ['nullable', 'date', 'after_or_equal:planned_start_at'],
            'completed_at' => ['nullable', 'date'],
            'sort_order' => ['required', 'integer', 'between:0,65000'],
        ], [
            'responsible_user_id.exists' => 'Selecione um responsável com perfil de gestor ou editor neste município.',
            'planned_end_at.after_or_equal' => 'A data final não pode ser anterior à data inicial.',
        ]);
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function normalized(array $validated): array
    {
        if ($validated['status'] === ExecutionStage::STATUS_COMPLETED) {
            $validated['progress_percentage'] = 100;
            $validated['completed_at'] ??= today()->toDateString();
        } elseif ($validated['status'] === ExecutionStage::STATUS_PLANNED) {
            $validated['progress_percentage'] = 0;
            $validated['completed_at'] = null;
        } else {
            $validated['completed_at'] = null;
        }

        return $validated;
    }
}
