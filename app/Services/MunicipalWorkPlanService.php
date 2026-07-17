<?php

namespace App\Services;

use App\Models\MunicipalWorkPlan;
use App\Models\ParliamentaryAmendment;
use Illuminate\Validation\ValidationException;

class MunicipalWorkPlanService
{
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
