<?php

namespace App\Http\Controllers;

use App\Models\AmendmentRemapping;
use App\Models\TechnicalImpediment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AmendmentRemappingController extends Controller
{
    public function store(Request $request, int $emenda, int $impedimento, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $impediment = $amendment->technicalImpediments()->findOrFail($impedimento);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'proposed_object' => ['required', 'string', 'max:5000', 'different:original_object'],
            'justification' => ['required', 'string', 'max:5000'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
        ]);

        if (! $formSubmission->consume($request, "amendment-remapping-create-{$impediment->id}")) {
            return back()->with('warning', 'Esta proposta de remanejamento já foi registrada.');
        }

        if ($impediment->nature !== TechnicalImpediment::NATURE_INSURMOUNTABLE) {
            throw ValidationException::withMessages([
                'remapping' => 'O remanejamento somente pode ser proposto após classificar o impedimento como insuperável.',
            ]);
        }

        if ($impediment->remappings()->whereIn('status', ['draft', 'submitted', 'approved'])->exists()) {
            return back()->with('warning', 'Este impedimento já possui uma proposta de remanejamento ativa.');
        }

        DB::transaction(function () use ($request, $validated, $municipality, $amendment, $impediment, $auditTrail): void {
            $remapping = $impediment->remappings()->create([
                ...$validated,
                'municipality_id' => $municipality->id,
                'parliamentary_amendment_id' => $amendment->id,
                'requested_by' => $request->user()->id,
                'status' => AmendmentRemapping::STATUS_DRAFT,
                'original_object' => $amendment->object,
            ]);
            $auditTrail->recordOperation($request, $amendment, 'amendment_remapping_created', [
                'impediment' => $impediment->title,
                'remapping_status' => $remapping->status,
                'remapping_amount' => $remapping->amount,
                'proposed_object' => $remapping->proposed_object,
            ]);
        });

        return back()->with('status', 'Proposta de remanejamento criada como rascunho.');
    }

    public function submit(Request $request, int $emenda, int $impedimento, int $remanejamento, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $impediment = $amendment->technicalImpediments()->findOrFail($impedimento);
        $remapping = $impediment->remappings()->findOrFail($remanejamento);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "amendment-remapping-submit-{$remapping->id}")) {
            return back()->with('warning', 'Esta proposta já foi enviada.');
        }
        abort_unless($remapping->status === AmendmentRemapping::STATUS_DRAFT, 422);

        DB::transaction(function () use ($request, $amendment, $impediment, $remapping, $auditTrail): void {
            $remapping->update([
                'status' => AmendmentRemapping::STATUS_SUBMITTED,
                'requested_at' => today(),
            ]);
            $auditTrail->recordOperation($request, $amendment, 'amendment_remapping_submitted', [
                'impediment' => $impediment->title,
                'remapping_status' => $remapping->status,
                'remapping_amount' => $remapping->amount,
            ]);
        });

        return back()->with('status', 'Remanejamento enviado para decisão do gestor.');
    }

    public function update(Request $request, int $emenda, int $impedimento, int $remanejamento, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $impediment = $amendment->technicalImpediments()->findOrFail($impedimento);
        $remapping = $impediment->remappings()->findOrFail($remanejamento);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'proposed_object' => ['required', 'string', 'max:5000'],
            'justification' => ['required', 'string', 'max:5000'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
        ]);

        if (! $formSubmission->consume($request, "amendment-remapping-update-{$remapping->id}")) {
            return back()->with('warning', 'Esta atualização do remanejamento já foi processada.');
        }
        abort_unless($remapping->status === AmendmentRemapping::STATUS_DRAFT, 409);

        DB::transaction(function () use ($request, $validated, $amendment, $impediment, $remapping, $auditTrail): void {
            $oldValues = $remapping->only(['proposed_object', 'justification', 'amount']);
            $remapping->update($validated);
            $auditTrail->recordOperation($request, $amendment, 'amendment_remapping_updated', [
                'impediment' => $impediment->title,
                'remapping_amount' => $remapping->amount,
                'proposed_object' => $remapping->proposed_object,
            ], [
                'impediment' => $impediment->title,
                'remapping_amount' => $oldValues['amount'],
                'proposed_object' => $oldValues['proposed_object'],
            ]);
        });

        return back()->with('status', 'Rascunho do remanejamento atualizado.');
    }

    public function decide(Request $request, int $emenda, int $impedimento, int $remanejamento, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $impediment = $amendment->technicalImpediments()->findOrFail($impedimento);
        $remapping = $impediment->remappings()->findOrFail($remanejamento);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'status' => ['required', Rule::in([AmendmentRemapping::STATUS_APPROVED, AmendmentRemapping::STATUS_REJECTED])],
            'decision_notes' => ['required', 'string', 'max:5000'],
            'decision_reference' => ['required', 'string', 'max:160'],
        ]);

        if (! $formSubmission->consume($request, "amendment-remapping-decide-{$remapping->id}")) {
            return back()->with('warning', 'Esta decisão já foi processada.');
        }
        abort_unless($remapping->status === AmendmentRemapping::STATUS_SUBMITTED, 422);

        DB::transaction(function () use ($request, $validated, $amendment, $impediment, $remapping, $auditTrail): void {
            $remapping->update([
                ...$validated,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ]);
            if ($remapping->status === AmendmentRemapping::STATUS_APPROVED) {
                $impediment->update([
                    'status' => TechnicalImpediment::STATUS_REMAPPED,
                    'resolved_at' => now(),
                    'resolution_notes' => 'Remanejamento aprovado: '.$remapping->decision_reference,
                ]);
            }
            $auditTrail->recordOperation($request, $amendment, 'amendment_remapping_decided', [
                'impediment' => $impediment->title,
                'remapping_status' => $remapping->status,
                'remapping_amount' => $remapping->amount,
                'decision_reference' => $remapping->decision_reference,
            ]);
        });

        return back()->with('status', 'Decisão do remanejamento registrada.');
    }
}
