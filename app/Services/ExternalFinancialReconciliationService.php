<?php

namespace App\Services;

use App\Models\ExternalAmendmentCandidate;
use App\Models\ExternalDataSync;
use App\Models\ExternalFinancialReconciliation;
use App\Models\FinancialCommitment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

class ExternalFinancialReconciliationService
{
    public function __construct(private readonly TransferegovClient $client) {}

    public function reconcile(ExternalAmendmentCandidate $candidate, User $user): ExternalFinancialReconciliation
    {
        $candidate->load('amendment');

        try {
            $official = $this->client->financialDataForPlan(
                (int) $candidate->external_id,
                $candidate->payload['id_agencia_conta'] ?? null,
            );
            $sourceUpdatedAt = $this->client->sourceUpdatedAt();

            return $this->storeSuccessfulReconciliation($candidate, $user, $official, $sourceUpdatedAt);
        } catch (Throwable $exception) {
            report($exception);

            return $candidate->financialReconciliations()->create([
                'municipality_id' => $candidate->municipality_id,
                'parliamentary_amendment_id' => $candidate->parliamentary_amendment_id,
                'initiated_by' => $user->id,
                'source' => ExternalDataSync::SOURCE_TRANSFEREGOV_SPECIAL,
                'status' => ExternalFinancialReconciliation::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'reconciled_at' => now(),
            ]);
        }
    }

    /**
     * @param  array{commitments: array<int, array<string, mixed>>, documents: array<int, array<string, mixed>>, payment_orders: array<int, array<string, mixed>>, account_balances: array<int, array<string, mixed>>}  $official
     */
    private function storeSuccessfulReconciliation(
        ExternalAmendmentCandidate $candidate,
        User $user,
        array $official,
        ?Carbon $sourceUpdatedAt,
    ): ExternalFinancialReconciliation {
        $amendment = $candidate->amendment;
        $commitments = collect($official['commitments']);
        $documents = collect($official['documents']);
        $paymentOrders = collect($official['payment_orders']);
        $accountData = collect($official['account_balances'])
            ->sortByDesc('data_saldo_conta')
            ->first();

        $officialCommitted = (float) $commitments->sum(fn (array $item) => (float) ($item['valor_empenho'] ?? 0));
        $orderedDocumentIds = $paymentOrders
            ->filter(fn (array $item) => filled($item['numero_ordem_bancaria'] ?? null))
            ->pluck('id_dh')
            ->filter()
            ->unique();
        $officialOrdered = (float) $documents
            ->whereIn('id_dh', $orderedDocumentIds)
            ->unique('id_dh')
            ->sum(fn (array $item) => (float) ($item['valor_dh'] ?? 0));
        $officialBalance = isset($accountData['saldo_final_gestao_financeira'])
            ? (float) $accountData['saldo_final_gestao_financeira']
            : null;

        $localExpected = $amendment ? (float) $amendment->expected_amount : null;
        $localReceived = $amendment && $amendment->received_amount !== null
            ? (float) $amendment->received_amount
            : null;
        $localCommitted = $amendment
            ? (float) $amendment->financialCommitments()->where('status', FinancialCommitment::STATUS_ACTIVE)->sum('committed_amount')
            : null;
        $localPaid = $amendment ? (float) $amendment->financialPayments()->sum('amount') : null;
        $localEstimatedBalance = $localReceived !== null ? $localReceived - (float) $localPaid : null;

        $differences = $this->differences(
            $officialCommitted,
            $officialOrdered,
            $officialBalance,
            $localExpected,
            $localReceived,
            $localEstimatedBalance,
        );
        $status = $this->status($candidate, $differences);

        return $candidate->financialReconciliations()->create([
            'municipality_id' => $candidate->municipality_id,
            'parliamentary_amendment_id' => $candidate->parliamentary_amendment_id,
            'initiated_by' => $user->id,
            'source' => ExternalDataSync::SOURCE_TRANSFEREGOV_SPECIAL,
            'status' => $status,
            'official_committed_amount' => $officialCommitted,
            'official_ordered_amount' => $officialOrdered,
            'official_account_balance' => $officialBalance,
            'local_expected_amount' => $localExpected,
            'local_received_amount' => $localReceived,
            'local_committed_amount' => $localCommitted,
            'local_paid_amount' => $localPaid,
            'local_estimated_balance' => $localEstimatedBalance,
            'differences' => $differences,
            'official_commitments' => $commitments->values()->all(),
            'official_payment_orders' => $this->enrichPaymentOrders($paymentOrders, $documents),
            'official_account_data' => $accountData,
            'source_updated_at' => $sourceUpdatedAt,
            'reconciled_at' => now(),
        ]);
    }

    /** @return array<string, array<string, float|string|null>> */
    private function differences(
        float $officialCommitted,
        float $officialOrdered,
        ?float $officialBalance,
        ?float $localExpected,
        ?float $localReceived,
        ?float $localEstimatedBalance,
    ): array {
        return [
            'transfer_commitment' => $this->comparison(
                'Empenhos federais x valor previsto',
                $officialCommitted,
                $localExpected,
            ),
            'transfer_received' => $this->comparison(
                'Ordens bancárias x valor recebido',
                $officialOrdered,
                $localReceived,
            ),
            'account_balance' => $this->comparison(
                'Saldo bancário x saldo local estimado',
                $officialBalance,
                $localEstimatedBalance,
            ),
        ];
    }

    /** @return array<string, float|string|null> */
    private function comparison(string $label, ?float $official, ?float $local): array
    {
        $difference = $official !== null && $local !== null ? round($official - $local, 2) : null;

        return [
            'label' => $label,
            'official' => $official,
            'local' => $local,
            'difference' => $difference,
            'state' => $difference === null ? 'pending' : (abs($difference) <= 0.01 ? 'consistent' : 'divergent'),
        ];
    }

    /** @param array<string, array<string, float|string|null>> $differences */
    private function status(ExternalAmendmentCandidate $candidate, array $differences): string
    {
        if ($candidate->parliamentary_amendment_id === null) {
            return ExternalFinancialReconciliation::STATUS_UNLINKED;
        }

        if (collect($differences)->contains(fn (array $item) => $item['state'] === 'pending')) {
            return ExternalFinancialReconciliation::STATUS_INCOMPLETE;
        }

        return collect($differences)->contains(fn (array $item) => $item['state'] === 'divergent')
            ? ExternalFinancialReconciliation::STATUS_DIVERGENT
            : ExternalFinancialReconciliation::STATUS_CONSISTENT;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $orders
     * @param  Collection<int, array<string, mixed>>  $documents
     * @return array<int, array<string, mixed>>
     */
    private function enrichPaymentOrders(Collection $orders, Collection $documents): array
    {
        $documentsById = $documents->keyBy('id_dh');

        return $orders->map(function (array $order) use ($documentsById): array {
            $document = $documentsById->get($order['id_dh'] ?? null, []);

            return [
                ...$order,
                'numero_documento_habil' => $document['numero_documento_habil'] ?? null,
                'valor_dh' => $document['valor_dh'] ?? null,
            ];
        })->values()->all();
    }
}
