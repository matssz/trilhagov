<?php

namespace App\Services;

use App\Models\AmendmentComplianceReview;
use App\Models\FinancialCommitment;
use App\Models\Municipality;
use App\Models\MunicipalWorkPlan;
use App\Models\ParliamentaryAmendment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MunicipalGovernanceReportService
{
    public const SOURCE_LABEL = 'Manual de Emendas Parlamentares Impositivas Municipais - TCESP, julho de 2026';

    public const SOURCE_URL = 'https://www.tce.sp.gov.br/publicacoes/manual-emendas-parlamentares-impositivas-municipais';

    public function __construct(private readonly TcespComplianceFramework $framework) {}

    /** @return array<string, mixed> */
    public function build(Municipality $municipality, int $year, int $month): array
    {
        $periodEnd = Carbon::create($year, $month, 1)->endOfMonth();
        $amendments = $municipality->amendments()
            ->where('fiscal_year', $year)
            ->with([
                'responsibleUser:id,name',
                'executionStages',
                'financialCommitments' => fn ($query) => $query->whereDate('committed_at', '<=', $periodEnd),
                'financialCommitments.liquidations' => fn ($query) => $query->whereDate('liquidated_at', '<=', $periodEnd),
                'financialCommitments.payments' => fn ($query) => $query->whereDate('paid_at', '<=', $periodEnd),
                'municipalWorkPlan',
                'technicalImpediments',
                'accountabilityProcess.diligences',
                'complianceReviews',
                'audespRegistration',
                'audespHomologationItems.batch',
                'integrityAlerts',
                'workItems',
            ])
            ->orderBy('reference')
            ->get();

        $rows = $amendments->map(fn (ParliamentaryAmendment $amendment) => $this->amendmentRow($amendment, $periodEnd));
        $totals = $this->totals($rows);
        $profile = $municipality->regulatoryProfiles()
            ->where('fiscal_year', $year)
            ->where('status', 'active')
            ->with('instruments')
            ->latest('version')
            ->first();
        $latestBatch = $municipality->audespHomologationBatches()
            ->where('fiscal_year', $year)
            ->where('reference_month', '<=', $month)
            ->latest('reference_month')
            ->latest('id')
            ->first();

        return [
            'schema_version' => 'governance-report-2026-01',
            'generated_at' => now()->toIso8601String(),
            'period' => ['year' => $year, 'month' => $month, 'end' => $periodEnd->toDateString()],
            'municipality' => [
                'name' => $municipality->name,
                'state' => $municipality->state,
                'cnpj' => $municipality->cnpj,
                'ibge_code' => $municipality->ibge_code,
            ],
            'totals' => $totals,
            'governance' => [
                'active_normative_profile' => $profile !== null,
                'normative_profile_version' => $profile?->version,
                'normative_instruments' => $profile?->instruments->count() ?? 0,
                'transparency_enabled' => (bool) $municipality->transparency_enabled,
                'transparency_updated_at' => $municipality->transparency_updated_at?->toIso8601String(),
                'latest_audesp_batch' => $latestBatch ? [
                    'reference' => $latestBatch->reference,
                    'period' => sprintf('%02d/%d', $latestBatch->reference_month, $latestBatch->fiscal_year),
                    'status' => $latestBatch->status,
                    'status_label' => $latestBatch->statusLabel(),
                    'pending_items' => $latestBatch->divergent_count + $latestBatch->unmatched_count,
                ] : null,
            ],
            'control_matrix' => $this->controlMatrix($rows, $municipality),
            'attention' => $this->attention($rows),
            'amendments' => $rows->values()->all(),
            'basis' => [
                'manual' => self::SOURCE_LABEL,
                'manual_url' => self::SOURCE_URL,
                'governance_notice' => 'Comunicado GP 15/2026 - TCESP',
                'chamber_report_notice' => 'Comunicado GP 46/2025 - TCESP',
            ],
            'disclaimer' => 'Instrumento gerencial de apoio ao controle interno e à prestação periódica de informações. Não substitui parecer, certificação, balancete, envio ao Audesp ou documento exigido pela legislação local.',
        ];
    }

    /** @return array<string, mixed> */
    private function amendmentRow(ParliamentaryAmendment $amendment, Carbon $periodEnd): array
    {
        $activeCommitments = $amendment->financialCommitments->where('status', FinancialCommitment::STATUS_ACTIVE);
        $committed = (float) $activeCommitments->sum('committed_amount');
        $liquidated = (float) $activeCommitments->sum(fn ($item) => $item->liquidations->sum('amount'));
        $paid = (float) $activeCommitments->sum(fn ($item) => $item->payments->sum('amount'));
        $received = ($amendment->received_at === null || $amendment->received_at->lte($periodEnd))
            ? (float) $amendment->received_amount
            : 0.0;
        $reviews = $amendment->complianceReviews->where('framework_version', TcespComplianceFramework::VERSION);
        $applicable = $reviews->where('status', '!=', AmendmentComplianceReview::STATUS_NOT_APPLICABLE);
        $rulesTotal = count($this->framework->rules());
        $notApplicable = $reviews->where('status', AmendmentComplianceReview::STATUS_NOT_APPLICABLE)->count();
        $applicableTotal = $rulesTotal - $notApplicable;
        $compliantTotal = $applicable->where('status', AmendmentComplianceReview::STATUS_COMPLIANT)->count();
        $compliancePercentage = $applicableTotal > 0 ? (int) round(($compliantTotal / $applicableTotal) * 100) : 0;
        $openImpediments = $amendment->technicalImpediments->filter->isOpen();
        $openAlerts = $amendment->integrityAlerts->where('status', 'open');
        $openWork = $amendment->workItems->whereIn('status', ['pending', 'in_progress']);
        $latestHomologation = $amendment->audespHomologationItems
            ->sortByDesc('audesp_homologation_batch_id')
            ->first()?->batch;
        $transparencyFields = collect([
            $amendment->author_name, $amendment->object, $amendment->expected_amount,
            $amendment->responsible_department, $amendment->status, $amendment->indicated_at,
        ]);

        return [
            'id' => $amendment->id,
            'reference' => $amendment->reference,
            'author' => $amendment->author_name,
            'object' => $amendment->object,
            'department' => $amendment->responsible_department,
            'responsible' => $amendment->responsibleUser?->name,
            'status' => $amendment->status,
            'status_label' => $amendment->statusLabel(),
            'risk' => $amendment->risk_level,
            'risk_label' => $amendment->riskLabel(),
            'expected' => (float) $amendment->expected_amount,
            'received' => $received,
            'committed' => $committed,
            'liquidated' => $liquidated,
            'paid' => $paid,
            'balance' => max(0, $received - $paid),
            'physical_execution' => $amendment->physicalExecutionPercentage(),
            'work_plan_status' => $amendment->municipalWorkPlan?->status,
            'work_plan_label' => $amendment->municipalWorkPlan?->statusLabel() ?? 'Não iniciado',
            'work_plan_approved' => $amendment->municipalWorkPlan?->status === MunicipalWorkPlan::STATUS_APPROVED,
            'compliance_percentage' => $compliancePercentage,
            'compliance_pending' => max(0, $applicableTotal - $compliantTotal),
            'budget_reviewed' => $this->ruleCompliant($reviews, 'ORC-02'),
            'concomitant_control_reviewed' => $this->ruleCompliant($reviews, 'CON-02'),
            'transparency_reviewed' => $this->ruleCompliant($reviews, 'TRA-01'),
            'traceability_reviewed' => $this->ruleCompliant($reviews, 'TRA-02') && $this->ruleCompliant($reviews, 'TRA-03'),
            'open_impediments' => $openImpediments->count(),
            'overdue_impediments' => $openImpediments->filter->isOverdue()->count(),
            'open_alerts' => $openAlerts->count(),
            'critical_alerts' => $openAlerts->where('severity', 'critical')->count(),
            'open_work_items' => $openWork->count(),
            'accountability_status' => $amendment->accountabilityProcess?->status,
            'accountability_label' => $amendment->accountabilityProcess?->statusLabel() ?? 'Não iniciada',
            'open_accountability_diligences' => $amendment->accountabilityProcess?->diligences->where('status', 'open')->count() ?? 0,
            'transparency_complete' => $transparencyFields->every(fn ($value) => filled($value)),
            'audesp_prepared' => $amendment->audespRegistration?->prepared_at !== null,
            'audesp_homologation_status' => $latestHomologation?->status,
            'audesp_homologation_label' => $latestHomologation?->statusLabel() ?? 'Sem lote',
            'next_deadline' => collect([
                $amendment->communication_deadline, $amendment->application_deadline,
                $amendment->execution_deadline, $amendment->accountability_deadline,
            ])->filter()->sort()->first()?->toDateString(),
        ];
    }

    /** @return array<string, float|int> */
    private function totals(Collection $rows): array
    {
        return [
            'amendments' => $rows->count(),
            'expected' => (float) $rows->sum('expected'),
            'received' => (float) $rows->sum('received'),
            'committed' => (float) $rows->sum('committed'),
            'liquidated' => (float) $rows->sum('liquidated'),
            'paid' => (float) $rows->sum('paid'),
            'balance' => (float) $rows->sum('balance'),
            'open_impediments' => (int) $rows->sum('open_impediments'),
            'open_alerts' => (int) $rows->sum('open_alerts'),
            'open_work_items' => (int) $rows->sum('open_work_items'),
            'average_physical_execution' => $rows->isEmpty() ? 0 : (int) round($rows->avg('physical_execution')),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function controlMatrix(Collection $rows, Municipality $municipality): array
    {
        $checks = [
            ['key' => 'work_plan', 'label' => 'Adequação do plano de trabalho', 'met' => $rows->where('work_plan_approved', true)->count(), 'pending' => $rows->where('work_plan_approved', false)->count()],
            ['key' => 'budget', 'label' => 'Compatibilidade orçamentária revisada', 'met' => $rows->where('budget_reviewed', true)->count(), 'pending' => $rows->where('budget_reviewed', false)->count()],
            ['key' => 'procurement', 'label' => 'Contratação, conflitos e controle concomitante', 'met' => $rows->where('concomitant_control_reviewed', true)->count(), 'pending' => $rows->where('concomitant_control_reviewed', false)->count()],
            ['key' => 'transparency', 'label' => 'Transparência ativa revisada', 'met' => $rows->where('transparency_reviewed', true)->where('transparency_complete', true)->count(), 'pending' => $rows->reject(fn (array $row) => $row['transparency_reviewed'] && $row['transparency_complete'])->count()],
            ['key' => 'audesp', 'label' => 'Rastreabilidade e cadastro Audesp', 'met' => $rows->where('traceability_reviewed', true)->where('audesp_prepared', true)->count(), 'pending' => $rows->reject(fn (array $row) => $row['traceability_reviewed'] && $row['audesp_prepared'])->count()],
        ];

        return collect($checks)->map(function (array $check) use ($municipality): array {
            $check['status'] = $check['pending'] === 0 && $check['met'] > 0 ? 'controlled' : 'attention';
            if ($check['key'] === 'transparency' && ! $municipality->transparency_enabled) {
                $check['status'] = 'attention';
            }

            return $check;
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function attention(Collection $rows): array
    {
        return $rows->filter(fn (array $row) => $row['critical_alerts'] > 0
                || $row['overdue_impediments'] > 0
                || $row['compliance_pending'] > 0
                || ! $row['work_plan_approved']
                || ! $row['audesp_prepared'])
            ->sortByDesc(fn (array $row) => ($row['critical_alerts'] * 100) + ($row['overdue_impediments'] * 50) + $row['compliance_pending'])
            ->take(12)
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function ruleCompliant(Collection $reviews, string $code): bool
    {
        return $reviews->firstWhere('rule_code', $code)?->status === AmendmentComplianceReview::STATUS_COMPLIANT;
    }
}
