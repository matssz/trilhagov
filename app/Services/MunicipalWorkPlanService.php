<?php

namespace App\Services;

use App\Models\MunicipalWorkPlan;
use App\Models\ParliamentaryAmendment;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MunicipalWorkPlanService
{
    /** @return array<string, mixed> */
    public function suggestedDraft(ParliamentaryAmendment $amendment): array
    {
        $start = $amendment->received_at ?? $amendment->indicated_at ?? today();
        $end = $amendment->execution_deadline ?? $start->copy()->addMonths(6);
        $department = $amendment->responsible_department ?: 'Unidade executora municipal';
        $amount = (float) $amendment->expected_amount;
        $object = trim((string) $amendment->object);
        $notes = trim((string) $amendment->notes);
        $healthContext = Str::of($department.' '.$object.' '.$notes)->ascii()->lower();
        $health = (bool) $amendment->indicated_for_health
            || $healthContext->contains(['saude', 'ubs', 'vacina', 'hospital', 'clinica']);

        return [
            'fields' => [
                'beneficiary_type' => 'municipal_body',
                'beneficiary_name' => $department,
                'beneficiary_contact' => $department,
                'object_description' => $object,
                'public_need' => $notes !== ''
                    ? $notes
                    : 'Necessidade publica vinculada a emenda '.$amendment->reference.' indicada para atendimento municipal.',
                'physical_target' => 'Executar o objeto indicado: '.$object,
                'finalistic_target' => $health
                    ? 'Ampliar ou qualificar o atendimento publico de saude vinculado ao objeto indicado.'
                    : 'Entregar beneficio direto a populacao municipal conforme o objeto aprovado.',
                'budget_program' => $health ? 'Programa municipal de saude' : 'Programa municipal a confirmar',
                'budget_action' => $health ? 'Acao de atencao e servicos publicos de saude' : 'Acao orcamentaria a confirmar',
                'application_plan' => 'Aplicacao integral dos recursos no objeto aprovado pela Camara e recebido pelo Executivo.',
                'cost_memory' => 'Valor base recebido da emenda: R$ '.number_format($amount, 2, ',', '.').'. Detalhar composicao final antes da analise tecnica.',
                'maintenance_plan' => 'A unidade executora municipal ficara responsavel pela operacao, guarda, manutencao e continuidade da entrega.',
                'health_related' => $health,
                'health_reserve_verified' => $health,
                'includes_engineering' => false,
                'engineering_project_status' => 'not_applicable',
                'environmental_license_status' => 'not_applicable',
                'pca_status' => 'update_requested',
                'planned_start_at' => $start->toDateString(),
                'planned_end_at' => $end->toDateString(),
            ],
            'stage' => [
                'title' => 'Execucao integral da emenda',
                'physical_delivery' => $object,
                'planned_amount' => $amount,
                'planned_start_at' => $start->toDateString(),
                'planned_end_at' => $end->toDateString(),
                'sort_order' => 10,
            ],
            'items' => [
                ['label' => 'Executor sugerido', 'value' => $department],
                ['label' => 'Valor planejado', 'value' => 'R$ '.number_format($amount, 2, ',', '.')],
                ['label' => 'Saude', 'value' => $health ? 'Reserva ja marcada' : 'Nao classificada como saude'],
                ['label' => 'Cronograma', 'value' => $start->format('d/m/Y').' a '.$end->format('d/m/Y')],
            ],
        ];
    }

    /** @return array<string, array{label: string, guidance: string}> */
    public function admissibilityCriteria(): array
    {
        return [
            'normative' => ['label' => 'Adequação normativa', 'guidance' => 'Lei Orgânica, LDO, Regimento Interno, limites e reserva da saúde.'],
            'budget' => ['label' => 'Objeto e enquadramento orçamentário', 'guidance' => 'Objeto preciso, programa/ação compatível, despesa discricionária e coerência do beneficiário.'],
            'viability' => ['label' => 'Metas, custos e funcionalidade', 'guidance' => 'Metas mensuráveis, custo realista, etapa útil, projetos, licenças e manutenção futura.'],
            'schedule' => ['label' => 'Plano de trabalho e prazos', 'guidance' => 'Cronograma físico-financeiro, memória de cálculo e viabilidade no exercício.'],
            'beneficiary' => ['label' => 'Beneficiário e Terceiro Setor', 'guidance' => 'CNPJ, pertinência temática, regularidade, ausência de nepotismo e conta específica quando aplicável.'],
            'health' => ['label' => 'Saúde', 'guidance' => 'Ação ou serviço público de saúde e reserva mínima, quando aplicável.'],
            'pca' => ['label' => 'Plano de Contratações Anual', 'guidance' => 'Inclusão no PCA ou encaminhamento dos elementos para atualização pelo Executivo.'],
        ];
    }

    /** @return array<string, mixed> */
    public function guide(ParliamentaryAmendment $amendment, ?MunicipalWorkPlan $plan, ?array $readiness): array
    {
        if (! $plan) {
            return [
                'next' => [
                    'icon' => 'clipboard-list',
                    'title' => 'Iniciar Plano de Trabalho guiado',
                    'description' => 'O sistema monta beneficiario, objeto, valor, saude e cronograma inicial a partir da proposta.',
                    'href' => '#iniciar-plano',
                    'label' => 'Iniciar plano guiado',
                ],
                'steps' => $this->guideSteps(false, false, false, false, false),
                'documents' => $this->requiredDocuments($amendment, null),
                'risks' => ['O Executivo ainda nao iniciou o Plano de Trabalho desta emenda.'],
                'responsibles' => $this->responsibles($amendment, 'Gestor municipal'),
            ];
        }

        $ready = (bool) ($readiness['ready'] ?? false);
        $scheduleReady = $plan->stages->isNotEmpty()
            && abs((float) ($readiness['difference'] ?? 1)) < 0.01
            && (float) ($readiness['planned_amount'] ?? 0) > 0;
        $coreReady = filled($plan->beneficiary_name)
            && filled($plan->beneficiary_contact)
            && filled($plan->object_description)
            && filled($plan->public_need)
            && filled($plan->physical_target)
            && filled($plan->finalistic_target);
        $technicalReady = $plan->pca_status !== 'not_checked'
            && (! $plan->health_related || $plan->health_reserve_verified)
            && (! $plan->includes_engineering || $plan->engineering_project_status !== 'pending');

        $next = [
            'icon' => 'list-checks',
            'title' => 'Completar dados pendentes',
            'description' => 'Revise os campos destacados, confirme documentos minimos e salve o plano antes do envio.',
            'href' => '#dados-plano',
            'label' => 'Revisar pendencias',
        ];

        if ($ready && $plan->isEditable()) {
            $next = [
                'icon' => 'send',
                'title' => 'Enviar para analise tecnica',
                'description' => 'O plano esta pronto para parecer de admissibilidade e sera bloqueado durante a avaliacao.',
                'href' => '#enviar-analise',
                'label' => 'Enviar para analise',
            ];
        } elseif ($plan->status === MunicipalWorkPlan::STATUS_UNDER_REVIEW) {
            $next = [
                'icon' => 'badge-check',
                'title' => 'Emitir parecer de admissibilidade',
                'description' => 'O plano esta sob responsabilidade do gestor para aprovar, devolver ou rejeitar formalmente.',
                'href' => '#parecer',
                'label' => 'Ver parecer',
            ];
        } elseif ($plan->status === MunicipalWorkPlan::STATUS_APPROVED) {
            $next = [
                'icon' => 'route',
                'title' => 'Avancar para execucao',
                'description' => 'Com o plano aprovado, o proximo controle e acompanhar entregas, pagamentos e documentos.',
                'href' => route('emendas.execution', $amendment),
                'label' => 'Abrir execucao',
            ];
        }

        return [
            'next' => $next,
            'steps' => $this->guideSteps(true, $coreReady, $scheduleReady, $technicalReady, $ready || ! $plan->isEditable()),
            'documents' => $this->requiredDocuments($amendment, $plan),
            'risks' => $this->guideRisks($amendment, $plan, $readiness ?? []),
            'responsibles' => $this->responsibles($amendment, $next['title']),
        ];
    }

    /**
     * @return array{score: int, ready: bool, completed: int, total: int, blockers: array<int, string>, warnings: array<int, string>, planned_amount: float, difference: float}
     */
    public function readiness(MunicipalWorkPlan $plan, ParliamentaryAmendment $amendment): array
    {
        $plan->loadMissing('stages');
        $checks = [
            ['ok' => filled($plan->beneficiary_type) && filled($plan->beneficiary_name), 'message' => 'Identifique o tipo e o nome do beneficiário ou órgão executor.'],
            ['ok' => $plan->beneficiary_type === 'municipal_body' || filled($plan->beneficiary_cnpj), 'message' => 'Informe o CNPJ do beneficiário externo.'],
            ['ok' => filled($plan->beneficiary_contact), 'message' => 'Informe o contato do beneficiário ou órgão executor.'],
            ['ok' => filled($plan->object_description), 'message' => 'Detalhe o objeto do plano de trabalho.'],
            ['ok' => filled($plan->public_need), 'message' => 'Justifique a necessidade pública atendida.'],
            ['ok' => filled($plan->physical_target), 'message' => 'Defina a meta física verificável.'],
            ['ok' => filled($plan->finalistic_target), 'message' => 'Defina o resultado finalístico esperado.'],
            ['ok' => filled($plan->budget_program) && filled($plan->budget_action), 'message' => 'Informe o programa e a ação orçamentária.'],
            ['ok' => filled($plan->application_plan), 'message' => 'Descreva o plano de aplicação dos recursos.'],
            ['ok' => filled($plan->cost_memory), 'message' => 'Apresente a memória de cálculo dos custos.'],
            ['ok' => filled($plan->maintenance_plan), 'message' => 'Demonstre como ocorrerão operação e manutenção após a entrega.'],
            ['ok' => $plan->planned_start_at !== null && $plan->planned_end_at !== null, 'message' => 'Informe o período planejado de execução.'],
            ['ok' => $plan->pca_status !== 'not_checked', 'message' => 'Registre a situação do objeto no Plano de Contratações Anual.'],
            ['ok' => ! $plan->health_related || $plan->health_reserve_verified, 'message' => 'Confirme a verificação da reserva da saúde.'],
            ['ok' => $plan->stages->isNotEmpty(), 'message' => 'Cadastre ao menos uma etapa físico-financeira.'],
        ];

        $plannedAmount = round((float) $plan->stages->sum('planned_amount'), 2);
        $expectedAmount = round((float) $amendment->expected_amount, 2);
        $difference = round($expectedAmount - $plannedAmount, 2);
        $checks[] = [
            'ok' => $plan->stages->isNotEmpty() && abs($difference) < 0.01,
            'message' => 'O total das etapas deve ser igual ao valor previsto da emenda.',
        ];

        if ($plan->planned_start_at && $plan->planned_end_at) {
            $checks[] = [
                'ok' => $plan->stages->every(fn ($stage) => $stage->planned_start_at->greaterThanOrEqualTo($plan->planned_start_at)
                    && $stage->planned_end_at->lessThanOrEqualTo($plan->planned_end_at)),
                'message' => 'As datas das etapas devem estar dentro do período geral do plano.',
            ];
        }

        $blockers = collect($checks)->where('ok', false)->pluck('message')->values()->all();
        $warnings = [];

        if ($plan->includes_engineering && $plan->engineering_project_status === 'pending') {
            $warnings[] = 'O projeto de engenharia está pendente e deverá ser tratado como cláusula suspensiva ou possível impedimento temporário.';
        }
        if ($plan->includes_engineering && $plan->environmental_license_status === 'pending') {
            $warnings[] = 'A licença ambiental está pendente e exige acompanhamento antes da execução.';
        }
        if ($plan->pca_status === 'update_requested') {
            $warnings[] = 'A atualização do PCA cabe ao Executivo; a ausência atual não cancela automaticamente a emenda.';
        }

        return [
            'score' => (int) round(((count($checks) - count($blockers)) / count($checks)) * 100),
            'ready' => $blockers === [],
            'completed' => count($checks) - count($blockers),
            'total' => count($checks),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'planned_amount' => $plannedAmount,
            'difference' => $difference,
        ];
    }

    /** @return array<int, array{label: string, description: string, done: bool}> */
    private function guideSteps(bool $started, bool $core, bool $schedule, bool $technical, bool $submitted): array
    {
        return [
            ['label' => 'Plano iniciado', 'description' => 'Dados basicos criados pelo Executivo.', 'done' => $started],
            ['label' => 'Objeto e metas', 'description' => 'Beneficiario, necessidade e resultado esperado.', 'done' => $core],
            ['label' => 'Cronograma e valor', 'description' => 'Etapas batem com o valor reservado.', 'done' => $schedule],
            ['label' => 'Conferencia tecnica', 'description' => 'Saude, PCA, engenharia e licencas tratados.', 'done' => $technical],
            ['label' => 'Envio formal', 'description' => 'Plano enviado para parecer municipal.', 'done' => $submitted],
        ];
    }

    /** @return array<int, string> */
    private function requiredDocuments(ParliamentaryAmendment $amendment, ?MunicipalWorkPlan $plan): array
    {
        $documents = [
            'Plano de Trabalho revisado pelo Executivo',
            'Memoria de calculo ou pesquisa de precos',
            'Cronograma fisico-financeiro',
            'Identificacao do beneficiario ou orgao executor',
        ];

        if (($plan?->health_related ?? false) || $amendment->indicated_for_health) {
            $documents[] = 'Comprovacao da reserva e enquadramento em saude';
        }

        if ($plan?->includes_engineering) {
            $documents[] = 'Projeto, orcamento, ART/RRT e licencas aplicaveis';
        }

        return $documents;
    }

    /** @return array<int, string> */
    private function guideRisks(ParliamentaryAmendment $amendment, MunicipalWorkPlan $plan, array $readiness): array
    {
        $risks = [];

        if (($readiness['difference'] ?? 0) != 0) {
            $risks[] = 'O total das etapas ainda nao fecha com o valor da emenda.';
        }
        if ($plan->pca_status === 'update_requested') {
            $risks[] = 'O objeto precisa ser encaminhado para atualizacao do PCA pelo Executivo.';
        }
        if ($plan->health_related && ! $plan->health_reserve_verified) {
            $risks[] = 'A emenda marcada como saude ainda nao teve reserva confirmada.';
        }
        if ($plan->includes_engineering && $plan->engineering_project_status === 'pending') {
            $risks[] = 'Ha obra ou engenharia com projeto pendente antes da execucao.';
        }
        if (Str::length(trim((string) $amendment->object)) < 35) {
            $risks[] = 'O objeto original e curto; detalhe bem a entrega para evitar devolucao.';
        }

        return $risks ?: ['Sem risco critico identificado pelo preenchimento atual.'];
    }

    /** @return array<int, array{label: string, value: string}> */
    private function responsibles(ParliamentaryAmendment $amendment, string $nextOwner): array
    {
        return [
            ['label' => 'Unidade executora', 'value' => $amendment->responsible_department ?: 'A confirmar pelo gestor'],
            ['label' => 'Responsavel operacional', 'value' => $amendment->responsibleUser?->name ?: 'Nao definido'],
            ['label' => 'Proxima acao', 'value' => $nextOwner],
        ];
    }

    public function ensureReadyForSubmission(MunicipalWorkPlan $plan, ParliamentaryAmendment $amendment): void
    {
        $readiness = $this->readiness($plan, $amendment);

        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'work_plan' => 'O plano ainda não pode ser enviado: '.implode(' ', $readiness['blockers']),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(MunicipalWorkPlan $plan, ParliamentaryAmendment $amendment): array
    {
        $plan->loadMissing('stages');

        return [
            'amendment' => $amendment->only([
                'reference', 'fiscal_year', 'author_name', 'author_party', 'object', 'expected_amount',
            ]),
            'plan' => $plan->only([
                'revision_number', 'beneficiary_type', 'beneficiary_name', 'beneficiary_cnpj',
                'beneficiary_contact', 'object_description', 'public_need', 'physical_target',
                'finalistic_target', 'budget_program', 'budget_action', 'application_plan',
                'cost_memory', 'maintenance_plan', 'health_related', 'health_reserve_verified',
                'includes_engineering', 'engineering_project_status', 'environmental_license_status',
                'pca_status', 'planned_start_at', 'planned_end_at', 'submitted_at',
            ]),
            'stages' => $plan->stages->map(fn ($stage) => $stage->only([
                'title', 'physical_delivery', 'planned_amount', 'planned_start_at', 'planned_end_at', 'sort_order',
            ]))->values()->all(),
        ];
    }
}
