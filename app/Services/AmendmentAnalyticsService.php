<?php

namespace App\Services;

use App\Models\AccountabilityProcess;
use App\Models\FinancialCommitment;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AmendmentAnalyticsService
{
    /** @param array<string, mixed> $filters */
    public function amendments(Municipality $municipality, array $filters = []): Collection
    {
        return $this->filteredQuery($municipality, $filters)
            ->with([
                'responsibleUser',
                'executionStages',
                'financialCommitments.payments',
                'municipalWorkPlan.stages',
                'accountabilityProcess.requirements',
                'accountabilityProcess.diligences',
            ])
            ->latest('fiscal_year')
            ->latest('id')
            ->get();
    }

    /** @param array<string, mixed> $filters */
    public function filteredQuery(Municipality $municipality, array $filters = []): Builder
    {
        $year = (string) ($filters['year'] ?? '');
        $sphere = (string) ($filters['sphere'] ?? '');
        $status = (string) ($filters['status'] ?? '');
        $department = trim((string) ($filters['department'] ?? ''));

        return $municipality->amendments()->getQuery()
            ->when(ctype_digit($year), fn (Builder $query) => $query->where('fiscal_year', (int) $year))
            ->when(array_key_exists($sphere, ParliamentaryAmendment::governmentSpheres()), fn (Builder $query) => $query->where('government_sphere', $sphere))
            ->when(array_key_exists($status, ParliamentaryAmendment::statuses()), fn (Builder $query) => $query->where('status', $status))
            ->when($department !== '', fn (Builder $query) => $query->where('responsible_department', $department));
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function dashboard(Municipality $municipality, array $filters = []): array
    {
        $amendments = $this->amendments($municipality, $filters);
        $expected = $amendments->sum(fn (ParliamentaryAmendment $item) => (float) $item->expected_amount);
        $received = $amendments->sum(fn (ParliamentaryAmendment $item) => (float) $item->received_amount);
        $committed = $amendments->sum(fn (ParliamentaryAmendment $item) => $this->committedAmount($item));
        $paid = $amendments->sum(fn (ParliamentaryAmendment $item) => $this->paidAmount($item));
        $physicalExecution = $amendments->isEmpty()
            ? 0
            : (int) round($amendments->average(fn (ParliamentaryAmendment $item) => $item->physicalExecutionPercentage()));
        $highRisk = $amendments->whereIn('risk_level', [
            ParliamentaryAmendment::RISK_HIGH,
            ParliamentaryAmendment::RISK_CRITICAL,
        ])->count();
        $overdue = $amendments->filter->hasOverdueDeadline()->count();
        $approvedAccountability = $amendments->filter(fn (ParliamentaryAmendment $item) => $item->accountabilityProcess?->status === AccountabilityProcess::STATUS_APPROVED
        )->count();

        return [
            'amendments' => $amendments,
            'summary' => [
                'count' => $amendments->count(),
                'expected' => $expected,
                'received' => $received,
                'committed' => $committed,
                'paid' => $paid,
                'to_receive' => max(0, $expected - $received),
                'available' => max(0, $received - $paid),
                'receipt_rate' => $this->percentage($received, $expected),
                'payment_rate' => $this->percentage($paid, $received),
                'physical_execution' => $physicalExecution,
                'overdue' => $overdue,
                'high_risk' => $highRisk,
                'accountability_approved' => $approvedAccountability,
                'data_quality' => $this->dataQuality($amendments),
            ],
            'charts' => [
                'financial' => [
                    'labels' => ['Previsto', 'Recebido', 'Empenhado', 'Pago'],
                    'values' => [round($expected, 2), round($received, 2), round($committed, 2), round($paid, 2)],
                ],
                'statuses' => $this->distribution($amendments, 'status', ParliamentaryAmendment::statuses()),
                'risks' => $this->distribution($amendments, 'risk_level', [
                    ParliamentaryAmendment::RISK_LOW => 'Baixo',
                    ParliamentaryAmendment::RISK_MODERATE => 'Moderado',
                    ParliamentaryAmendment::RISK_HIGH => 'Alto',
                    ParliamentaryAmendment::RISK_CRITICAL => 'Crítico',
                ]),
                'departments' => $this->rankedAmounts($amendments, 'responsible_department'),
                'authors' => $this->rankedAmounts($amendments, 'author_name'),
            ],
            'insights' => $this->insights($amendments, $expected, $received, $paid, $overdue, $highRisk),
            'attention' => $amendments
                ->filter(fn (ParliamentaryAmendment $item) => $item->hasOverdueDeadline()
                    || in_array($item->risk_level, [ParliamentaryAmendment::RISK_HIGH, ParliamentaryAmendment::RISK_CRITICAL], true)
                    || $item->responsible_user_id === null)
                ->sortByDesc(fn (ParliamentaryAmendment $item) => ($item->hasOverdueDeadline() ? 1000 : 0) + (int) $item->risk_score)
                ->take(6)
                ->values(),
        ];
    }

    /** @return array{years: Collection, departments: Collection} */
    public function filterOptions(Municipality $municipality): array
    {
        return [
            'years' => $municipality->amendments()->select('fiscal_year')->distinct()->orderByDesc('fiscal_year')->pluck('fiscal_year'),
            'departments' => $municipality->amendments()->whereNotNull('responsible_department')->select('responsible_department')->distinct()->orderBy('responsible_department')->pluck('responsible_department'),
        ];
    }

    public function committedAmount(ParliamentaryAmendment $amendment): float
    {
        return (float) $amendment->financialCommitments
            ->where('status', FinancialCommitment::STATUS_ACTIVE)
            ->sum('committed_amount');
    }

    public function paidAmount(ParliamentaryAmendment $amendment): float
    {
        return (float) $amendment->financialCommitments
            ->where('status', FinancialCommitment::STATUS_ACTIVE)
            ->sum(fn ($commitment) => $commitment->payments->sum('amount'));
    }

    private function percentage(float $value, float $total): int
    {
        return $total <= 0 ? 0 : (int) min(100, round(($value / $total) * 100));
    }

    private function dataQuality(Collection $amendments): int
    {
        if ($amendments->isEmpty()) {
            return 0;
        }

        $score = $amendments->sum(function (ParliamentaryAmendment $item): int {
            return collect([
                filled($item->responsible_department),
                $item->responsible_user_id !== null,
                filled($item->expected_amount),
                $item->indicated_at !== null,
                $item->execution_deadline !== null,
                $item->accountability_deadline !== null,
            ])->filter()->count();
        });

        return (int) round(($score / ($amendments->count() * 6)) * 100);
    }

    /** @param array<string, string> $labels @return array{labels: array<int, string>, values: array<int, int>} */
    private function distribution(Collection $amendments, string $field, array $labels): array
    {
        $counts = $amendments->countBy($field);
        $present = collect($labels)->filter(fn (string $label, string $key) => (int) $counts->get($key, 0) > 0);

        return [
            'labels' => $present->values()->all(),
            'values' => $present->keys()->map(fn (string $key) => (int) $counts->get($key, 0))->all(),
        ];
    }

    /** @return array{labels: array<int, string>, values: array<int, float>} */
    private function rankedAmounts(Collection $amendments, string $field): array
    {
        $ranked = $amendments
            ->groupBy(fn (ParliamentaryAmendment $item) => filled($item->{$field}) ? $item->{$field} : 'Não informado')
            ->map(fn (Collection $items) => round($items->sum(fn (ParliamentaryAmendment $item) => (float) $item->expected_amount), 2))
            ->sortDesc()
            ->take(6);

        return ['labels' => $ranked->keys()->all(), 'values' => $ranked->values()->all()];
    }

    /** @return array<int, array{tone: string, title: string, message: string}> */
    private function insights(Collection $amendments, float $expected, float $received, float $paid, int $overdue, int $highRisk): array
    {
        if ($amendments->isEmpty()) {
            return [[
                'tone' => 'neutral',
                'title' => 'Base ainda vazia',
                'message' => 'Cadastre emendas para formar a primeira leitura gerencial.',
            ]];
        }

        $withoutResponsible = $amendments->whereNull('responsible_user_id')->count();
        $topAuthor = $amendments->groupBy('author_name')
            ->map(fn (Collection $items) => $items->sum('expected_amount'))
            ->sortDesc()
            ->first() ?? 0;
        $concentration = $this->percentage((float) $topAuthor, $expected);

        $insights = [];
        if ($overdue > 0) {
            $insights[] = ['tone' => 'danger', 'title' => 'Prazo exige ação', 'message' => "{$overdue} emenda(s) possuem marco vencido e ainda não concluído."];
        }
        if ($highRisk > 0) {
            $insights[] = ['tone' => 'warning', 'title' => 'Risco concentrado', 'message' => "{$highRisk} emenda(s) estão em risco alto ou crítico."];
        }
        if ($withoutResponsible > 0) {
            $insights[] = ['tone' => 'warning', 'title' => 'Responsabilidade indefinida', 'message' => "{$withoutResponsible} emenda(s) ainda não possuem responsável operacional."];
        }
        if ($expected > $received) {
            $insights[] = ['tone' => 'info', 'title' => 'Recursos a receber', 'message' => 'Ainda faltam R$ '.number_format($expected - $received, 2, ',', '.').' para atingir o valor previsto.'];
        }
        if ($received > 0) {
            $insights[] = ['tone' => 'success', 'title' => 'Conversão financeira', 'message' => $this->percentage($paid, $received).'% dos recursos recebidos já foram pagos em despesas registradas.'];
        }
        if ($concentration >= 50) {
            $insights[] = ['tone' => 'info', 'title' => 'Concentração de origem', 'message' => "O principal autor representa {$concentration}% do valor previsto no recorte."];
        }

        return array_slice($insights, 0, 5);
    }
}
