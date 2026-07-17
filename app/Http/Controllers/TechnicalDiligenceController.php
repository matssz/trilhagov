<?php

namespace App\Http\Controllers;

use App\Models\TechnicalDiligence;
use App\Models\TechnicalImpediment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TechnicalDiligenceController extends Controller
{
    public function store(Request $request, int $emenda, int $impedimento, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $impediment = $amendment->technicalImpediments()->findOrFail($impedimento);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'title' => ['required', 'string', 'max:180'],
            'request_details' => ['required', 'string', 'max:5000'],
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query
                ->where('municipality_id', $municipality->id)
                ->whereIn('role', ['manager', 'editor']))],
            'requested_at' => ['required', 'date'],
            'due_at' => ['required', 'date', 'after_or_equal:requested_at'],
        ]);

        if (! $formSubmission->consume($request, "technical-diligence-create-{$impediment->id}")) {
            return back()->with('warning', 'Esta diligência já foi registrada.');
        }

        DB::transaction(function () use ($request, $validated, $municipality, $amendment, $impediment, $auditTrail): void {
            $diligence = $impediment->diligences()->create([
                ...$validated,
                'municipality_id' => $municipality->id,
                'parliamentary_amendment_id' => $amendment->id,
                'created_by' => $request->user()->id,
                'status' => TechnicalDiligence::STATUS_OPEN,
            ]);
            if ($impediment->isOpen()) {
                $impediment->update(['status' => TechnicalImpediment::STATUS_UNDER_DILIGENCE]);
            }
            $auditTrail->recordOperation($request, $amendment, 'technical_diligence_created', [
                'impediment' => $impediment->title,
                'technical_diligence' => $diligence->title,
                'diligence_due_at' => $diligence->due_at,
                'assigned_user_id' => $diligence->assigned_user_id,
            ]);
        });

        return back()->with('status', 'Diligência técnica aberta.');
    }

    public function update(Request $request, int $emenda, int $impedimento, int $diligencia, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $impediment = $amendment->technicalImpediments()->findOrFail($impedimento);
        $diligence = $impediment->diligences()->findOrFail($diligencia);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'status' => ['required', Rule::in(array_keys(TechnicalDiligence::statuses()))],
            'response_notes' => ['nullable', 'string', 'max:5000'],
            'response_protocol' => ['nullable', 'string', 'max:120'],
            'evidence_document_id' => ['nullable', 'integer', Rule::exists('amendment_documents', 'id')->where(fn ($query) => $query
                ->where('municipality_id', $municipality->id)
                ->where('parliamentary_amendment_id', $amendment->id))],
        ]);

        if (! $formSubmission->consume($request, "technical-diligence-update-{$diligence->id}")) {
            return back()->with('warning', 'Esta resposta já foi processada.');
        }

        if ($validated['status'] !== TechnicalDiligence::STATUS_OPEN
            && (blank($validated['response_notes'] ?? null) || blank($validated['response_protocol'] ?? null))) {
            throw ValidationException::withMessages([
                'response_protocol' => 'Informe a resposta e o protocolo antes de alterar a situação da diligência.',
            ]);
        }

        if (in_array($validated['status'], [TechnicalDiligence::STATUS_ACCEPTED, TechnicalDiligence::STATUS_REJECTED], true)
            && $request->user()->roleForMunicipality($municipality->id) !== 'manager') {
            abort(403);
        }

        DB::transaction(function () use ($request, $validated, $amendment, $impediment, $diligence, $auditTrail): void {
            $oldStatus = $diligence->status;
            $diligence->update([
                ...$validated,
                'responded_at' => $validated['status'] === TechnicalDiligence::STATUS_OPEN ? null : now(),
            ]);
            if ($validated['status'] === TechnicalDiligence::STATUS_REJECTED) {
                $impediment->update(['status' => TechnicalImpediment::STATUS_UNDER_DILIGENCE]);
            }
            $auditTrail->recordOperation($request, $amendment, 'technical_diligence_updated', [
                'impediment' => $impediment->title,
                'technical_diligence' => $diligence->title,
                'technical_diligence_status' => $diligence->status,
                'response_protocol' => $diligence->response_protocol,
            ], [
                'impediment' => $impediment->title,
                'technical_diligence' => $diligence->title,
                'technical_diligence_status' => $oldStatus,
                'response_protocol' => null,
            ]);
        });

        return back()->with('status', 'Diligência atualizada com sucesso.');
    }
}
