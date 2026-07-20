<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'municipality_id',
        'user_id',
        'actor_name',
        'action',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Registros de auditoria não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Registros de auditoria não podem ser excluídos.'));
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            'execution_stage_created' => 'Etapa de execução criada',
            'execution_stage_updated' => 'Etapa de execução atualizada',
            'financial_commitment_created' => 'Empenho registrado',
            'financial_commitment_cancelled' => 'Empenho cancelado',
            'financial_payment_created' => 'Pagamento registrado',
            'financial_liquidation_created' => 'Liquidação registrada',
            'audesp_registration_created' => 'Cadastro Audesp iniciado',
            'audesp_registration_updated' => 'Cadastro Audesp atualizado',
            'audesp_preview_exported' => 'Prévia Audesp exportada',
            'audesp_diagnostic_exported' => 'Diagnóstico Audesp exportado',
            'audesp_homologation_created' => 'Lote de homologação Audesp criado',
            'audesp_homologation_rechecked' => 'Lote Audesp reconferido',
            'audesp_submission_recorded' => 'Transmissão Audesp registrada',
            'audesp_return_recorded' => 'Retorno Audesp registrado',
            'accountability_created' => 'Prestação de contas iniciada',
            'accountability_updated' => 'Prestação de contas atualizada',
            'accountability_requirement_created' => 'Item do checklist criado',
            'accountability_requirement_updated' => 'Checklist da prestação atualizado',
            'accountability_diligence_created' => 'Diligência registrada',
            'accountability_diligence_updated' => 'Diligência atualizada',
            'transparency_updated' => 'Transparência atualizada',
            'report_exported' => 'Relatório exportado',
            'external_sync_finished' => 'Fonte oficial sincronizada',
            'external_candidate_linked' => 'Plano oficial vinculado',
            'external_candidate_imported' => 'Emenda importada da fonte oficial',
            'external_fields_applied' => 'Dados oficiais aplicados',
            'external_candidate_ignored' => 'Candidato oficial ignorado',
            'external_financial_reconciled' => 'Conciliação financeira oficial atualizada',
            'work_items_synchronized' => 'Plano operacional atualizado',
            'work_item_updated' => 'Ação operacional atualizada',
            'amendments_spreadsheet_previewed' => 'Planilha de emendas conferida',
            'amendments_spreadsheet_imported' => 'Planilha de emendas importada',
            'compliance_review_updated' => 'Conformidade TCESP revisada',
            'municipal_work_plan_created' => 'Plano de trabalho iniciado',
            'municipal_work_plan_updated' => 'Plano de trabalho atualizado',
            'municipal_work_plan_submitted' => 'Plano enviado para análise',
            'municipal_work_plan_stage_created' => 'Etapa do plano criada',
            'municipal_work_plan_stage_updated' => 'Etapa do plano atualizada',
            'municipal_work_plan_stage_deleted' => 'Etapa do plano removida',
            'municipal_admissibility_review_created' => 'Parecer de admissibilidade emitido',
            'technical_impediment_created' => 'Impedimento técnico registrado',
            'technical_impediment_updated' => 'Impedimento técnico atualizado',
            'technical_diligence_created' => 'Diligência técnica aberta',
            'technical_diligence_updated' => 'Diligência técnica atualizada',
            'amendment_remapping_created' => 'Proposta de remanejamento criada',
            'amendment_remapping_updated' => 'Proposta de remanejamento atualizada',
            'amendment_remapping_submitted' => 'Remanejamento enviado para decisão',
            'amendment_remapping_decided' => 'Remanejamento decidido',
            'municipal_rules_created' => 'Configuração normativa iniciada',
            'municipal_rules_updated' => 'Parâmetros municipais atualizados',
            'municipal_rules_activated' => 'Configuração normativa ativada',
            'municipal_rules_revised' => 'Revisão normativa criada',
            'municipal_instrument_created' => 'Instrumento normativo vinculado',
            'municipal_instrument_removed' => 'Instrumento normativo removido',
            'created' => 'Emenda cadastrada',
            'updated' => 'Emenda atualizada',
            'role_updated' => 'Perfil de acesso atualizado',
            'document_uploaded' => 'Documento anexado',
            'document_type_created' => 'Tipo de documento criado',
            'document_type_updated' => 'Tipo de documento atualizado',
            default => 'Alteração registrada',
        };
    }

    /** @return array<int, array{label: string, old: string, new: string}> */
    public function changesForDisplay(): array
    {
        if ($this->action === 'created') {
            return [];
        }

        return collect($this->new_values ?? [])
            ->map(fn (mixed $value, string $field) => [
                'label' => self::fieldLabels()[$field] ?? Str::headline($field),
                'old' => $this->formatValue($field, ($this->old_values ?? [])[$field] ?? null),
                'new' => $this->formatValue($field, $value),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    private static function fieldLabels(): array
    {
        return [
            'stage' => 'Etapa',
            'title' => 'Título da etapa',
            'stage_status' => 'Situação da etapa',
            'progress_percentage' => 'Progresso físico',
            'planned_end_at' => 'Previsão de conclusão',
            'commitment_number' => 'Número do empenho',
            'supplier_name' => 'Fornecedor',
            'committed_amount' => 'Valor empenhado',
            'cancellation_reason' => 'Motivo do cancelamento',
            'payment_reference' => 'Referência do pagamento',
            'payment_amount' => 'Valor pago',
            'liquidation_reference' => 'Referência da liquidação',
            'liquidation_amount' => 'Valor liquidado',
            'supporting_document' => 'Documento da liquidação',
            'audesp_schema' => 'Versão do XSD Audesp',
            'audesp_number' => 'Número no cadastro Audesp',
            'audesp_year' => 'Ano no cadastro Audesp',
            'audesp_application_code' => 'Código de aplicação combinado',
            'audesp_ready' => 'Prontidão Audesp',
            'audesp_blockers' => 'Bloqueios Audesp',
            'preview_count' => 'Prévias geradas',
            'execution_stage' => 'Etapa de execução',
            'accountability_status' => 'Situação da prestação',
            'accountability_due_at' => 'Prazo da prestação',
            'requirement' => 'Item do checklist',
            'requirement_category' => 'Categoria',
            'requirement_status' => 'Situação do item',
            'accountability_document' => 'Documento vinculado',
            'diligence' => 'Diligência',
            'diligence_due_at' => 'Prazo da diligência',
            'diligence_status' => 'Situação da diligência',
            'assigned_user_id' => 'Responsável',
            'protocol_number' => 'Protocolo',
            'submitted_at' => 'Data de envio',
            'approved_at' => 'Data de aprovação',
            'returned_amount' => 'Valor devolvido',
            'returned_at' => 'Data da devolução',
            'transparency_enabled' => 'Portal de transparência',
            'export_format' => 'Formato exportado',
            'report_filters' => 'Filtros do relatório',
            'external_source' => 'Fonte externa',
            'external_code' => 'Código externo',
            'external_id' => 'Identificador externo',
            'external_match_status' => 'Conferência externa',
            'sync_status' => 'Situação da sincronização',
            'records' => 'Registros consultados',
            'divergences' => 'Divergências encontradas',
            'review_notes' => 'Justificativa da revisão',
            'compliance_rule' => 'Regra TCESP',
            'compliance_status' => 'Situação de conformidade',
            'compliance_evidence' => 'Evidência ou constatação',
            'compliance_document' => 'Documento de evidência',
            'compliance_framework' => 'Versão da matriz',
            'work_plan_status' => 'Situação do plano',
            'work_plan_revision' => 'Revisão do plano',
            'work_plan_fields' => 'Campos atualizados',
            'work_plan_stage' => 'Etapa do plano',
            'planned_amount' => 'Valor planejado',
            'admissibility_conclusion' => 'Conclusão da admissibilidade',
            'admissibility_rationale' => 'Fundamentação do parecer',
            'impediment' => 'Impedimento',
            'impediment_category' => 'Categoria do impedimento',
            'impediment_nature' => 'Natureza do impedimento',
            'impediment_status' => 'Situação do impedimento',
            'impediment_due_at' => 'Prazo do impedimento',
            'communicated_at' => 'Impedimento comunicado em',
            'communication_reference' => 'Protocolo da comunicação',
            'communication_due_at' => 'Prazo de comunicação do impedimento',
            'resolution_notes' => 'Fundamentação da solução',
            'technical_diligence' => 'Diligência técnica',
            'technical_diligence_status' => 'Situação da diligência técnica',
            'remapping_status' => 'Situação do remanejamento',
            'remapping_amount' => 'Valor do remanejamento',
            'regulatory_version' => 'Versão normativa',
            'municipal_regulatory_profile_id' => 'Versão normativa vinculada',
            'amendments_bound' => 'Emendas vinculadas',
            'impediments_bound' => 'Impedimentos vinculados',
            'normative_instrument' => 'Instrumento normativo',
            'instrument_type' => 'Tipo de instrumento',
            'regime_status' => 'Situação do regime',
            'previous_year_rcl' => 'RCL do exercício anterior',
            'individual_limit_percentage' => 'Percentual-limite individual',
            'health_reserve_percentage' => 'Reserva mínima da saúde',
            'health_reserve_method' => 'Método da reserva da saúde',
            'impediment_notice_days' => 'Prazo para comunicar impedimento',
            'impediment_correction_days' => 'Prazo para saneamento',
            'publication_business_days' => 'Prazo de publicação',
            'document_retention_years' => 'Retenção documental',
            'bank_traceability_rule' => 'Rastreabilidade bancária',
            'audesp_registration_status' => 'Preparação Audesp',
            'legal_review_responsible' => 'Responsável pela revisão jurídica',
            'legal_review_reference' => 'Referência da revisão jurídica',
            'legal_reviewed_at' => 'Data da revisão jurídica',
            'proposed_object' => 'Objeto proposto',
            'decision_reference' => 'Referência da decisão',
            'work_item' => 'Ação operacional',
            'work_item_status' => 'Andamento da ação',
            'active' => 'Ações ativas',
            'reopened' => 'Ações reabertas',
            'completed' => 'Ações resolvidas',
            'import_batch' => 'Lote de importação',
            'source_file' => 'Arquivo de origem',
            'total_rows' => 'Linhas analisadas',
            'valid_rows' => 'Linhas aptas',
            'imported_rows' => 'Linhas importadas',
            'duplicate_rows' => 'Linhas duplicadas',
            'invalid_rows' => 'Linhas inválidas',
            'return_reference' => 'Referência da devolução',
            'response_protocol' => 'Protocolo da resposta',
            'role' => 'Perfil de acesso',
            'document_type' => 'Tipo de documento',
            'document_name' => 'Arquivo',
            'document_version' => 'Versão',
            'document_size' => 'Tamanho',
            'name' => 'Nome',
            'description' => 'Descrição',
            'is_required' => 'Obrigatório',
            'is_active' => 'Ativo',
            'sort_order' => 'Ordem',
            'reference' => 'Identificação',
            'fiscal_year' => 'Exercício',
            'government_sphere' => 'Esfera',
            'authorship_type' => 'Tipo de autoria',
            'transfer_type' => 'Modalidade',
            'author_name' => 'Autor',
            'author_party' => 'Partido',
            'object' => 'Objeto',
            'responsible_department' => 'Órgão responsável',
            'responsible_user_id' => 'Responsável operacional',
            'transferegov_code' => 'Código Transferegov',
            'expected_amount' => 'Valor previsto',
            'received_amount' => 'Valor recebido',
            'status' => 'Situação',
            'indicated_at' => 'Data da indicação',
            'received_at' => 'Data do recebimento',
            'communication_deadline' => 'Prazo de comunicação',
            'communication_completed_at' => 'Comunicação concluída em',
            'execution_deadline' => 'Prazo de execução',
            'execution_completed_at' => 'Execução concluída em',
            'accountability_deadline' => 'Prazo de prestação de contas',
            'accountability_completed_at' => 'Prestação de contas concluída em',
            'notes' => 'Observações internas',
        ];
    }

    private function formatValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Não informado';
        }

        if ($field === 'role') {
            return User::municipalityRoles()[$value] ?? (string) $value;
        }

        if (in_array($field, ['responsible_user_id', 'assigned_user_id'], true)) {
            return User::query()->find($value)?->name ?? 'Usuário não disponível';
        }

        if (in_array($field, ['is_required', 'is_active'], true)) {
            return $value ? 'Sim' : 'Não';
        }

        if (in_array($field, ['expected_amount', 'received_amount', 'committed_amount', 'payment_amount', 'liquidation_amount', 'returned_amount', 'planned_amount', 'remapping_amount'], true)) {
            return 'R$ '.number_format((float) $value, 2, ',', '.');
        }

        if ($field === 'progress_percentage') {
            return (int) $value.'%';
        }

        if ($field === 'status') {
            if (str_starts_with($this->action, 'accountability_')) {
                return AccountabilityProcess::statuses()[$value] ?? (string) $value;
            }

            return ParliamentaryAmendment::statuses()[$value] ?? (string) $value;
        }

        if ($field === 'compliance_status') {
            return AmendmentComplianceReview::statuses()[$value] ?? (string) $value;
        }

        if ($field === 'work_plan_status') {
            return MunicipalWorkPlan::statuses()[$value] ?? (string) $value;
        }

        if ($field === 'admissibility_conclusion') {
            return MunicipalAdmissibilityReview::conclusions()[$value] ?? (string) $value;
        }

        if ($field === 'impediment_category') {
            return TechnicalImpediment::categories()[$value] ?? (string) $value;
        }

        if ($field === 'impediment_nature') {
            return TechnicalImpediment::natures()[$value] ?? (string) $value;
        }

        if ($field === 'impediment_status') {
            return TechnicalImpediment::statuses()[$value] ?? (string) $value;
        }

        if ($field === 'technical_diligence_status') {
            return TechnicalDiligence::statuses()[$value] ?? (string) $value;
        }

        if ($field === 'remapping_status') {
            return AmendmentRemapping::statuses()[$value] ?? (string) $value;
        }

        if ($field === 'government_sphere') {
            return ParliamentaryAmendment::governmentSpheres()[$value] ?? (string) $value;
        }

        if ($field === 'authorship_type') {
            return ParliamentaryAmendment::authorshipTypes()[$value] ?? (string) $value;
        }

        if ($field === 'transfer_type') {
            return ParliamentaryAmendment::transferTypes()[$value] ?? (string) $value;
        }

        if (str_ends_with($field, '_at') || str_ends_with($field, '_deadline')) {
            return Carbon::parse($value)->format('d/m/Y');
        }

        return Str::limit((string) $value, 180);
    }
}
