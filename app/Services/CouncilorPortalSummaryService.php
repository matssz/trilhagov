<?php

namespace App\Services;

use App\Models\LegislativeProposal;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\Municipality;

class CouncilorPortalSummaryService
{
    /**
     * @param array<string, mixed>|null $quota
     * @return array<string, mixed>
     */
    public function build(
        Municipality $municipality,
        int $userId,
        int $year,
        ?MunicipalRegulatoryProfile $profile,
        ?array $quota,
    ): array {
        $statusBase = $municipality->legislativeProposals()
            ->where('fiscal_year', $year)
            ->where('submitted_by', $userId);
        $statusCounts = [];
        foreach (array_keys(LegislativeProposal::statuses()) as $status) {
            $statusCounts[$status] = (clone $statusBase)->where('status', $status)->count();
        }

        $remaining = $quota['remaining'] ?? null;
        $countLimit = $quota['count_limit'] ?? null;
        $count = (int) ($quota['count'] ?? 0);
        $quotaUsed = (float) ($quota['used'] ?? 0);
        $quotaCeiling = $quota['author_ceiling'] ?? null;
        $healthAllocated = (float) ($quota['health_allocated'] ?? 0);
        $healthRequired = $quota['health_required'] ?? null;
        $healthGap = $quota['health_gap'] ?? null;
        $minimumAmount = $profile?->minimum_amendment_amount === null ? null : (float) $profile->minimum_amendment_amount;

        $canCreate = $profile !== null
            && ($remaining === null || (float) $remaining > 0.005)
            && ($minimumAmount === null || $remaining === null || (float) $remaining + 0.005 >= $minimumAmount)
            && ($countLimit === null || $count < (int) $countLimit);
        $quotaProgress = $quotaCeiling ? min(100, round($quotaUsed / (float) $quotaCeiling * 100)) : null;
        $healthProgress = $healthRequired ? min(100, round($healthAllocated / (float) $healthRequired * 100)) : null;

        [$nextTitle, $nextText, $badge, $badgeTone] = $this->nextAction(
            $profile,
            $remaining,
            $countLimit,
            $count,
            $minimumAmount,
            $healthGap,
        );

        return [
            'canCreate' => $canCreate,
            'quotaProgress' => $quotaProgress,
            'healthProgress' => $healthProgress,
            'nextTitle' => $nextTitle,
            'nextText' => $nextText,
            'badge' => $badge,
            'badgeTone' => $badgeTone,
            'simpleCards' => $this->simpleCards($remaining, $healthGap, $nextTitle, $nextText, $badgeTone),
            'plainChecklist' => $this->plainChecklist($profile, $remaining, $healthGap, $canCreate, $badge),
            'mandateCards' => $this->mandateCards($quota, $healthGap, $count, $countLimit),
            'timeline' => $this->timeline($year, $statusCounts),
            'alerts' => $this->alerts($profile, $statusCounts, $remaining, $healthGap, $canCreate),
            'statusCounts' => $statusCounts,
        ];
    }

    /**
     * @return array{string, string, string, string}
     */
    private function nextAction(
        ?MunicipalRegulatoryProfile $profile,
        mixed $remaining,
        mixed $countLimit,
        int $count,
        ?float $minimumAmount,
        mixed $healthGap,
    ): array {
        if ($profile === null) {
            return [
                'Aguardando ativação do exercício',
                'O gestor municipal precisa ativar a norma para liberar cota, reserva de saúde e cadastro de propostas.',
                'Bloqueado',
                'danger',
            ];
        }

        if ($countLimit !== null && $count >= (int) $countLimit) {
            return [
                'Limite de propostas atingido',
                'Revise as propostas existentes ou aguarde orientação da equipe municipal antes de cadastrar outra.',
                'Cota encerrada',
                'warning',
            ];
        }

        if ($remaining !== null && (float) $remaining <= 0.005) {
            return [
                'Saldo individual esgotado',
                'Todas as novas indicações precisam respeitar o teto da Lei Orgânica definido para o exercício.',
                'Sem saldo',
                'warning',
            ];
        }

        if ($minimumAmount !== null && $remaining !== null && (float) $remaining + 0.005 < $minimumAmount) {
            return [
                'Saldo abaixo do mínimo',
                'A norma municipal exige proposta mínima de R$ '.number_format($minimumAmount, 2, ',', '.').', acima do saldo disponível.',
                'Sem valor mínimo',
                'warning',
            ];
        }

        if ($healthGap !== null && (float) $healthGap > 0.005) {
            return [
                'Priorize uma proposta de saúde',
                'Para protocolar sem bloqueio, direcione pelo menos R$ '.number_format((float) $healthGap, 2, ',', '.').' para saúde.',
                'Saúde pendente',
                'warning',
            ];
        }

        return [
            'Pronto para indicar',
            'Informe objeto, beneficiário, valor estimado e envie para conferência legislativa.',
            'Pode indicar',
            'success',
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function simpleCards(mixed $remaining, mixed $healthGap, string $nextTitle, string $nextText, string $badgeTone): array
    {
        return [
            [
                'icon' => 'wallet-cards',
                'label' => 'Saldo para novas propostas',
                'value' => $remaining === null ? 'A configurar' : 'R$ '.number_format((float) $remaining, 2, ',', '.'),
                'description' => 'Valor ainda livre dentro da sua cota individual.',
                'tone' => $remaining !== null && (float) $remaining <= 0.005 ? 'warning' : 'neutral',
            ],
            [
                'icon' => 'heart-pulse',
                'label' => 'Saúde obrigatória',
                'value' => $healthGap !== null && (float) $healthGap > 0.005
                    ? 'Faltam R$ '.number_format((float) $healthGap, 2, ',', '.')
                    : 'Em dia',
                'description' => 'O sistema calcula a reserva mínima pela norma municipal ativa.',
                'tone' => $healthGap !== null && (float) $healthGap > 0.005 ? 'warning' : 'success',
            ],
            [
                'icon' => 'file-plus-2',
                'label' => 'Seu próximo passo',
                'value' => $nextTitle,
                'description' => $nextText,
                'tone' => $badgeTone,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plainChecklist(
        ?MunicipalRegulatoryProfile $profile,
        mixed $remaining,
        mixed $healthGap,
        bool $canCreate,
        string $badge,
    ): array {
        return [
            [
                'label' => 'Saldo disponível',
                'done' => $remaining !== null && (float) $remaining > 0.005,
                'detail' => $remaining === null ? 'Aguardando norma ativa' : 'R$ '.number_format((float) $remaining, 2, ',', '.'),
            ],
            [
                'label' => 'Regra municipal ativa',
                'done' => $profile !== null,
                'detail' => $profile ? 'Exercício '.$profile->fiscal_year.' liberado' : 'Gestor precisa ativar',
            ],
            [
                'label' => 'Saúde calculada',
                'done' => $healthGap === null || (float) $healthGap <= 0.005,
                'detail' => $healthGap !== null && (float) $healthGap > 0.005 ? 'Priorize saúde' : 'Sem bloqueio',
            ],
            [
                'label' => 'Pode enviar',
                'done' => $canCreate,
                'detail' => $canCreate ? 'Formulário liberado' : $badge,
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $quota
     * @return array<int, array<string, string>>
     */
    private function mandateCards(?array $quota, mixed $healthGap, int $count, mixed $countLimit): array
    {
        return [
            [
                'label' => 'Cota individual',
                'value' => ($quota['author_ceiling'] ?? null) === null ? 'A configurar' : 'R$ '.number_format((float) $quota['author_ceiling'], 2, ',', '.'),
                'hint' => 'Limite do mandato no exercício.',
            ],
            [
                'label' => 'Saldo disponível',
                'value' => ($quota['remaining'] ?? null) === null ? 'A configurar' : 'R$ '.number_format((float) $quota['remaining'], 2, ',', '.'),
                'hint' => 'Quanto ainda pode virar proposta.',
            ],
            [
                'label' => 'Já indicado',
                'value' => 'R$ '.number_format((float) ($quota['used'] ?? 0), 2, ',', '.'),
                'hint' => $count.' de '.($countLimit ?? '∞').' proposta(s).',
            ],
            [
                'label' => 'Saúde',
                'value' => $healthGap !== null && (float) $healthGap > 0.005
                    ? 'Faltam R$ '.number_format((float) $healthGap, 2, ',', '.')
                    : 'Em dia',
                'hint' => 'Reserva mínima acompanhada automaticamente.',
            ],
        ];
    }

    /**
     * @param array<string, int> $statusCounts
     * @return array<int, array<string, mixed>>
     */
    private function timeline(int $year, array $statusCounts): array
    {
        return [
            [
                'label' => 'Rascunho ou ajuste',
                'count' => ($statusCounts[LegislativeProposal::STATUS_DRAFT] ?? 0) + ($statusCounts[LegislativeProposal::STATUS_RETURNED] ?? 0),
                'url' => route('legislative.index', ['year' => $year, 'status' => LegislativeProposal::STATUS_DRAFT]),
                'tone' => 'warning',
            ],
            [
                'label' => 'Com a Câmara',
                'count' => ($statusCounts[LegislativeProposal::STATUS_SUBMITTED] ?? 0) + ($statusCounts[LegislativeProposal::STATUS_APPROVED] ?? 0),
                'url' => route('legislative.index', ['year' => $year, 'status' => LegislativeProposal::STATUS_SUBMITTED]),
                'tone' => 'info',
            ],
            [
                'label' => 'Com o Executivo',
                'count' => ($statusCounts[LegislativeProposal::STATUS_SENT] ?? 0) + ($statusCounts[LegislativeProposal::STATUS_RECEIVED] ?? 0),
                'url' => route('legislative.index', ['year' => $year, 'status' => LegislativeProposal::STATUS_SENT]),
                'tone' => 'success',
            ],
            [
                'label' => 'Reservada',
                'count' => $statusCounts[LegislativeProposal::STATUS_RESERVED] ?? 0,
                'url' => route('legislative.index', ['year' => $year, 'status' => LegislativeProposal::STATUS_RESERVED]),
                'tone' => 'neutral',
            ],
        ];
    }

    /**
     * @param array<string, int> $statusCounts
     * @return array<int, array<string, string>>
     */
    private function alerts(
        ?MunicipalRegulatoryProfile $profile,
        array $statusCounts,
        mixed $remaining,
        mixed $healthGap,
        bool $canCreate,
    ): array {
        $alerts = [];

        if ($profile === null) {
            $alerts[] = [
                'title' => 'Exercício ainda não liberado',
                'text' => 'Aguarde o gestor municipal ativar as regras para cadastrar propostas.',
            ];
        }

        if (($statusCounts[LegislativeProposal::STATUS_RETURNED] ?? 0) > 0) {
            $alerts[] = [
                'title' => 'Há proposta devolvida para correção',
                'text' => 'Abra a proposta, ajuste os campos destacados e envie novamente para a Câmara.',
            ];
        }

        if ($healthGap !== null && (float) $healthGap > 0.005) {
            $alerts[] = [
                'title' => 'Saúde precisa ser priorizada',
                'text' => 'O saldo reservado para saúde ainda não atingiu o mínimo da norma municipal.',
            ];
        }

        if (! $canCreate && $remaining !== null && (float) $remaining <= 0.005) {
            $alerts[] = [
                'title' => 'Saldo esgotado',
                'text' => 'Novas propostas só devem seguir se houver orientação formal do Município.',
            ];
        }

        return $alerts;
    }
}
