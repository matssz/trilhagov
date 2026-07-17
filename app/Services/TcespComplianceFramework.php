<?php

namespace App\Services;

use App\Models\AmendmentComplianceReview;
use App\Models\ParliamentaryAmendment;
use Illuminate\Support\Collection;

class TcespComplianceFramework
{
    public const VERSION = 'tcesp-manual-2026-07';

    public const SOURCE_LABEL = 'Manual de Emendas Parlamentares Impositivas Municipais - TCESP, julho de 2026';

    public const SOURCE_URL = 'https://www.tce.sp.gov.br/publicacoes/manual-emendas-parlamentares-impositivas-municipais';

    public function appliesTo(ParliamentaryAmendment $amendment): bool
    {
        return $amendment->supportsTcespCompliance();
    }

    /** @return array<string, array{label: string, icon: string}> */
    public function categories(): array
    {
        return [
            'normative' => ['label' => 'Base normativa', 'icon' => 'landmark'],
            'budget' => ['label' => 'Objeto e orçamento', 'icon' => 'scan-search'],
            'viability' => ['label' => 'Metas e viabilidade', 'icon' => 'route'],
            'work_plan' => ['label' => 'Plano de trabalho', 'icon' => 'clipboard-list'],
            'beneficiary' => ['label' => 'Beneficiário e saúde', 'icon' => 'building-2'],
            'impediments' => ['label' => 'Impedimentos técnicos', 'icon' => 'shield-alert'],
            'traceability' => ['label' => 'Transparência e Audesp', 'icon' => 'waypoints'],
            'control' => ['label' => 'Prestação e controle', 'icon' => 'file-check-2'],
        ];
    }

    /**
     * @return array<int, array{code: string, category: string, title: string, guidance: string, source: string, critical: bool}>
     */
    public function rules(): array
    {
        return [
            ['code' => 'NORM-01', 'category' => 'normative', 'title' => 'Regime previsto na legislação municipal', 'guidance' => 'Confirme a Lei Orgânica que institui o regime, o teto aplicável, a reserva da saúde e as hipóteses de impedimento.', 'source' => 'Cap. 4, item 4.12, p. 43', 'critical' => true],
            ['code' => 'NORM-02', 'category' => 'normative', 'title' => 'Fluxo e prazos regulamentados', 'guidance' => 'Regimento Interno, LDO ou norma complementar devem disciplinar análise, plano de trabalho, impedimentos e prazos.', 'source' => 'Cap. 4, itens 4.11 e 4.12, p. 42-43', 'critical' => false],
            ['code' => 'ORC-01', 'category' => 'budget', 'title' => 'Objeto preciso e não genérico', 'guidance' => 'O objeto deve descrever entrega verificável. Expressões amplas, como “custeio da saúde”, não delimitam o resultado.', 'source' => 'Cap. 4, item 4.12, p. 43', 'critical' => true],
            ['code' => 'ORC-02', 'category' => 'budget', 'title' => 'Programa, ação e política setorial compatíveis', 'guidance' => 'Vincule o objeto a programa ou ação da LOA e confirme a competência do órgão executor e a política pública correspondente.', 'source' => 'Cap. 4, item 4.12, p. 43', 'critical' => true],
            ['code' => 'ORC-03', 'category' => 'budget', 'title' => 'Despesa discricionária e enquadramento coerente', 'guidance' => 'Confirme que a despesa não é obrigatória ou previamente vinculada e que beneficiário, ação e localidade são coerentes.', 'source' => 'Cap. 4, item 4.12, p. 43', 'critical' => false],
            ['code' => 'VIA-01', 'category' => 'viability', 'title' => 'Metas física e finalística definidas', 'guidance' => 'Registre o que será entregue e o benefício público mensurável esperado.', 'source' => 'Cap. 4, item 4.12, p. 43', 'critical' => true],
            ['code' => 'VIA-02', 'category' => 'viability', 'title' => 'Custo suficiente para o objeto ou etapa útil', 'guidance' => 'O valor deve concluir o objeto ou uma etapa autônoma, funcional e imediatamente utilizável pela população.', 'source' => 'Cap. 5, item 5.5, p. 47-48', 'critical' => true],
            ['code' => 'VIA-03', 'category' => 'viability', 'title' => 'Projeto, licenças e custos acessórios avaliados', 'guidance' => 'Quando exigíveis, documente projeto de engenharia, licença ambiental e custos necessários para colocar a entrega em uso.', 'source' => 'Cap. 5, item 5.5, p. 48-49', 'critical' => false],
            ['code' => 'VIA-04', 'category' => 'viability', 'title' => 'Operação e manutenção futuras demonstradas', 'guidance' => 'Comprove capacidade de custeio, operação e manutenção após a entrega, inclusive impactos continuados quando houver.', 'source' => 'Cap. 5, item 5.5, p. 49', 'critical' => true],
            ['code' => 'PLAN-01', 'category' => 'work_plan', 'title' => 'Emenda, autor e beneficiário identificados', 'guidance' => 'O plano deve identificar a emenda, autoria, beneficiário, CNPJ e contato, quando aplicável.', 'source' => 'Cap. 4, plano de trabalho e item 4.11, p. 42', 'critical' => true],
            ['code' => 'PLAN-02', 'category' => 'work_plan', 'title' => 'Objeto e necessidade pública justificados', 'guidance' => 'Descreva detalhadamente o objeto e a necessidade pública que fundamenta a proposta.', 'source' => 'Cap. 4, plano de trabalho', 'critical' => true],
            ['code' => 'PLAN-03', 'category' => 'work_plan', 'title' => 'Etapas e cronograma físico-financeiro', 'guidance' => 'Defina etapas, prazos, desembolsos e a relação entre evolução física e financeira.', 'source' => 'Cap. 4, item 4.12, p. 43', 'critical' => true],
            ['code' => 'PLAN-04', 'category' => 'work_plan', 'title' => 'Plano de aplicação e memória de cálculo', 'guidance' => 'Apresente custos detalhados e memória de cálculo que permitam verificar a estimativa.', 'source' => 'Cap. 4, plano de trabalho', 'critical' => true],
            ['code' => 'BEN-01', 'category' => 'beneficiary', 'title' => 'Beneficiário verificado', 'guidance' => 'Quando houver entidade beneficiária, confira CNPJ, natureza sem fins lucrativos, pertinência temática e regularidade jurídica, fiscal e técnica.', 'source' => 'Cap. 4, item 4.12, p. 43', 'critical' => true],
            ['code' => 'BEN-02', 'category' => 'beneficiary', 'title' => 'Controles de parceria e nepotismo', 'guidance' => 'Para execução por organização da sociedade civil, verifique declaração de inexistência de nepotismo e conta específica.', 'source' => 'Cap. 4, item 4.12, p. 43; Cap. 8', 'critical' => false],
            ['code' => 'SAU-01', 'category' => 'beneficiary', 'title' => 'Reserva e objeto de saúde validados', 'guidance' => 'Quando aplicável, confirme que o objeto é ação ou serviço público de saúde e que a reserva mínima foi respeitada conforme a norma municipal.', 'source' => 'Cap. 4, item 4.12, p. 43', 'critical' => true],
            ['code' => 'IMP-01', 'category' => 'impediments', 'title' => 'Parecer de admissibilidade documentado', 'guidance' => 'A análise preliminar da Câmara deve concluir pela aprovação, ajuste ou rejeição fundamentada antes do plenário.', 'source' => 'Cap. 4, análise preliminar e Comunicado GP 15/2026', 'critical' => true],
            ['code' => 'IMP-02', 'category' => 'impediments', 'title' => 'Impedimento classificado e fundamentado', 'guidance' => 'Quando houver óbice, classifique-o como orçamentário-financeiro, técnico-operacional ou formal e como temporário ou insuperável.', 'source' => 'Cap. 5, itens 5.5 e 5.6, p. 47-50', 'critical' => true],
            ['code' => 'IMP-03', 'category' => 'impediments', 'title' => 'Notificação e saneamento formalizados', 'guidance' => 'Registre hipótese legal, fatos, proposta de saneamento ou remanejamento e prazo do autor. O Executivo não pode alterar o objeto de ofício.', 'source' => 'Cap. 5, item 5.7, p. 50-51', 'critical' => true],
            ['code' => 'TRA-01', 'category' => 'traceability', 'title' => 'Transparência dedicada e atualizada', 'guidance' => 'Divulgue os dados do artigo 3º em seção própria, pesquisável e exportável, com acesso ao processo, estágio e data de atualização.', 'source' => 'Cap. 6; Resolução TCESP 17/2025, art. 3º', 'critical' => true],
            ['code' => 'TRA-02', 'category' => 'traceability', 'title' => 'Cadastro da emenda no Audesp', 'guidance' => 'Cadastre individualmente a emenda antes dos balancetes. Desde abril de 2026, a regra 47.4.63 impede saldo vinculado sem cadastro.', 'source' => 'Cap. 1 e Cap. 6; regra Audesp 47.4.63', 'critical' => true],
            ['code' => 'TRA-03', 'category' => 'traceability', 'title' => 'Rastreabilidade bancária e contábil', 'guidance' => 'Use conta exclusiva quando exigida e individualize fonte, códigos de aplicação, empenhos, liquidações e rendimentos. Na execução direta, documente a exceção admitida pelo TCESP.', 'source' => 'Cap. 6; Comunicado Audesp de 18/03/2026', 'critical' => true],
            ['code' => 'CON-01', 'category' => 'control', 'title' => 'Execução comprovada e inspecionada', 'guidance' => 'Organize contratos, notas, pagamentos, extratos, termos de recebimento e inspeção técnica até a conclusão do objeto.', 'source' => 'Cap. 7, prestação de contas', 'critical' => true],
            ['code' => 'CON-02', 'category' => 'control', 'title' => 'Controle interno realizou revisão concomitante', 'guidance' => 'Documente a revisão de plano, orçamento, contratação, conflitos, transparência, contas, contabilidade e aderência da execução.', 'source' => 'Cap. 7, controle interno', 'critical' => true],
        ];
    }

    public function hasRule(string $code): bool
    {
        return collect($this->rules())->contains('code', $code);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function matrix(ParliamentaryAmendment $amendment): Collection
    {
        $reviews = $amendment->complianceReviews
            ->where('framework_version', self::VERSION)
            ->keyBy('rule_code');

        return collect($this->rules())->map(function (array $rule) use ($reviews): array {
            $review = $reviews->get($rule['code']);

            return [
                ...$rule,
                'review' => $review,
                'status' => $review?->status ?? AmendmentComplianceReview::STATUS_PENDING,
            ];
        });
    }

    /** @return array{total: int, applicable: int, compliant: int, non_compliant: int, pending: int, not_applicable: int, percentage: int} */
    public function summary(Collection $matrix): array
    {
        $counts = $matrix->countBy('status');
        $notApplicable = $counts->get(AmendmentComplianceReview::STATUS_NOT_APPLICABLE, 0);
        $applicable = $matrix->count() - $notApplicable;
        $compliant = $counts->get(AmendmentComplianceReview::STATUS_COMPLIANT, 0);

        return [
            'total' => $matrix->count(),
            'applicable' => $applicable,
            'compliant' => $compliant,
            'non_compliant' => $counts->get(AmendmentComplianceReview::STATUS_NON_COMPLIANT, 0),
            'pending' => $counts->get(AmendmentComplianceReview::STATUS_PENDING, 0),
            'not_applicable' => $notApplicable,
            'percentage' => $applicable > 0 ? (int) round(($compliant / $applicable) * 100) : 0,
        ];
    }
}
