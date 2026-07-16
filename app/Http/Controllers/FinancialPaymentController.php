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

class FinancialPaymentController extends Controller
{
    public function store(Request $request, int $emenda, int $empenho, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail, IntegrityAlertService $integrityAlertService): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $commitment = $amendment->financialCommitments()->findOrFail($empenho);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'payment_reference' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'paid_at' => ['required', 'date', 'after_or_equal:'.$commitment->committed_at->toDateString()],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'paid_at.after_or_equal' => 'A data do pagamento não pode ser anterior à data do empenho.',
        ]);

        if (! $formSubmission->consume($request, "financial-payment-create-{$commitment->id}")) {
            return back()->with('warning', 'Este pagamento já foi processado.');
        }

        try {
            $payment = DB::transaction(function () use ($request, $validated, $municipality, $amendment, $commitment, $auditTrail) {
                $locked = FinancialCommitment::query()->lockForUpdate()->findOrFail($commitment->id);

                if ($locked->status !== FinancialCommitment::STATUS_ACTIVE) {
                    throw ValidationException::withMessages(['amount' => 'Não é possível pagar um empenho cancelado.']);
                }

                $remaining = (float) $locked->committed_amount - (float) $locked->payments()->sum('amount');

                if ((float) $validated['amount'] > $remaining + 0.00001) {
                    throw ValidationException::withMessages([
                        'amount' => 'O pagamento ultrapassa o saldo disponível do empenho, que é R$ '.number_format(max(0, $remaining), 2, ',', '.').'.',
                    ]);
                }

                $payment = $locked->payments()->create([
                    ...$validated,
                    'municipality_id' => $municipality->id,
                    'parliamentary_amendment_id' => $amendment->id,
                    'created_by' => $request->user()->id,
                    'payment_reference' => trim($validated['payment_reference']),
                    'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
                ]);
                $auditTrail->recordOperation($request, $amendment, 'financial_payment_created', [
                    'commitment_number' => $locked->commitment_number,
                    'payment_reference' => $payment->payment_reference,
                    'payment_amount' => $payment->amount,
                ]);

                return $payment;
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'payment_reference' => 'Já existe um pagamento com esta referência neste empenho.',
            ]);
        }

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', "Pagamento {$payment->payment_reference} registrado com sucesso.");
    }
}
