<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TransferegovClient
{
    /** @return array<string, mixed>|null */
    public function beneficiaryByCnpj(string $cnpj): ?array
    {
        $response = $this->request()->get('/beneficiarios_especiais', [
            'cnpj_beneficiario' => preg_replace('/\D/', '', $cnpj),
            'pagina' => 1,
            'tamanho_da_pagina' => 10,
        ])->throw()->json();

        return $response['data'][0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function plansForBeneficiary(int $beneficiaryId): array
    {
        $firstPage = $this->plansPage($beneficiaryId, 1);
        $pages = (int) ($firstPage['total_pages'] ?? 1);

        if ($pages > 50) {
            throw new RuntimeException('A fonte retornou um volume acima do limite seguro para sincronização manual.');
        }

        $items = $firstPage['data'] ?? [];
        for ($page = 2; $page <= $pages; $page++) {
            $items = [...$items, ...($this->plansPage($beneficiaryId, $page)['data'] ?? [])];
        }

        return $items;
    }

    public function sourceUpdatedAt(): ?Carbon
    {
        $value = $this->request()->get('/data-atualizacao')->throw()->json('data_ultima_atualizacao');

        return filled($value) ? Carbon::parse($value) : null;
    }

    /**
     * @return array{commitments: array<int, array<string, mixed>>, documents: array<int, array<string, mixed>>, payment_orders: array<int, array<string, mixed>>, account_balances: array<int, array<string, mixed>>}
     */
    public function financialDataForPlan(int $planId, ?string $accountId): array
    {
        $commitments = $this->fetchAll('/empenhos_especiais', ['id_plano_acao' => $planId]);
        $documents = [];
        $paymentOrders = [];

        foreach ($commitments as $commitment) {
            $commitmentDocuments = $this->fetchAll('/documentos_habeis_especiais', [
                'id_empenho' => $commitment['id_empenho'],
            ]);
            $documents = [...$documents, ...$commitmentDocuments];

            foreach ($commitmentDocuments as $document) {
                $paymentOrders = [
                    ...$paymentOrders,
                    ...$this->fetchAll('/ordens_pagamentos_ordens_bancarias_especiais', [
                        'id_dh' => $document['id_dh'],
                    ]),
                ];
            }
        }

        return [
            'commitments' => $commitments,
            'documents' => $documents,
            'payment_orders' => $paymentOrders,
            'account_balances' => filled($accountId)
                ? $this->fetchAll('/saldo_conta_gestao_financeira_especiais', ['id_agencia_conta' => $accountId])
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function plansPage(int $beneficiaryId, int $page): array
    {
        return $this->request()->get('/planos_acao_especiais', [
            'id_beneficiario' => $beneficiaryId,
            'pagina' => $page,
            'tamanho_da_pagina' => 200,
        ])->throw()->json();
    }

    /** @param array<string, mixed> $filters @return array<int, array<string, mixed>> */
    private function fetchAll(string $endpoint, array $filters): array
    {
        $firstPage = $this->request()->get($endpoint, [
            ...$filters,
            'pagina' => 1,
            'tamanho_da_pagina' => 200,
        ])->throw()->json();
        $pages = (int) ($firstPage['total_pages'] ?? 1);

        if ($pages > 10) {
            throw new RuntimeException('A fonte retornou um volume financeiro acima do limite seguro para consulta manual.');
        }

        $items = $firstPage['data'] ?? [];
        for ($page = 2; $page <= $pages; $page++) {
            $response = $this->request()->get($endpoint, [
                ...$filters,
                'pagina' => $page,
                'tamanho_da_pagina' => 200,
            ])->throw()->json();
            $items = [...$items, ...($response['data'] ?? [])];
        }

        return array_values($items);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('services.transferegov.base_url'))
            ->acceptJson()
            ->timeout((int) config('services.transferegov.timeout', 20))
            ->retry([250, 750], throw: false)
            ->withHeaders(['User-Agent' => 'TrilhaGov/1.0']);
    }
}
