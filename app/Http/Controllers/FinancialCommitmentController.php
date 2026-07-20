<?php

namespace App\Http\Controllers;

use App\Models\FinancialCommitment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialCommitmentController extends Controller
{
    public function store(Request $request, int $emenda, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail, IntegrityAlertService $integrityAlertService): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'execution_stage_id' => ['nullable', 'integer', Rule::exists('execution_stages', 'id')->where(fn ($query) => $query
                ->where('municipality_id', $municipality->id)
                ->where('parliamentary_amendment_id', $amendment->id))],
            'commitment_number' => ['required', 'string', 'max:80'],
            'supplier_name' => ['required', 'string', 'max:180'],
            'supplier_document' => ['nullable', 'string', 'max:20'],
            'procurement_process' => ['required', 'string', 'max:100'],
            'object_description' => ['required', 'string', 'max:2000'],
            'committed_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'committed_at' => ['required', 'date'],
        ], [
            'execution_stage_id.exists' => 'A etapa selecionada não pertence a esta emenda.',
        ]);

        if (! $formSubmission->consume($request, "financial-commitment-create-{$amendment->id}")) {
            return back()->with('warning', 'Este empenho já foi processado.');
        }

        try {
            $commitment = DB::transaction(function () use ($request, $validated, $municipality, $amendment, $auditTrail) {
                $commitment = $amendment->financialCommitments()->create([
                    ...$validated,
                    'municipality_id' => $municipality->id,
                    'created_by' => $request->user()->id,
                    'commitment_number' => trim($validated['commitment_number']),
                    'supplier_name' => trim($validated['supplier_name']),
                    'supplier_document' => filled($validated['supplier_document'] ?? null)
                        ? preg_replace('/\D/', '', $validated['supplier_document'])
                        : null,
                    'procurement_process' => trim($validated['procurement_process']),
                    'status' => FinancialCommitment::STATUS_ACTIVE,
                ]);
                $auditTrail->recordOperation($request, $amendment, 'financial_commitment_created', [
                    'commitment_number' => $commitment->commitment_number,
                    'supplier_name' => $commitment->supplier_name,
                    'committed_amount' => $commitment->committed_amount,
                ]);

                return $commitment;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'commitment_number' => 'Já existe um empenho com este número nesta emenda.',
            ]);
        }

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', "Empenho {$commitment->commitment_number} registrado com sucesso.");
    }

    public function cancel(Request $request, int $emenda, int $empenho, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail, IntegrityAlertService $integrityAlertService): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $commitment = $amendment->financialCommitments()->findOrFail($empenho);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'cancellation_reason' => ['required', 'string', 'max:1000'],
        ]);

        if (! $formSubmission->consume($request, "financial-commitment-cancel-{$commitment->id}")) {
            return back()->with('warning', 'Este cancelamento já foi processado.');
        }

        DB::transaction(function () use ($request, $validated, $amendment, $commitment, $auditTrail): void {
            $locked = FinancialCommitment::query()->lockForUpdate()->findOrFail($commitment->id);

            if ($locked->status === FinancialCommitment::STATUS_CANCELLED) {
                return;
            }

            if ($locked->payments()->exists()) {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'Não é possível cancelar um empenho que já possui pagamento. Registre a correção no sistema contábil e mantenha a evidência aqui.',
                ]);
            }

            if ($locked->liquidations()->exists()) {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'Não é possível cancelar um empenho que já possui liquidação. Registre a anulação no sistema contábil e preserve a evidência no TrilhaGov.',
                ]);
            }

            $locked->update([
                'status' => FinancialCommitment::STATUS_CANCELLED,
                'cancellation_reason' => trim($validated['cancellation_reason']),
                'cancelled_at' => now(),
            ]);
            $auditTrail->recordOperation($request, $amendment, 'financial_commitment_cancelled', [
                'commitment_number' => $locked->commitment_number,
                'cancellation_reason' => $locked->cancellation_reason,
            ]);
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Empenho cancelado e preservado no histórico.');
    }
}
