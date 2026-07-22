<?php

namespace App\Services;

use App\Models\ContractAddendum;
use App\Models\ContractMeasurement;
use App\Models\MunicipalContract;
use Illuminate\Support\Carbon;

class MunicipalContractFramework
{
    public const VERSION = 'lei-14133-rev-2026-01';

    /** @return array<string, string> */
    public function objectTypes(): array
    {
        return [
            'public_work' => 'Obra pública',
            'engineering_service' => 'Serviço de engenharia',
            'renovation' => 'Reforma de edifício ou equipamento',
            'acquisition' => 'Aquisição vinculada à emenda',
            'service' => 'Serviço vinculado à emenda',
        ];
    }

    /** @return array<string, string> */
    public function procurementMethods(): array
    {
        return [
            'competition' => 'Concorrência',
            'auction' => 'Pregão',
            'contest' => 'Concurso',
            'bidding' => 'Leilão',
            'competitive_dialogue' => 'Diálogo competitivo',
            'waiver' => 'Contratação direta por dispensa',
            'inexigibility' => 'Contratação direta por inexigibilidade',
            'adhesion' => 'Adesão a ata de registro de preços',
        ];
    }

    /** @return array<string, string> */
    public function executionRegimes(): array
    {
        return [
            'unit_price' => 'Empreitada por preço unitário',
            'global_price' => 'Empreitada por preço global',
            'full' => 'Empreitada integral',
            'task' => 'Contratação por tarefa',
            'integrated' => 'Contratação integrada',
            'semi_integrated' => 'Contratação semi-integrada',
            'supply_service' => 'Fornecimento e prestação de serviço associado',
        ];
    }

    /** @return array<string, string> */
    public function publicationTypes(): array
    {
        return [
            'pncp' => 'Portal Nacional de Contratações Públicas',
            'small_municipality_transition' => 'Regra transitória do art. 176',
        ];
    }

    /** @return array<string, string> */
    public function addendumTypes(): array
    {
        return [
            'increase' => 'Acréscimo quantitativo',
            'decrease' => 'Supressão quantitativa',
            'extension' => 'Prorrogação de prazo',
            'rebalance' => 'Reequilíbrio econômico-financeiro',
            'project_change' => 'Alteração de projeto ou especificação',
        ];
    }

    /** @return array<string, string> */
    public function planningChecklist(): array
    {
        return [
            'demand_formalized' => 'Demanda e interesse público formalizados',
            'technical_study' => 'Estudo Técnico Preliminar ou justificativa de dispensa',
            'risk_analysis' => 'Análise de riscos da contratação',
            'basic_project' => 'Termo de referência, anteprojeto ou projeto básico',
            'budget_spreadsheet' => 'Orçamento detalhado e preços referenciais',
            'physical_financial_schedule' => 'Cronograma físico-financeiro',
            'licenses_and_land' => 'Licenças, terreno e interferências verificados',
            'budget_allocation' => 'Dotação e origem dos recursos identificadas',
            'legal_review' => 'Controle prévio de legalidade realizado',
        ];
    }

    /** @return array{ready: bool, blockers: array<int, string>, warnings: array<int, string>, financial_percentage: float, physical_percentage: float, variance: float} */
    public function evaluate(MunicipalContract $contract): array
    {
        $contract->loadMissing(['measurements', 'addenda']);
        $blockers = [];
        $warnings = [];
        $checklist = $contract->planning_checklist ?? [];

        foreach ($this->planningChecklist() as $key => $label) {
            if ($key === 'licenses_and_land' && ! in_array($contract->object_type, ['public_work', 'engineering_service', 'renovation'], true)) {
                continue;
            }
            if (! ($checklist[$key] ?? false)) {
                $blockers[] = $label.' está pendente.';
            }
        }

        if (in_array($contract->status, [MunicipalContract::STATUS_CONTRACTED, MunicipalContract::STATUS_EXECUTING, MunicipalContract::STATUS_SUSPENDED, MunicipalContract::STATUS_COMPLETED], true)) {
            foreach ([
                'contract_number' => 'Número do contrato',
                'supplier_name' => 'Contratada',
                'supplier_document' => 'CNPJ ou CPF da contratada',
                'original_amount' => 'Valor original',
                'signed_at' => 'Data de assinatura',
                'effective_start_at' => 'Início da vigência',
                'effective_end_at' => 'Fim da vigência',
                'contract_manager_id' => 'Gestor do contrato',
                'contract_inspector_id' => 'Fiscal do contrato',
                'measurement_criteria' => 'Critérios de medição',
                'payment_terms' => 'Condições de pagamento',
                'publication_type' => 'Canal de publicidade',
                'publication_reference' => 'Referência da publicação',
                'published_at' => 'Data da publicação',
            ] as $field => $label) {
                if (blank($contract->{$field})) {
                    $blockers[] = $label.' deve ser informado.';
                }
            }
        }

        if (in_array($contract->object_type, ['public_work', 'engineering_service', 'renovation'], true)) {
            if (blank($contract->technical_responsible) || blank($contract->technical_registration)) {
                $blockers[] = 'Responsável técnico e ART/RRT devem ser identificados.';
            }
            if (blank($contract->site_location)) {
                $warnings[] = 'Informe o local da obra para facilitar inspeção e transparência.';
            }
        }

        if ($contract->contract_manager_id !== null && $contract->contract_manager_id === $contract->contract_inspector_id) {
            $warnings[] = 'Gestor e fiscal estão concentrados na mesma pessoa; registre a justificativa institucional dessa organização.';
        }

        if ($contract->signed_at && $contract->published_at) {
            $days = in_array($contract->procurement_method, ['waiver', 'inexigibility'], true) ? 10 : 20;
            if ($contract->published_at->isAfter($contract->signed_at->copy()->addWeekdays($days))) {
                $warnings[] = "A publicação ocorreu depois do prazo indicativo de {$days} dias úteis do art. 94; confira o caso concreto.";
            }
        }

        $approved = $contract->measurements->where('status', ContractMeasurement::STATUS_APPROVED);
        $measured = (float) $approved->sum('amount');
        $currentAmount = (float) ($contract->current_amount ?? $contract->original_amount ?? 0);
        $financial = $currentAmount > 0 ? round(($measured / $currentAmount) * 100, 2) : 0.0;
        $physical = (float) ($approved->sortByDesc('sequence')->first()?->cumulative_physical_percentage ?? 0);
        $variance = round($financial - $physical, 2);
        if ($variance > 15) {
            $warnings[] = 'A execução medida financeiramente supera o avanço físico em '.$variance.' pontos percentuais.';
        }

        if ($contract->status === MunicipalContract::STATUS_EXECUTING && blank($contract->work_order_at)) {
            $blockers[] = 'Registre a ordem de serviço antes de iniciar a execução.';
        }
        if ($contract->status === MunicipalContract::STATUS_SUSPENDED && blank($contract->suspension_reason)) {
            $blockers[] = 'A paralisação exige motivo formalizado.';
        }
        if ($contract->status === MunicipalContract::STATUS_COMPLETED) {
            if ($physical < 100) {
                $blockers[] = 'O recebimento definitivo exige medição física acumulada de 100%.';
            }
            if (blank($contract->provisional_acceptance_reference) || blank($contract->provisional_accepted_at)) {
                $blockers[] = 'Registre o termo de recebimento provisório.';
            }
            if (blank($contract->definitive_acceptance_reference) || blank($contract->definitive_accepted_at)) {
                $blockers[] = 'Registre o termo de recebimento definitivo.';
            }
        }

        return [
            'ready' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'financial_percentage' => min(999.99, $financial),
            'physical_percentage' => $physical,
            'variance' => $variance,
        ];
    }

    /** @return array{ready: bool, blockers: array<int, string>, limit_percentage: float, projected_percentage: float, projected_increase: float} */
    public function evaluateAddendum(ContractAddendum $addendum): array
    {
        $contract = $addendum->contract()->with('addenda')->firstOrFail();
        $blockers = [];
        $limit = $contract->is_renovation || $contract->object_type === 'renovation' ? 50.0 : 25.0;
        $original = (float) ($contract->original_amount ?? 0);
        $previousIncreases = (float) $contract->addenda
            ->where('status', ContractAddendum::STATUS_APPROVED)
            ->whereIn('type', ['increase', 'project_change'])
            ->where('id', '!=', $addendum->id)
            ->sum('value_change');
        $projectedIncrease = $previousIncreases + (in_array($addendum->type, ['increase', 'project_change'], true) ? (float) $addendum->value_change : 0);
        $projectedPercentage = $original > 0 ? round(($projectedIncrease / $original) * 100, 2) : 0.0;

        if ($original <= 0) {
            $blockers[] = 'Informe o valor original do contrato antes de analisar o aditivo.';
        }
        if ($projectedPercentage > $limit + 0.001) {
            $blockers[] = "Os acréscimos acumulados alcançariam {$projectedPercentage}%, acima do limite de {$limit}% aplicado pelo art. 125.";
        }
        if (blank($addendum->signed_at) || blank($addendum->publication_reference) || blank($addendum->published_at)) {
            $blockers[] = 'Assinatura e publicidade do termo aditivo devem ser registradas antes da aprovação.';
        }
        if ($addendum->signed_at && $addendum->effective_at->isBefore($addendum->signed_at)) {
            if (blank($addendum->advance_effects_justification)) {
                $blockers[] = 'Efeito anterior à assinatura exige justificativa expressa.';
            } elseif ($addendum->effective_at->diffInDays($addendum->signed_at) > 31) {
                $blockers[] = 'A formalização ultrapassa um mês da antecipação excepcional dos efeitos.';
            }
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'limit_percentage' => $limit,
            'projected_percentage' => $projectedPercentage,
            'projected_increase' => $projectedIncrease,
        ];
    }

    public function recalculateCurrentAmount(MunicipalContract $contract): float
    {
        $contract->load('addenda');
        $amount = (float) ($contract->original_amount ?? 0);
        foreach ($contract->addenda->where('status', ContractAddendum::STATUS_APPROVED) as $addendum) {
            $change = (float) $addendum->value_change;
            $amount += $addendum->type === 'decrease' ? -$change : ($addendum->type === 'extension' ? 0 : $change);
        }
        $amount = max(0, round($amount, 2));
        $contract->forceFill(['current_amount' => $amount])->saveQuietly();

        return $amount;
    }

    /** @return array<string, mixed> */
    public function measurementSnapshot(ContractMeasurement $measurement): array
    {
        $contract = $measurement->contract;

        return [
            'methodology' => self::VERSION,
            'municipality_id' => $measurement->municipality_id,
            'contract' => ['id' => $contract->id, 'code' => $contract->code(), 'number' => $contract->contract_number, 'current_amount' => (float) $contract->current_amount],
            'measurement' => [
                'id' => $measurement->id, 'sequence' => $measurement->sequence,
                'period_start_at' => $measurement->period_start_at->toDateString(),
                'period_end_at' => $measurement->period_end_at->toDateString(),
                'measured_at' => $measurement->measured_at->toDateString(),
                'amount' => (float) $measurement->amount,
                'cumulative_physical_percentage' => (float) $measurement->cumulative_physical_percentage,
                'notes' => $measurement->notes,
                'evidence_document_id' => $measurement->evidence_document_id,
            ],
            'review' => ['reviewed_by' => $measurement->reviewed_by, 'review_notes' => $measurement->review_notes, 'reviewed_at' => $measurement->reviewed_at?->toIso8601String()],
        ];
    }

    /** @return array<string, mixed> */
    public function addendumSnapshot(ContractAddendum $addendum): array
    {
        $diagnostic = $this->evaluateAddendum($addendum);

        return [
            'methodology' => self::VERSION,
            'municipality_id' => $addendum->municipality_id,
            'contract' => ['id' => $addendum->contract->id, 'code' => $addendum->contract->code(), 'original_amount' => (float) $addendum->contract->original_amount],
            'addendum' => [
                'id' => $addendum->id, 'sequence' => $addendum->sequence, 'type' => $addendum->type,
                'value_change' => (float) $addendum->value_change, 'days_change' => $addendum->days_change,
                'justification' => $addendum->justification, 'technical_basis' => $addendum->technical_basis,
                'effective_at' => $addendum->effective_at->toDateString(), 'signed_at' => $addendum->signed_at?->toDateString(),
                'publication_reference' => $addendum->publication_reference, 'published_at' => $addendum->published_at?->toDateString(),
            ],
            'limit' => $diagnostic,
            'review' => ['reviewed_by' => $addendum->reviewed_by, 'review_notes' => $addendum->review_notes, 'reviewed_at' => $addendum->reviewed_at?->toIso8601String()],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function suspensionDays(MunicipalContract $contract): int
    {
        if ($contract->status !== MunicipalContract::STATUS_SUSPENDED || ! $contract->suspended_at) {
            return 0;
        }

        return (int) Carbon::parse($contract->suspended_at)->diffInDays(today());
    }
}
