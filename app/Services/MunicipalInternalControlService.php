<?php

namespace App\Services;

use App\Models\ParliamentaryAmendment;

class MunicipalInternalControlService
{
    public const FRAMEWORK_VERSION = 'tcesp-manual-2026-07-control';

    public const SOURCE_LABEL = 'Manual de Emendas Parlamentares Impositivas Municipais - TCESP, capítulo 7';

    public const SOURCE_URL = 'https://www.tce.sp.gov.br/publicacoes/manual-emendas-parlamentares-impositivas-municipais';

    /** @return array<string, array{label: string, guidance: string, source: string}> */
    public function criteria(): array
    {
        return [
            'work_plan' => [
                'label' => 'Adequação do Plano de Trabalho',
                'guidance' => 'Confira objeto, metas, custos, cronograma, beneficiário e aderência entre planejamento e execução.',
                'source' => 'Manual TCESP, item 7.3; Comunicado GP 15/2026, XVI',
            ],
            'budget' => [
                'label' => 'Compatibilidade orçamentária',
                'guidance' => 'Verifique PPA, LDO, LOA, dotação, fonte e códigos de aplicação.',
                'source' => 'Comunicado GP 15/2026, II e XVI',
            ],
            'procurement' => [
                'label' => 'Regularidade da contratação',
                'guidance' => 'Analise processo licitatório, contratação direta, instrumento contratual ou parceria aplicável.',
                'source' => 'Comunicado GP 15/2026, VI e XVI',
            ],
            'conflicts' => [
                'label' => 'Conflitos de interesses e integridade',
                'guidance' => 'Registre a verificação de direcionamento, parentesco, nepotismo e vínculos incompatíveis.',
                'source' => 'Comunicado GP 15/2026, XI, XV e XVI',
            ],
            'transparency' => [
                'label' => 'Transparência ativa',
                'guidance' => 'Confira a publicação do processo, autor, objeto, valor, cronograma, execução e documentos.',
                'source' => 'Resolução TCESP 17/2025, art. 3º; Comunicado GP 15/2026, XVII-XIX',
            ],
            'banking' => [
                'label' => 'Rastreabilidade bancária',
                'guidance' => 'Confirme conta específica quando exigida ou a exceção municipal documentada com rastreabilidade contábil.',
                'source' => 'Manual TCESP, item 7.3; Comunicado Audesp 09/2026',
            ],
            'accounting' => [
                'label' => 'Escrituração contábil e Audesp',
                'guidance' => 'Verifique individualização da emenda, cadastro prévio e consistência de empenhos, liquidações e pagamentos.',
                'source' => 'Manual TCESP, itens 6.5 e 7.3',
            ],
            'execution' => [
                'label' => 'Aderência da execução ao objeto',
                'guidance' => 'Compare entregas físicas, despesas, prazos e finalidade com o Plano de Trabalho aprovado.',
                'source' => 'Manual TCESP, item 7.3',
            ],
        ];
    }

    /** @return array<string, string> */
    public function criterionStatuses(): array
    {
        return [
            'compliant' => 'Conforme',
            'attention' => 'Ponto de atenção',
            'non_compliant' => 'Não conforme',
            'not_applicable' => 'Não se aplica',
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(ParliamentaryAmendment $amendment): array
    {
        $amendment->loadMissing([
            'municipality:id,name,state,ibge_code,cnpj',
            'responsibleUser:id,name,email',
            'regulatoryProfile:id,reference,status,version',
            'municipalWorkPlan.stages',
            'municipalWorkPlan.reviews',
            'documents:id,parliamentary_amendment_id,original_name,version,size_bytes,created_at',
            'executionStages:id,parliamentary_amendment_id,title,status,progress_percentage,planned_end_at',
            'financialCommitments.liquidations.payments',
            'financialPayments:id,parliamentary_amendment_id,amount,paid_at',
            'audespRegistration',
            'integrityAlerts' => fn ($query) => $query->where('status', 'open'),
        ]);

        $plan = $amendment->municipalWorkPlan;

        return [
            'framework_version' => self::FRAMEWORK_VERSION,
            'captured_at' => now()->toIso8601String(),
            'municipality' => $amendment->municipality->only(['id', 'name', 'state', 'ibge_code', 'cnpj']),
            'amendment' => [
                ...$amendment->only([
                    'id', 'reference', 'fiscal_year', 'government_sphere', 'transfer_type',
                    'author_name', 'object', 'responsible_department', 'administrative_process',
                    'bank_tracking_type', 'bank_account_number', 'funding_source_code',
                    'application_code_fixed', 'application_code_variable', 'expected_amount',
                    'received_amount', 'status', 'indicated_at', 'received_at',
                    'execution_deadline', 'accountability_deadline',
                ]),
                'responsible' => $amendment->responsibleUser?->only(['id', 'name', 'email']),
            ],
            'regulatory_profile' => $amendment->regulatoryProfile?->only(['id', 'reference', 'status', 'version']),
            'work_plan' => $plan ? [
                'id' => $plan->id,
                'status' => $plan->status,
                'revision' => $plan->revision_number,
                'approved_at' => $plan->approved_at?->toIso8601String(),
                'beneficiary_name' => $plan->beneficiary_name,
                'object_description' => $plan->object_description,
                'budget_program' => $plan->budget_program,
                'budget_action' => $plan->budget_action,
                'stages' => $plan->stages->map->only(['title', 'physical_delivery', 'planned_amount', 'planned_start_at', 'planned_end_at'])->values()->all(),
            ] : null,
            'execution' => [
                'physical_percentage' => $amendment->physicalExecutionPercentage(),
                'stages' => $amendment->executionStages->map->only(['title', 'status', 'progress_percentage', 'planned_end_at'])->values()->all(),
                'committed_total' => (string) $amendment->financialCommitments->where('status', 'active')->sum('amount'),
                'paid_total' => (string) $amendment->financialPayments->sum('amount'),
            ],
            'controls' => [
                'document_count' => $amendment->documents->count(),
                'documents' => $amendment->documents
                    ->map->only(['id', 'original_name', 'version', 'size_bytes', 'created_at'])
                    ->values()
                    ->all(),
                'open_alerts' => $amendment->integrityAlerts->map->only(['alert_key', 'severity', 'title', 'due_at'])->values()->all(),
                'audesp_status' => $amendment->audespRegistration?->status,
            ],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
