<?php

namespace App\Services;

use App\Models\MunicipalAuditPlan;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use Illuminate\Support\Collection;

class MunicipalAuditPlanService
{
    /** @return Collection<int, array{amendment: ParliamentaryAmendment, score: int, reasons: array<int, string>}> */
    public function recommendations(Municipality $municipality, MunicipalAuditPlan $plan): Collection
    {
        $plannedIds = $plan->items()->pluck('parliamentary_amendment_id');

        return $municipality->amendments()
            ->where('government_sphere', 'municipal')
            ->whereNotIn('id', $plannedIds)
            ->withCount(['internalControlReviews', 'integrityAlerts as open_alerts_count' => fn ($query) => $query->where('status', 'open')])
            ->get()
            ->map(function (ParliamentaryAmendment $amendment): array {
                $score = (int) $amendment->risk_score;
                $reasons = [];
                if ($amendment->internal_control_reviews_count === 0) {
                    $score += 25;
                    $reasons[] = 'Sem parecer do Controle Interno';
                }
                if ($amendment->open_alerts_count > 0) {
                    $score += min(20, $amendment->open_alerts_count * 5);
                    $reasons[] = $amendment->open_alerts_count.' alerta(s) ativo(s)';
                }
                if ((float) $amendment->expected_amount >= 1000000) {
                    $score += 15;
                    $reasons[] = 'Materialidade acima de R$ 1 milhão';
                }
                if (in_array($amendment->status, ['resource_received', 'executing', 'accountability_pending'], true)) {
                    $score += 10;
                    $reasons[] = 'Execução financeira iniciada';
                }

                return ['amendment' => $amendment, 'score' => min(100, $score), 'reasons' => $reasons ?: ['Seleção discricionária da unidade']];
            })
            ->sortByDesc('score')
            ->values();
    }

    /** @return array<int, string> */
    public function readiness(MunicipalAuditPlan $plan): array
    {
        $blockers = [];
        if ($plan->items()->count() === 0) {
            $blockers[] = 'Inclua ao menos uma emenda na agenda de auditoria.';
        }
        if ($plan->planned_start_at->year !== $plan->fiscal_year || $plan->planned_end_at->year !== $plan->fiscal_year) {
            $blockers[] = 'O período do plano deve permanecer dentro do exercício selecionado.';
        }
        if ($plan->items()->where(function ($query) use ($plan): void {
            $query->whereDate('planned_at', '<', $plan->planned_start_at)
                ->orWhereDate('planned_at', '>', $plan->planned_end_at);
        })->exists()) {
            $blockers[] = 'Todos os itens devem estar agendados dentro do período do plano.';
        }

        return $blockers;
    }

    /** @return array<string, mixed> */
    public function snapshot(MunicipalAuditPlan $plan): array
    {
        $plan->loadMissing(['municipality:id,name,state,ibge_code,cnpj', 'items.amendment', 'items.assignedUser:id,name,email']);

        return [
            'captured_at' => now()->toIso8601String(),
            'reference' => $plan->reference(),
            'municipality' => $plan->municipality->only(['id', 'name', 'state', 'ibge_code', 'cnpj']),
            'plan' => $plan->only([
                'fiscal_year', 'version', 'title', 'objective', 'methodology', 'risk_criteria',
                'normative_basis', 'coordination_unit', 'planned_start_at', 'planned_end_at',
                'management_notes',
            ]),
            'items' => $plan->items->map(fn ($item) => [
                'id' => $item->id,
                'amendment' => $item->amendment->only(['id', 'reference', 'object', 'author_name', 'expected_amount', 'status', 'risk_score', 'risk_level']),
                'assigned_user' => $item->assignedUser->only(['id', 'name', 'email']),
                ...$item->only(['phase', 'priority', 'frequency', 'planned_at', 'scope_notes']),
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
