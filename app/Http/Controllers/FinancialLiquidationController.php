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
use Illuminate\Validation\ValidationException;

class FinancialLiquidationController extends Controller
{
    public function store(
        Request $request,
        int $emenda,
        int $empenho,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
        IntegrityAlertService $integrityAlertService,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->with('municipality')->findOrFail($emenda);
        abort_unless($amendment->supportsTcespCompliance(), 404);
        $commitment = $amendment->financialCommitments()->findOrFail($empenho);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'liquidation_reference' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'liquidated_at' => ['required', 'date', 'after_or_equal:'.$commitment->committed_at->toDateString()],
            'supporting_document' => ['required', 'string', 'max:140'],
            'acceptance_reference' => ['required', 'string', 'max:140'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'liquidated_at.after_or_equal' => 'A liquidação não pode ser anterior ao empenho.',
            'supporting_document.required' => 'Informe a nota fiscal, medição ou documento que sustenta a liquidação.',
            'acceptance_reference.required' => 'Informe o termo, ateste ou responsável pelo recebimento do objeto.',
        ]);

        if (! $formSubmission->consume($request, "financial-liquidation-create-{$commitment->id}")) {
            return back()->with('warning', 'Esta liquidação já foi processada.');
        }

        try {
            $liquidation = DB::transaction(function () use ($request, $validated, $municipality, $amendment, $commitment, $auditTrail) {
                $locked = FinancialCommitment::query()->lockForUpdate()->findOrFail($commitment->id);
                if ($locked->status !== FinancialCommitment::STATUS_ACTIVE) {
                    throw ValidationException::withMessages(['amount' => 'Não é possível liquidar um empenho cancelado.']);
                }

                $remaining = (float) $locked->committed_amount - (float) $locked->liquidations()->sum('amount');
                if ((float) $validated['amount'] > $remaining + 0.00001) {
                    throw ValidationException::withMessages([
                        'amount' => 'A liquidação ultrapassa o saldo a liquidar do empenho, que é R$ '.number_format(max(0, $remaining), 2, ',', '.').'.',
                    ]);
                }

                $liquidation = $locked->liquidations()->create([
                    ...$validated,
                    'municipality_id' => $municipality->id,
                    'parliamentary_amendment_id' => $amendment->id,
                    'created_by' => $request->user()->id,
                    'liquidation_reference' => trim($validated['liquidation_reference']),
                    'supporting_document' => trim($validated['supporting_document']),
                    'acceptance_reference' => trim($validated['acceptance_reference']),
                    'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
                ]);
                $auditTrail->recordOperation($request, $amendment, 'financial_liquidation_created', [
                    'commitment_number' => $locked->commitment_number,
                    'liquidation_reference' => $liquidation->liquidation_reference,
                    'liquidation_amount' => $liquidation->amount,
                    'supporting_document' => $liquidation->supporting_document,
                ]);

                return $liquidation;
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'liquidation_reference' => 'Já existe uma liquidação com esta referência neste empenho.',
            ]);
        }

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', "Liquidação {$liquidation->liquidation_reference} registrada e preservada no histórico.");
    }
}
