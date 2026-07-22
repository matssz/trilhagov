<?php

namespace App\Services;

use App\Models\HealthAspsAssessment;
use App\Models\ParliamentaryAmendment;

class HealthAspsFramework
{
    public const VERSION = 'lc141-2012-rev-2024-01';

    public const LAW_URL = 'https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp141.htm';

    public const SIOPS_URL = 'https://www.gov.br/saude/pt-br/acesso-a-informacao/siops/siops';

    /** @return array<string, string> */
    public function criteria(): array
    {
        return [
            'universal_free_access' => 'Acesso universal, igualitário e gratuito',
            'health_plan_alignment' => 'Compatibilidade com objetivos e metas do Plano Municipal de Saúde',
            'health_sector_responsibility' => 'Responsabilidade específica do setor saúde',
            'health_fund_financing' => 'Recursos movimentados pelo Fundo Municipal de Saúde',
            'sus_scope' => 'Objeto inserido nas ações e serviços públicos do SUS',
            'health_council_approval' => 'Aprovação do Conselho de Saúde, quando exigida',
        ];
    }

    /** @return array<string, string> */
    public function categories(): array
    {
        return [
            'health_surveillance' => 'Vigilância em saúde, epidemiológica ou sanitária',
            'comprehensive_care' => 'Atenção integral e universal à saúde',
            'sus_training' => 'Capacitação de pessoal do SUS',
            'science_and_quality' => 'Desenvolvimento científico, tecnológico e controle de qualidade do SUS',
            'health_inputs' => 'Insumos específicos, medicamentos ou equipamentos médico-odontológicos',
            'small_community_sanitation' => 'Saneamento de domicílios ou pequenas comunidades',
            'special_territory_sanitation' => 'Saneamento de territórios indígenas ou comunidades quilombolas',
            'vector_control' => 'Manejo ambiental diretamente ligado ao controle de vetores',
            'sus_physical_network' => 'Investimento na rede física do SUS',
            'active_health_personnel' => 'Pessoal ativo atuando nas ações de saúde',
            'sus_administrative_support' => 'Apoio administrativo imprescindível às ações do SUS',
            'public_health_management' => 'Gestão do sistema público ou operação de unidade pública de saúde',
        ];
    }

    /** @return array<string, string> */
    public function exclusions(): array
    {
        return [
            'retirement_or_pension' => 'Aposentadoria ou pensão, inclusive de servidor da saúde',
            'personnel_outside_health' => 'Pessoal da saúde em atividade alheia ao setor',
            'non_universal_care' => 'Assistência à saúde sem acesso universal',
            'school_meals' => 'Merenda escolar ou programa comum de alimentação',
            'ordinary_sanitation' => 'Saneamento básico fora das hipóteses admitidas',
            'urban_cleaning' => 'Limpeza urbana ou remoção de resíduos',
            'general_environment' => 'Preservação ambiental sem vínculo direto com controle de vetores',
            'social_assistance' => 'Ação de assistência social',
            'general_infrastructure' => 'Obra de infraestrutura sem integração à rede física do SUS',
            'outside_health_funding' => 'Recurso fora da base ou de fundo específico da saúde',
        ];
    }

    /** @return array{ready: bool, blockers: array<int, string>, warnings: array<int, string>, recommendation: string} */
    public function evaluate(HealthAspsAssessment $assessment, ParliamentaryAmendment $amendment): array
    {
        $blockers = [];
        $warnings = [];
        $criteria = $assessment->criteria ?? [];
        $exclusions = array_values(array_filter($assessment->exclusion_reasons ?? []));

        foreach (['universal_free_access', 'health_plan_alignment', 'health_sector_responsibility', 'health_fund_financing', 'sus_scope'] as $key) {
            if (! ($criteria[$key] ?? false)) {
                $blockers[] = $this->criteria()[$key].' não foi confirmado.';
            }
        }
        if ($assessment->asps_category === 'small_community_sanitation' && ! ($criteria['health_council_approval'] ?? false)) {
            $blockers[] = 'A categoria selecionada exige aprovação do Conselho de Saúde.';
        }
        if (! array_key_exists((string) $assessment->asps_category, $this->categories())) {
            $blockers[] = 'Selecione uma categoria de ASPS prevista no art. 3º da LC 141.';
        }
        if ($exclusions !== []) {
            $blockers[] = count($exclusions).' hipótese(s) de exclusão do art. 4º foram identificadas.';
        }
        if ($assessment->budget_function !== '10') {
            $blockers[] = 'A função orçamentária deve ser 10 para concluir pelo cômputo em ASPS.';
        }
        foreach ([
            'budget_subfunction' => 'Informe a subfunção orçamentária.',
            'funding_source_code' => 'Informe a fonte de recursos usada pela contabilidade.',
            'health_fund_reference' => 'Informe a referência do Fundo Municipal de Saúde.',
            'health_plan_reference' => 'Informe a meta ou diretriz do Plano Municipal de Saúde.',
            'technical_justification' => 'Registre a justificativa técnica do enquadramento.',
        ] as $field => $message) {
            if (! filled($assessment->{$field})) {
                $blockers[] = $message;
            }
        }
        if (! $amendment->municipalWorkPlan?->health_related) {
            $warnings[] = 'O plano de trabalho ainda não está marcado como relacionado à saúde.';
        }
        if (! $amendment->municipalWorkPlan?->health_reserve_verified) {
            $warnings[] = 'A reserva local da saúde ainda não foi confirmada no plano de trabalho.';
        }
        if ($amendment->audespRegistration && $amendment->audespRegistration->government_function !== '10') {
            $warnings[] = 'O cadastro Audesp utiliza função diferente de 10; concilie a origem antes da emissão.';
        }
        if (! $assessment->evidence_document_id) {
            $warnings[] = 'Nenhum documento da emenda foi vinculado como evidência principal.';
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'recommendation' => $exclusions !== [] ? HealthAspsAssessment::CONCLUSION_INELIGIBLE : ($blockers === [] ? HealthAspsAssessment::CONCLUSION_ELIGIBLE : 'pending'),
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(HealthAspsAssessment $assessment, ParliamentaryAmendment $amendment): array
    {
        return [
            'schema_version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'assessment' => [
                'reference' => $assessment->reference,
                'version' => $assessment->version,
                'conclusion' => $assessment->conclusion,
                'asps_category' => $assessment->asps_category,
                'criteria' => $assessment->criteria,
                'exclusion_reasons' => $assessment->exclusion_reasons,
                'budget_function' => $assessment->budget_function,
                'budget_subfunction' => $assessment->budget_subfunction,
                'funding_source_code' => $assessment->funding_source_code,
                'application_code' => $assessment->application_code,
                'health_fund_reference' => $assessment->health_fund_reference,
                'health_plan_reference' => $assessment->health_plan_reference,
                'technical_justification' => $assessment->technical_justification,
                'reviewer_notes' => $assessment->reviewer_notes,
                'evidence_document_id' => $assessment->evidence_document_id,
            ],
            'amendment' => [
                'id' => $amendment->id,
                'reference' => $amendment->reference,
                'fiscal_year' => $amendment->fiscal_year,
                'author' => $amendment->author_name,
                'object' => $amendment->object,
                'expected_amount' => (float) $amendment->expected_amount,
                'responsible_department' => $amendment->responsible_department,
                'work_plan_health_related' => (bool) $amendment->municipalWorkPlan?->health_related,
                'work_plan_health_reserve_verified' => (bool) $amendment->municipalWorkPlan?->health_reserve_verified,
            ],
            'basis' => [
                'law' => 'Lei Complementar Federal nº 141/2012, arts. 2º a 4º',
                'law_url' => self::LAW_URL,
                'siops_url' => self::SIOPS_URL,
                'framework_version' => self::VERSION,
            ],
            'disclaimer' => 'Parecer municipal de apoio ao enquadramento da emenda. Não substitui os registros contábeis, o RREO, a declaração no SIOPS nem a manifestação dos órgãos legalmente competentes.',
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
