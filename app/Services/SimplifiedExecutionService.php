<?php

namespace App\Services;

use App\Models\ExecutionStage;
use App\Models\MunicipalWorkPlan;
use App\Models\ParliamentaryAmendment;
use Illuminate\Support\Collection;

class SimplifiedExecutionService
{
    /** @return array<string, mixed> */
    public function guide(ParliamentaryAmendment $amendment, float $receivedAmount, float $committedAmount, float $paidAmount, int $physicalPercentage): array
    {
        $stages = $amendment->executionStages;
        $documents = $amendment->documents->whereNotNull('execution_stage_id');
        $activeCommitments = $amendment->financialCommitments->where('status', 'active');
        $plan = $amendment->municipalWorkPlan;
        $hasApprovedPlan = $plan?->status === MunicipalWorkPlan::STATUS_APPROVED;
        $hasStages = $stages->isNotEmpty();
        $hasCommitments = $activeCommitments->isNotEmpty();
        $hasEvidence = $documents->isNotEmpty();
        $financialPercentage = $receivedAmount > 0 ? (int) round(($paidAmount / $receivedAmount) * 100) : 0;
        $hasFinancialClosure = $receivedAmount > 0 && $paidAmount > 0 && abs($paidAmount - $receivedAmount) <= 0.01;
        $readyForAccountability = $hasStages && $physicalPercentage >= 100 && $hasFinancialClosure && $hasEvidence;
        $doneCount = collect([$hasStages, $hasCommitments, $paidAmount > 0, $hasEvidence, $readyForAccountability])
            ->filter()
            ->count();
        $readinessPercentage = (int) round($doneCount / 5 * 100);
        $releaseChecks = $this->releaseChecks($amendment, $receivedAmount, $committedAmount, $paidAmount, $physicalPercentage, $hasStages, $hasEvidence, $hasFinancialClosure);
        $releaseBlockers = collect($releaseChecks)->where('done', false)->pluck('description')->values();

        $next = [
            'icon' => 'route',
            'title' => 'Iniciar execucao simplificada',
            'description' => $hasApprovedPlan
                ? 'Copie automaticamente as etapas aprovadas no Plano de Trabalho para acompanhar a entrega real.'
                : 'Cadastre uma primeira etapa executavel para iniciar o acompanhamento fisico e financeiro.',
            'href' => '#iniciar-execucao',
            'label' => $hasApprovedPlan ? 'Gerar etapas do plano' : 'Criar primeira etapa',
        ];

        if ($hasStages && ! $hasCommitments) {
            $next = [
                'icon' => 'briefcase-business',
                'title' => 'Registrar empenho',
                'description' => 'Vincule o primeiro empenho a uma etapa para conectar orcamento, fornecedor e entrega fisica.',
                'href' => '#commitments',
                'label' => 'Registrar empenho',
            ];
        } elseif ($hasCommitments && $paidAmount <= 0) {
            $next = [
                'icon' => 'receipt-text',
                'title' => 'Registrar liquidacao e pagamento',
                'description' => 'Depois do empenho, mantenha liquidacoes e pagamentos alinhados ao valor recebido.',
                'href' => $amendment->supportsTcespCompliance() ? route('emendas.audesp', $amendment).'#cadeia-contabil' : '#commitments',
                'label' => $amendment->supportsTcespCompliance() ? 'Abrir Audesp' : 'Registrar pagamento',
            ];
        } elseif ($paidAmount > 0 && ! $hasEvidence) {
            $next = [
                'icon' => 'file-check-2',
                'title' => 'Anexar evidencia de entrega',
                'description' => 'Comprove medicao, recebimento, fotos ou relatorio antes de fechar a execucao.',
                'href' => '#evidence',
                'label' => 'Anexar evidencia',
            ];
        } elseif ($readyForAccountability) {
            $next = [
                'icon' => 'archive',
                'title' => 'Preparar prestacao de contas',
                'description' => 'Execucao fisica, pagamentos e evidencias estao prontos para o fechamento formal.',
                'href' => route('emendas.accountability', $amendment),
                'label' => 'Abrir prestacao',
            ];
        }

        return [
            'next' => $next,
            'steps' => [
                ['label' => 'Etapas abertas', 'description' => 'Cronograma real criado para a entrega.', 'done' => $hasStages],
                ['label' => 'Empenho vinculado', 'description' => 'Fornecedor e processo conectados a execucao.', 'done' => $hasCommitments],
                ['label' => 'Pagamento controlado', 'description' => 'Pagamentos lancados sem ultrapassar o recebido.', 'done' => $receivedAmount > 0 && $paidAmount > 0 && $paidAmount <= $receivedAmount],
                ['label' => 'Evidencias anexadas', 'description' => 'Documentos de entrega ligados as etapas.', 'done' => $hasEvidence],
                ['label' => 'Pronta para contas', 'description' => 'Base minima preparada para prestacao.', 'done' => $readyForAccountability],
            ],
            'risks' => $this->risks($amendment, $receivedAmount, $committedAmount, $paidAmount, $physicalPercentage),
            'summary' => [
                ['label' => 'Plano', 'value' => $hasApprovedPlan ? 'Plano aprovado localizado' : 'Plano aprovado nao localizado'],
                ['label' => 'Etapas', 'value' => $stages->count().' etapa(s)'],
                ['label' => 'Evidencias', 'value' => $documents->count().' evidencia(s)'],
                ['label' => 'Financeiro', 'value' => $financialPercentage.'% financeiro'],
            ],
            'readiness' => [
                'percentage' => $readinessPercentage,
                'done' => $doneCount,
                'total' => 5,
                'label' => $readyForAccountability ? 'Pronta para prestacao' : ($doneCount >= 3 ? 'Execucao em andamento' : 'Execucao a estruturar'),
                'tone' => $readyForAccountability ? 'success' : ($doneCount >= 3 ? 'warning' : 'neutral'),
                'physical' => $physicalPercentage,
                'financial' => $financialPercentage,
                'evidence_count' => $documents->count(),
                'paid_amount' => $paidAmount,
                'committed_amount' => $committedAmount,
                'received_amount' => $receivedAmount,
            ],
            'release' => [
                'ready' => $readyForAccountability,
                'label' => $readyForAccountability ? 'Liberada para prestacao de contas' : 'Ainda nao liberada para prestacao',
                'description' => $readyForAccountability
                    ? 'A emenda tem entrega fisica, financeiro conciliado e evidencia minima para abrir o fechamento formal.'
                    : 'Resolva os pontos abaixo antes de iniciar ou enviar a prestacao de contas.',
                'checks' => $releaseChecks,
                'blockers' => $releaseBlockers,
                'next_href' => $readyForAccountability ? route('emendas.accountability', $amendment) : ($releaseChecks->firstWhere('done', false)['href'] ?? '#stages'),
                'next_label' => $readyForAccountability ? 'Abrir prestacao' : 'Resolver proxima pendencia',
            ],
            'flow' => [
                ['label' => 'Abrir etapas', 'icon' => 'clipboard-list', 'done' => $hasStages, 'href' => '#stages'],
                ['label' => 'Empenhar', 'icon' => 'briefcase-business', 'done' => $hasCommitments, 'href' => '#commitments'],
                ['label' => 'Liquidar e pagar', 'icon' => 'receipt-text', 'done' => $paidAmount > 0, 'href' => '#commitments'],
                ['label' => 'Comprovar entrega', 'icon' => 'file-check-2', 'done' => $hasEvidence, 'href' => '#evidence'],
                ['label' => 'Prestar contas', 'icon' => 'archive', 'done' => $readyForAccountability, 'href' => route('emendas.accountability', $amendment)],
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{label: string, description: string, done: bool, href: string}>
     */
    private function releaseChecks(
        ParliamentaryAmendment $amendment,
        float $receivedAmount,
        float $committedAmount,
        float $paidAmount,
        int $physicalPercentage,
        bool $hasStages,
        bool $hasEvidence,
        bool $hasFinancialClosure,
    ): Collection {
        return collect([
            [
                'label' => 'Etapa fisica aberta',
                'description' => 'Inicie a execucao com ao menos uma etapa verificavel.',
                'done' => $hasStages,
                'href' => '#stages',
            ],
            [
                'label' => 'Entrega concluida',
                'description' => 'Atualize a etapa para 100% quando o objeto estiver entregue.',
                'done' => $physicalPercentage >= 100,
                'href' => '#stages',
            ],
            [
                'label' => 'Recurso recebido informado',
                'description' => 'Informe o valor recebido na emenda para permitir conciliacao.',
                'done' => $receivedAmount > 0,
                'href' => route('emendas.edit', $amendment),
            ],
            [
                'label' => 'Empenho registrado',
                'description' => 'Registre ao menos um empenho vinculado a execucao.',
                'done' => $committedAmount > 0,
                'href' => '#commitments',
            ],
            [
                'label' => 'Financeiro conciliado',
                'description' => 'Pagamentos devem corresponder ao valor recebido antes da prestacao.',
                'done' => $hasFinancialClosure,
                'href' => '#commitments',
            ],
            [
                'label' => 'Evidencia anexada',
                'description' => 'Anexe medicao, foto, termo de recebimento ou relatorio ligado a etapa.',
                'done' => $hasEvidence,
                'href' => '#evidence',
            ],
        ]);
    }

    public function createStagesFromPlan(ParliamentaryAmendment $amendment, int $userId): int
    {
        if ($amendment->executionStages()->exists()) {
            return 0;
        }

        $plan = $amendment->municipalWorkPlan()->with('stages')->first();
        $sourceStages = $plan?->status === MunicipalWorkPlan::STATUS_APPROVED && $plan->stages->isNotEmpty()
            ? $plan->stages
            : collect([(object) [
                'title' => 'Execucao integral da emenda',
                'physical_delivery' => $amendment->object,
                'planned_amount' => $amendment->received_amount ?? $amendment->expected_amount,
                'planned_start_at' => $amendment->received_at ?? today(),
                'planned_end_at' => $amendment->execution_deadline,
                'sort_order' => 10,
            ]]);

        return $this->persistStages($amendment, $sourceStages, $userId);
    }

    /** @param Collection<int, mixed> $sourceStages */
    private function persistStages(ParliamentaryAmendment $amendment, Collection $sourceStages, int $userId): int
    {
        $created = 0;

        foreach ($sourceStages as $source) {
            $amendment->executionStages()->create([
                'municipality_id' => $amendment->municipality_id,
                'created_by' => $userId,
                'responsible_user_id' => $amendment->responsible_user_id,
                'title' => $source->title,
                'description' => $source->physical_delivery ?? $source->description ?? $amendment->object,
                'status' => ExecutionStage::STATUS_PLANNED,
                'progress_percentage' => 0,
                'planned_amount' => $source->planned_amount,
                'planned_start_at' => $source->planned_start_at,
                'planned_end_at' => $source->planned_end_at ?? $amendment->execution_deadline,
                'sort_order' => $source->sort_order ?? (($created + 1) * 10),
            ]);
            $created++;
        }

        if ($created > 0 && $amendment->status !== ParliamentaryAmendment::STATUS_COMPLETED) {
            $amendment->update(['status' => ParliamentaryAmendment::STATUS_EXECUTING]);
        }

        return $created;
    }

    /** @return array<int, string> */
    private function risks(ParliamentaryAmendment $amendment, float $receivedAmount, float $committedAmount, float $paidAmount, int $physicalPercentage): array
    {
        $risks = [];

        if ($amendment->executionStages->isEmpty()) {
            $risks[] = 'Ainda nao ha etapa fisica para comprovar a entrega.';
        }
        if ($receivedAmount <= 0) {
            $risks[] = 'Valor recebido nao informado; a conciliacao financeira fica incompleta.';
        }
        if ($committedAmount > $receivedAmount && $receivedAmount > 0) {
            $risks[] = 'Empenhos ultrapassam o valor recebido.';
        }
        if ($paidAmount > $receivedAmount && $receivedAmount > 0) {
            $risks[] = 'Pagamentos ultrapassam o valor recebido.';
        }
        if ($paidAmount > 0 && $amendment->documents->whereNotNull('execution_stage_id')->isEmpty()) {
            $risks[] = 'Ha pagamento sem evidencia de entrega vinculada.';
        }
        if ($physicalPercentage >= 100 && $paidAmount <= 0) {
            $risks[] = 'Execucao fisica concluida sem pagamento registrado.';
        }

        return $risks ?: ['Sem risco critico na execucao simplificada atual.'];
    }
}
