<?php

namespace App\Services;

use App\Models\ExternalAmendmentCandidate;
use App\Models\ExternalDataSync;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExternalAmendmentReconciliationService
{
    public function __construct(private readonly TransferegovClient $client) {}

    public function sync(Municipality $municipality, User $user): ExternalDataSync
    {
        $sync = $municipality->externalDataSyncs()->create([
            'initiated_by' => $user->id,
            'source' => ExternalDataSync::SOURCE_TRANSFEREGOV_SPECIAL,
            'status' => ExternalDataSync::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $sourceUpdatedAt = $this->client->sourceUpdatedAt();
            $beneficiary = $this->client->beneficiaryByCnpj($municipality->cnpj);
            $plans = $beneficiary === null
                ? []
                : $this->client->plansForBeneficiary((int) $beneficiary['id_beneficiario']);
            $created = 0;
            $updated = 0;
            $divergences = 0;

            foreach ($plans as $payload) {
                $result = $this->storeCandidate($municipality, $sync, $payload);
                $created += $result['created'] ? 1 : 0;
                $updated += $result['created'] ? 0 : 1;
                $divergences += $result['divergent'] ? 1 : 0;
            }

            $sync->update([
                'status' => ExternalDataSync::STATUS_SUCCESS,
                'source_updated_at' => $sourceUpdatedAt,
                'items_fetched' => count($plans),
                'items_created' => $created,
                'items_updated' => $updated,
                'divergences_found' => $divergences,
                'metadata' => [
                    'beneficiary_found' => $beneficiary !== null,
                    'beneficiary_id' => $beneficiary['id_beneficiario'] ?? null,
                    'beneficiary_name' => $beneficiary['nome_beneficiario'] ?? null,
                    'cnpj_consulted' => $municipality->cnpj,
                ],
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Falha na sincronização do Transferegov.', [
                'municipality_id' => $municipality->id,
                'sync_id' => $sync->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $sync->update([
                'status' => ExternalDataSync::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
        }

        return $sync->fresh();
    }

    /** @return array<string, array{label: string, local: mixed, external: mixed}> */
    public function differences(ParliamentaryAmendment $amendment, ExternalAmendmentCandidate $candidate): array
    {
        $differences = [];
        $this->compare($differences, 'fiscal_year', 'Exercício', $amendment->fiscal_year, $candidate->fiscal_year);
        $this->compare($differences, 'author_name', 'Autor', $amendment->author_name, $candidate->author_name, true);
        $this->compare($differences, 'object', 'Objeto', $amendment->object, $candidate->object, true);
        $this->compare($differences, 'expected_amount', 'Valor previsto', (float) $amendment->expected_amount, $candidate->expected_amount !== null ? (float) $candidate->expected_amount : null);
        $this->compare($differences, 'transferegov_code', 'Código do plano', $amendment->transferegov_code, $candidate->external_code, true);

        return $differences;
    }

    public function refreshMatch(ExternalAmendmentCandidate $candidate): ExternalAmendmentCandidate
    {
        $amendment = $candidate->amendment;
        if ($amendment === null) {
            $candidate->update(['match_status' => ExternalAmendmentCandidate::STATUS_NEW, 'differences' => null]);

            return $candidate->fresh();
        }

        $differences = $this->differences($amendment, $candidate);
        $candidate->update([
            'differences' => $differences,
            'match_status' => $differences === []
                ? ExternalAmendmentCandidate::STATUS_MATCHED
                : ExternalAmendmentCandidate::STATUS_DIVERGENT,
        ]);

        return $candidate->fresh();
    }

    /** @param array<string, array{label: string, local: mixed, external: mixed}> $differences */
    private function compare(array &$differences, string $field, string $label, mixed $local, mixed $external, bool $normalize = false): void
    {
        if ($external === null || $external === '') {
            return;
        }

        $left = $normalize ? $this->normalize((string) $local) : $local;
        $right = $normalize ? $this->normalize((string) $external) : $external;
        $same = is_float($left) || is_float($right)
            ? abs((float) $left - (float) $right) < .01
            : $left == $right;

        if (! $same) {
            $differences[$field] = ['label' => $label, 'local' => $local, 'external' => $external];
        }
    }

    /** @param array<string, mixed> $payload @return array{created: bool, divergent: bool} */
    private function storeCandidate(Municipality $municipality, ExternalDataSync $sync, array $payload): array
    {
        $externalId = (string) $payload['id_plano_acao'];
        $candidate = $municipality->externalAmendmentCandidates()
            ->where('source', ExternalDataSync::SOURCE_TRANSFEREGOV_SPECIAL)
            ->where('external_id', $externalId)
            ->first();
        $created = $candidate === null;
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $amendment = $candidate?->amendment ?? $this->findAmendment($municipality, $payload);
        $mapped = $this->mapPayload($payload);
        $previousHash = $candidate?->source_hash;

        $candidate ??= new ExternalAmendmentCandidate([
            'municipality_id' => $municipality->id,
            'source' => ExternalDataSync::SOURCE_TRANSFEREGOV_SPECIAL,
            'external_id' => $externalId,
        ]);
        $candidate->fill([
            ...$mapped,
            'external_data_sync_id' => $sync->id,
            'parliamentary_amendment_id' => $amendment?->id,
            'payload' => $payload,
            'source_hash' => $hash,
            'last_seen_at' => now(),
        ]);

        if ($amendment !== null) {
            $differences = $this->differences($amendment, $candidate);
            $candidate->differences = $differences;
            $candidate->match_status = $differences === []
                ? ExternalAmendmentCandidate::STATUS_MATCHED
                : ExternalAmendmentCandidate::STATUS_DIVERGENT;
        } elseif ($candidate->match_status !== ExternalAmendmentCandidate::STATUS_IGNORED || $previousHash !== $hash) {
            $candidate->match_status = ExternalAmendmentCandidate::STATUS_NEW;
            $candidate->differences = null;
        }

        $candidate->save();

        return ['created' => $created, 'divergent' => $candidate->match_status === ExternalAmendmentCandidate::STATUS_DIVERGENT];
    }

    /** @param array<string, mixed> $payload */
    private function findAmendment(Municipality $municipality, array $payload): ?ParliamentaryAmendment
    {
        $planCode = (string) ($payload['codigo_plano_acao'] ?? '');
        $amendmentCode = (string) ($payload['numero_emenda_parlamentar_plano_acao'] ?? '');

        return $municipality->amendments()
            ->where(function ($query) use ($planCode, $amendmentCode): void {
                $query->when($planCode !== '', fn ($query) => $query->orWhere('transferegov_code', $planCode));
                $query->when($amendmentCode !== '', fn ($query) => $query
                    ->orWhere('transferegov_code', $amendmentCode)
                    ->orWhere('reference', $amendmentCode));
            })
            ->first();
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function mapPayload(array $payload): array
    {
        $expected = (float) ($payload['valor_custeio_plano_acao'] ?? 0)
            + (float) ($payload['valor_investimento_plano_acao'] ?? 0);

        return [
            'external_code' => $payload['codigo_plano_acao'] ?? null,
            'amendment_code' => isset($payload['numero_emenda_parlamentar_plano_acao'])
                ? (string) $payload['numero_emenda_parlamentar_plano_acao']
                : null,
            'fiscal_year' => $payload['ano_emenda_parlamentar_plano_acao'] ?? $payload['ano_plano_acao'] ?? null,
            'author_name' => $payload['nome_parlamentar_emenda_plano_acao'] ?? null,
            'object' => $payload['detalhamento_objeto'] ?? $payload['nome_objeto'] ?? null,
            'expected_amount' => $expected > 0 ? $expected : null,
            'external_status' => $payload['situacao_plano_acao'] ?? null,
            'accepted_at' => $payload['data_aceite_plano_acao'] ?? null,
            'bank_status' => $payload['descricao_situacao_dado_bancario_plano_acao'] ?? null,
        ];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }
}
