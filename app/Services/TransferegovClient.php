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

    /** @return array<string, mixed> */
    private function plansPage(int $beneficiaryId, int $page): array
    {
        return $this->request()->get('/planos_acao_especiais', [
            'id_beneficiario' => $beneficiaryId,
            'pagina' => $page,
            'tamanho_da_pagina' => 200,
        ])->throw()->json();
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
