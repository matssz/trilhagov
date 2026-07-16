<?php

namespace App\Http\Controllers;

use App\Models\AccountabilityDiligence;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountabilityDiligenceController extends Controller
{
    public function store(Request $request, int $emenda, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail, IntegrityAlertService $integrityAlertService): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $process = $amendment->accountabilityProcess()->firstOrFail();
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:3000'],
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query
                ->where('municipality_id', $municipality->id)
                ->whereIn('role', ['manager', 'editor']))],
            'received_at' => ['required', 'date'],
            'due_at' => ['required', 'date', 'after_or_equal:received_at'],
        ], [
            'assigned_user_id.exists' => 'Selecione um responsável com perfil de gestor ou editor neste município.',
            'due_at.after_or_equal' => 'O prazo não pode ser anterior ao recebimento da diligência.',
        ]);

        if (! $formSubmission->consume($request, "accountability-diligence-create-{$process->id}")) {
            return back()->with('warning', 'Esta diligência já foi processada.');
        }

        DB::transaction(function () use ($request, $validated, $municipality, $amendment, $process, $auditTrail): void {
            $diligence = $process->diligences()->create([
                ...$validated,
                'municipality_id' => $municipality->id,
                'parliamentary_amendment_id' => $amendment->id,
                'created_by' => $request->user()->id,
                'status' => AccountabilityDiligence::STATUS_OPEN,
            ]);
            if (in_array($process->status, ['submitted', 'under_review'], true)) {
                $process->update(['status' => 'pending_correction']);
            }
            $auditTrail->recordOperation($request, $amendment, 'accountability_diligence_created', [
                'diligence' => $diligence->title,
                'diligence_due_at' => $diligence->due_at,
                'assigned_user_id' => $diligence->assigned_user_id,
            ]);
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Diligência registrada com sucesso.');
    }

    public function update(Request $request, int $emenda, int $diligencia, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail, IntegrityAlertService $integrityAlertService): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $process = $amendment->accountabilityProcess()->firstOrFail();
        $diligence = $process->diligences()->findOrFail($diligencia);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'status' => ['required', Rule::in(array_keys(AccountabilityDiligence::statuses()))],
            'response_notes' => ['nullable', 'string', 'max:3000'],
            'response_protocol' => ['nullable', 'string', 'max:120'],
        ]);

        if (! $formSubmission->consume($request, "accountability-diligence-update-{$diligence->id}")) {
            return back()->with('warning', 'Esta atualização da diligência já foi processada.');
        }

        if ($validated['status'] !== AccountabilityDiligence::STATUS_OPEN
            && (blank($validated['response_notes']) || blank($validated['response_protocol']))) {
            throw ValidationException::withMessages([
                'response_protocol' => 'Informe a resposta e o protocolo antes de concluir a diligência.',
            ]);
        }

        DB::transaction(function () use ($request, $validated, $amendment, $diligence, $auditTrail): void {
            $oldStatus = $diligence->statusLabel();
            $diligence->update([
                ...$validated,
                'responded_at' => $validated['status'] === AccountabilityDiligence::STATUS_OPEN ? null : now(),
            ]);
            $auditTrail->recordOperation($request, $amendment, 'accountability_diligence_updated', [
                'diligence' => $diligence->title,
                'diligence_status' => $diligence->statusLabel(),
                'response_protocol' => $diligence->response_protocol,
            ], [
                'diligence' => $diligence->title,
                'diligence_status' => $oldStatus,
                'response_protocol' => null,
            ]);
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Diligência atualizada com sucesso.');
    }
}
