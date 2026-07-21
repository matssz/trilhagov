<?php

namespace App\Services;

use App\Models\AccountabilityProcess;
use App\Models\MunicipalAuditProgram;
use App\Models\Municipality;
use App\Models\MunicipalOfficialDocument;
use App\Models\MunicipalSpecializedReport;
use App\Models\ParliamentaryAmendment;
use Illuminate\Support\Collection;

class MunicipalSpecializedReportService
{
    public function __construct(private readonly MunicipalGovernanceReportService $governance) {}

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function build(Municipality $municipality, string $type, int $year, int $month, array $parameters = []): array
    {
        $base = $this->governance->build($municipality, $year, $month);

        return match ($type) {
            MunicipalSpecializedReport::TYPE_HEALTH => $this->health($municipality, $base),
            MunicipalSpecializedReport::TYPE_DIVERGENCES => $this->divergences($base, (int) ($parameters['difference_threshold'] ?? 20)),
            MunicipalSpecializedReport::TYPE_ANNUAL_DOSSIER => $this->annualDossier($municipality, $base, $year, $month),
            default => throw new \InvalidArgumentException('Tipo de relatório especializado inválido.'),
        };
    }

    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        return $this->governance->hash($snapshot);
    }

    /** @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function health(Municipality $municipality, array $base): array
    {
        $profile = $municipality->regulatoryProfiles()
            ->where('fiscal_year', $base['period']['year'])
            ->where('status', 'active')
            ->latest('version')
            ->first();
        $rows = collect($base['amendments'])
            ->where('government_sphere', 'municipal')
            ->where('authorship_type', 'individual')
            ->values();
        $classified = $rows->filter(fn (array $row) => $row['work_plan_health_related'] !== null);
        $health = $rows->where('work_plan_health_related', true)->values();
        $percentage = (float) ($profile?->health_reserve_percentage ?? 0);
        $required = round(((float) $rows->sum('expected')) * ($percentage / 100), 2);
        $reserved = (float) $health->sum('expected');
        $authors = $rows->groupBy('author')->map(function (Collection $authorRows) use ($percentage): array {
            $healthRows = $authorRows->where('work_plan_health_related', true);
            $expected = (float) $authorRows->sum('expected');
            $required = round($expected * ($percentage / 100), 2);
            $reserved = (float) $healthRows->sum('expected');

            return [
                'author' => (string) $authorRows->first()['author'],
                'expected' => $expected,
                'required' => $required,
                'reserved' => $reserved,
                'shortfall' => max(0, $required - $reserved),
                'status' => $percentage <= 0 ? 'not_configured' : ($reserved + 0.01 >= $required ? 'compliant' : 'attention'),
            ];
        })->sortByDesc('shortfall')->values();
        $method = $profile?->health_reserve_method;
        $shortfall = $method === 'per_councilor'
            ? (float) $authors->sum('shortfall')
            : max(0, $required - $reserved);
        $status = ! $profile || $percentage <= 0
            ? 'not_configured'
            : ($classified->count() < $rows->count() ? 'pending_classification' : ($shortfall > 0 ? 'attention' : 'compliant'));

        return $this->envelope($base, MunicipalSpecializedReport::TYPE_HEALTH, [
            'profile' => [
                'configured' => $profile !== null,
                'version' => $profile?->version,
                'percentage' => $percentage,
                'method' => $method,
                'method_label' => $method === 'per_councilor' ? 'Por vereador' : 'No total das emendas',
            ],
            'summary' => [
                'status' => $status,
                'municipal_individual_amendments' => $rows->count(),
                'classified' => $classified->count(),
                'unclassified' => $rows->count() - $classified->count(),
                'health_amendments' => $health->count(),
                'expected' => (float) $rows->sum('expected'),
                'required_health_reserve' => $required,
                'reserved_for_health' => $reserved,
                'shortfall' => $shortfall,
                'received' => (float) $health->sum('received'),
                'committed' => (float) $health->sum('committed'),
                'liquidated' => (float) $health->sum('liquidated'),
                'paid' => (float) $health->sum('paid'),
                'average_physical_execution' => $health->isEmpty() ? 0 : (int) round($health->avg('physical_execution')),
                'unverified_plans' => $health->where('work_plan_health_verified', false)->count(),
            ],
            'authors' => $authors->all(),
            'rows' => $health->all(),
            'unclassified_rows' => $rows->whereNull('work_plan_health_related')->values()->all(),
            'specific_disclaimer' => 'Este relatório demonstra a reserva definida na regra local e a execução das emendas classificadas como saúde. Não certifica, isoladamente, o cômputo de ASPS ou o cumprimento da LC 141, que dependem da contabilidade e da validação do Controle Interno.',
        ]);
    }

    /** @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function divergences(array $base, int $threshold): array
    {
        $threshold = min(100, max(5, $threshold));
        $rows = collect($base['amendments'])->map(function (array $row) use ($threshold): array {
            $flags = collect();
            $planned = (float) $row['work_plan_planned_amount'];
            $expected = (float) $row['expected'];
            $received = (float) $row['received'];
            $committed = (float) $row['committed'];
            $liquidated = (float) $row['liquidated'];
            $paid = (float) $row['paid'];
            $physical = (int) $row['physical_execution'];
            $financial = $received > 0 ? min(100, (int) round(($paid / $received) * 100)) : 0;
            $physicalFinancialDifference = abs($physical - $financial);

            if ($planned > 0 && abs($planned - $expected) > 0.01) {
                $this->flag($flags, 'planning_amount', 'Plano de trabalho diverge do valor previsto', 'warning', $expected, $planned);
            }
            if ($committed > $received + 0.01) {
                $this->flag($flags, 'commitment_above_received', 'Empenho superior ao recurso recebido', 'critical', $received, $committed);
            }
            if ($liquidated > $committed + 0.01) {
                $this->flag($flags, 'liquidation_above_commitment', 'Liquidação superior ao empenho ativo', 'critical', $committed, $liquidated);
            }
            if ($paid > $liquidated + 0.01) {
                $this->flag($flags, 'payment_above_liquidation', 'Pagamento superior ao valor liquidado', 'critical', $liquidated, $paid);
            }
            if ($physicalFinancialDifference >= $threshold && ($physical > 0 || $financial > 0)) {
                $this->flag(
                    $flags,
                    'physical_financial_gap',
                    "Diferença de {$physicalFinancialDifference} p.p. entre execução física e financeira",
                    $physicalFinancialDifference >= ($threshold * 2) ? 'critical' : 'warning',
                    $financial,
                    $physical,
                    'percent',
                );
            }
            if ($paid > 0 && (int) $row['execution_stage_count'] === 0) {
                $this->flag($flags, 'payment_without_stage', 'Há pagamento sem etapa física cadastrada', 'warning', 0, $paid);
            }
            if ($row['status'] === ParliamentaryAmendment::STATUS_COMPLETED && $physical < 100) {
                $this->flag($flags, 'completed_without_physical_delivery', 'Emenda concluída com entrega física inferior a 100%', 'critical', 100, $physical, 'percent');
            }

            $row['financial_execution'] = $financial;
            $row['physical_financial_difference'] = $physicalFinancialDifference;
            $row['divergences'] = $flags->all();
            $row['highest_severity'] = $flags->contains('severity', 'critical') ? 'critical' : ($flags->contains('severity', 'warning') ? 'warning' : 'aligned');

            return $row;
        });
        $divergent = $rows->filter(fn (array $row) => count($row['divergences']) > 0)->values();

        return $this->envelope($base, MunicipalSpecializedReport::TYPE_DIVERGENCES, [
            'threshold' => $threshold,
            'summary' => [
                'amendments_analyzed' => $rows->count(),
                'divergent_amendments' => $divergent->count(),
                'aligned_amendments' => $rows->count() - $divergent->count(),
                'critical_amendments' => $divergent->where('highest_severity', 'critical')->count(),
                'warning_amendments' => $divergent->where('highest_severity', 'warning')->count(),
                'occurrences' => $divergent->sum(fn (array $row) => count($row['divergences'])),
            ],
            'rows' => $divergent->sortBy(fn (array $row) => $row['highest_severity'] === 'critical' ? 0 : 1)->values()->all(),
            'specific_disclaimer' => 'As ocorrências são sinais gerenciais para conferência. Elas não substituem conciliação contábil, medição formal, ateste, parecer técnico ou manifestação do Controle Interno.',
        ]);
    }

    /** @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function annualDossier(Municipality $municipality, array $base, int $year, int $month): array
    {
        $governanceReports = $municipality->governanceReports()->where('fiscal_year', $year)->where('reference_month', '<=', $month);
        $officialDocuments = $municipality->officialDocuments()->where('fiscal_year', $year);
        $accountability = $municipality->accountabilityProcesses()->whereHas('amendment', fn ($query) => $query->where('fiscal_year', $year));
        $programs = MunicipalAuditProgram::query()
            ->where('municipality_id', $municipality->id)
            ->whereHas('planItem.plan', fn ($query) => $query->where('fiscal_year', $year));
        $reviews = $municipality->internalControlReviews()
            ->whereHas('amendment', fn ($query) => $query->where('fiscal_year', $year));

        return $this->envelope($base, MunicipalSpecializedReport::TYPE_ANNUAL_DOSSIER, [
            'summary' => $base['totals'],
            'coverage' => [
                'months_expected' => $month,
                'months_with_issued_report' => (clone $governanceReports)->where('status', 'issued')->distinct()->count('reference_month'),
                'monthly_reports_issued' => (clone $governanceReports)->where('status', 'issued')->count(),
                'official_documents' => (clone $officialDocuments)->count(),
                'official_documents_sent' => (clone $officialDocuments)->whereIn('status', [MunicipalOfficialDocument::STATUS_SENT, MunicipalOfficialDocument::STATUS_ACKNOWLEDGED])->count(),
                'official_documents_acknowledged' => (clone $officialDocuments)->where('status', MunicipalOfficialDocument::STATUS_ACKNOWLEDGED)->count(),
                'audit_plans_issued' => $municipality->auditPlans()->where('fiscal_year', $year)->where('status', 'issued')->count(),
                'audit_programs' => (clone $programs)->count(),
                'audit_programs_concluded' => (clone $programs)->where('status', MunicipalAuditProgram::STATUS_CONCLUDED)->count(),
                'internal_control_reviews' => (clone $reviews)->count(),
                'accountability_processes' => (clone $accountability)->count(),
                'accountability_approved' => (clone $accountability)->where('status', AccountabilityProcess::STATUS_APPROVED)->count(),
                'accountability_with_pending_issues' => (clone $accountability)->whereIn('status', [AccountabilityProcess::STATUS_PENDING_CORRECTION, AccountabilityProcess::STATUS_REJECTED])->count(),
            ],
            'control_matrix' => $base['control_matrix'],
            'attention' => $base['attention'],
            'rows' => $base['amendments'],
            'specific_disclaimer' => 'O dossiê consolida registros existentes no TrilhaGov até a competência indicada. Anexos externos, balancetes, recibos de transmissão e documentos mantidos fora do sistema devem ser conferidos antes do encaminhamento institucional.',
        ]);
    }

    /** @param array<string, mixed> $base
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelope(array $base, string $type, array $payload): array
    {
        return array_merge([
            'schema_version' => 'municipal-specialized-report-2026-01',
            'report_type' => $type,
            'generated_at' => $base['generated_at'],
            'period' => $base['period'],
            'municipality' => $base['municipality'],
            'basis' => $base['basis'],
            'governance' => $base['governance'],
            'general_disclaimer' => $base['disclaimer'],
        ], $payload);
    }

    private function flag(Collection $flags, string $code, string $label, string $severity, float|int $expected, float|int $actual, string $unit = 'money'): void
    {
        $flags->push([
            'code' => $code,
            'label' => $label,
            'severity' => $severity,
            'expected' => $expected,
            'actual' => $actual,
            'difference' => abs((float) $actual - (float) $expected),
            'unit' => $unit,
        ]);
    }
}
