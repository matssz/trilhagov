<?php

namespace App\Services;

use App\Models\AmendmentComplianceReview;
use App\Models\Municipality;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\ParliamentaryAmendment;
use Illuminate\Support\Collection;

class MunicipalTcespAdherenceService
{
    public function __construct(
        private readonly TcespComplianceFramework $framework,
        private readonly MunicipalRegulatoryReadiness $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(Municipality $municipality, int $year, bool $canEdit = false): array
    {
        $activeProfile = $municipality->regulatoryProfiles()
            ->with('instruments')
            ->where('fiscal_year', $year)
            ->where('status', MunicipalRegulatoryProfile::STATUS_ACTIVE)
            ->latest('version')
            ->first();

        $draftProfile = $municipality->regulatoryProfiles()
            ->with('instruments')
            ->where('fiscal_year', $year)
            ->where('status', MunicipalRegulatoryProfile::STATUS_DRAFT)
            ->latest('version')
            ->first();

        $profile = $activeProfile ?? $draftProfile;
        $diagnostic = $profile ? $this->readiness->evaluate($profile) : null;
        $amendments = $this->tcespAmendments($municipality, $year)->get();
        $matrix = $this->portfolioMatrix($amendments);
        $summary = $this->summary($matrix, $amendments->count(), $activeProfile !== null, $diagnostic);

        return [
            'year' => $year,
            'profile' => $profile,
            'activeProfile' => $activeProfile,
            'diagnostic' => $diagnostic,
            'amendments' => $amendments,
            'matrix' => $matrix,
            'groupedMatrix' => $matrix->groupBy('category'),
            'summary' => $summary,
            'categories' => $this->framework->categories(),
            'sourceLabel' => TcespComplianceFramework::SOURCE_LABEL,
            'sourceUrl' => TcespComplianceFramework::SOURCE_URL,
            'frameworkVersion' => TcespComplianceFramework::VERSION,
            'nextActions' => $this->nextActions($municipality, $year, $summary, $activeProfile, $diagnostic, $amendments, $matrix, $canEdit),
        ];
    }

    /** @return Collection<int, int> */
    public function availableYears(Municipality $municipality): Collection
    {
        return $municipality->amendments()
            ->where('government_sphere', 'municipal')
            ->select('fiscal_year')
            ->distinct()
            ->pluck('fiscal_year')
            ->merge($municipality->regulatoryProfiles()->select('fiscal_year')->distinct()->pluck('fiscal_year'))
            ->push(now()->year)
            ->push(now()->year + 1)
            ->unique()
            ->sortDesc()
            ->values();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ParliamentaryAmendment> */
    private function tcespAmendments(Municipality $municipality, int $year)
    {
        return $municipality->amendments()
            ->with(['complianceReviews', 'responsibleUser'])
            ->where('government_sphere', 'municipal')
            ->where('fiscal_year', $year)
            ->whereHas('municipality', fn ($query) => $query
                ->where('state', 'SP')
                ->where('ibge_code', '!=', '3550308'))
            ->latest('created_at')
            ->latest('id');
    }

    /** @param Collection<int, ParliamentaryAmendment> $amendments @return Collection<int, array<string, mixed>> */
    private function portfolioMatrix(Collection $amendments): Collection
    {
        $total = $amendments->count();

        return collect($this->framework->rules())->map(function (array $rule) use ($amendments, $total): array {
            $reviews = $amendments
                ->flatMap(fn (ParliamentaryAmendment $amendment) => $amendment->complianceReviews
                    ->where('framework_version', TcespComplianceFramework::VERSION)
                    ->where('rule_code', $rule['code']));
            $counts = $reviews->countBy('status');
            $notApplicable = (int) $counts->get(AmendmentComplianceReview::STATUS_NOT_APPLICABLE, 0);
            $applicable = max(0, $total - $notApplicable);
            $compliant = (int) $counts->get(AmendmentComplianceReview::STATUS_COMPLIANT, 0);
            $nonCompliant = (int) $counts->get(AmendmentComplianceReview::STATUS_NON_COMPLIANT, 0);
            $pending = max(0, $total - $reviews->count());
            $reviewPending = (int) $counts->get(AmendmentComplianceReview::STATUS_PENDING, 0);
            $open = $pending + $reviewPending + $nonCompliant;
            $percentage = $applicable > 0 ? (int) round(($compliant / $applicable) * 100) : 0;
            $state = $total === 0
                ? 'not_started'
                : ($open > 0 ? ($nonCompliant > 0 ? 'attention' : 'pending') : 'covered');

            return [
                ...$rule,
                'total' => $total,
                'applicable' => $applicable,
                'compliant' => $compliant,
                'non_compliant' => $nonCompliant,
                'not_applicable' => $notApplicable,
                'pending' => $pending + $reviewPending,
                'open' => $open,
                'percentage' => $percentage,
                'state' => $state,
            ];
        });
    }

    /** @param Collection<int, array<string, mixed>> $matrix @param array<string, mixed>|null $diagnostic @return array<string, mixed> */
    private function summary(Collection $matrix, int $amendmentsCount, bool $hasActiveProfile, ?array $diagnostic): array
    {
        $totalChecks = $matrix->sum(fn (array $item) => $item['applicable']);
        $compliant = $matrix->sum(fn (array $item) => $item['compliant']);
        $nonCompliant = $matrix->sum(fn (array $item) => $item['non_compliant']);
        $pending = $matrix->sum(fn (array $item) => $item['pending']);
        $coveredRules = $matrix->where('state', 'covered')->count();
        $criticalOpen = $matrix
            ->filter(fn (array $item) => $item['critical'] && $item['open'] > 0)
            ->count();
        $normativeReady = $hasActiveProfile && empty($diagnostic['blockers'] ?? []);
        $portfolioReady = $amendmentsCount > 0 && $criticalOpen === 0 && $nonCompliant === 0 && $pending === 0;
        $scorePieces = [
            $normativeReady ? 35 : 0,
            $amendmentsCount > 0 ? 15 : 0,
            $totalChecks > 0 ? (int) round(($compliant / $totalChecks) * 50) : 0,
        ];

        return [
            'score' => array_sum($scorePieces),
            'amendments_count' => $amendmentsCount,
            'rules_total' => $matrix->count(),
            'covered_rules' => $coveredRules,
            'critical_open' => $criticalOpen,
            'total_checks' => $totalChecks,
            'compliant' => $compliant,
            'non_compliant' => $nonCompliant,
            'pending' => $pending,
            'normative_ready' => $normativeReady,
            'portfolio_ready' => $portfolioReady,
            'status_label' => $normativeReady && $portfolioReady ? 'Pronto para auditoria' : 'Aderência em preparação',
        ];
    }

    /**
     * @param Collection<int, ParliamentaryAmendment> $amendments
     * @param Collection<int, array<string, mixed>> $matrix
     * @param array<string, mixed>|null $diagnostic
     * @return array<int, array{title: string, description: string, route: string|null, label: string, icon: string}>
     */
    private function nextActions(
        Municipality $municipality,
        int $year,
        array $summary,
        ?MunicipalRegulatoryProfile $activeProfile,
        ?array $diagnostic,
        Collection $amendments,
        Collection $matrix,
        bool $canEdit,
    ): array {
        $actions = [];

        if (! $activeProfile || ! empty($diagnostic['blockers'] ?? [])) {
            $actions[] = [
                'title' => 'Ativar normas do exercício',
                'description' => 'Sem regra vigente, a Câmara não deve cadastrar indicações e a cota não fica auditável.',
                'route' => route('municipal-rules.index', ['ano' => $year]),
                'label' => 'Abrir normas',
                'icon' => 'landmark',
            ];
        }

        if ($canEdit && $amendments->isEmpty()) {
            $actions[] = [
                'title' => 'Registrar a primeira emenda municipal',
                'description' => 'A matriz do manual só mede cobertura quando existe emenda municipal no exercício selecionado.',
                'route' => route('emendas.create'),
                'label' => 'Nova emenda',
                'icon' => 'plus',
            ];
        }

        if (($summary['critical_open'] ?? 0) > 0 || ($summary['pending'] ?? 0) > 0) {
            $rule = $matrix
                ->first(fn (array $item) => $item['critical'] && $item['open'] > 0)
                ?? $matrix->first(fn (array $item) => $item['open'] > 0);
            $amendment = $amendments->first();

            $actions[] = [
                'title' => 'Revisar itens pendentes do manual',
                'description' => $rule
                    ? "Comece por {$rule['code']} - {$rule['title']}."
                    : 'Complete as evidências de conformidade das emendas municipais.',
                'route' => $amendment ? route('emendas.compliance', $amendment) : null,
                'label' => 'Abrir matriz',
                'icon' => 'clipboard-check',
            ];
        }

        if (($summary['non_compliant'] ?? 0) > 0) {
            $actions[] = [
                'title' => 'Sanear itens não atendidos',
                'description' => 'Registre providência, documento ou justificativa antes de fechar o dossiê municipal.',
                'route' => route('work-center.index'),
                'label' => 'Central de Trabalho',
                'icon' => 'shield-alert',
            ];
        }

        return array_slice($actions, 0, 4);
    }
}
