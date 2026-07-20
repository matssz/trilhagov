<?php

namespace App\Models;

use Database\Factories\ParliamentaryAmendmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

class ParliamentaryAmendment extends Model
{
    /** @use HasFactory<ParliamentaryAmendmentFactory> */
    use HasFactory;

    public const STATUS_IDENTIFIED = 'identified';

    public const STATUS_PLAN_PENDING = 'plan_pending';

    public const STATUS_UNDER_ANALYSIS = 'under_analysis';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RESOURCE_RECEIVED = 'resource_received';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_ACCOUNTABILITY_PENDING = 'accountability_pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_CANCELLED = 'cancelled';

    public const RISK_LOW = 'low';

    public const RISK_MODERATE = 'moderate';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    private const SAO_PAULO_CAPITAL_IBGE_CODE = '3550308';

    protected $fillable = [
        'created_by',
        'municipal_regulatory_profile_id',
        'reference',
        'fiscal_year',
        'government_sphere',
        'authorship_type',
        'transfer_type',
        'author_name',
        'author_party',
        'object',
        'expense_destination',
        'responsible_department',
        'beneficiary_location',
        'responsible_user_id',
        'transferegov_code',
        'legal_instrument',
        'administrative_process',
        'bank_tracking_type',
        'bank_account_number',
        'funding_source_code',
        'application_code_fixed',
        'application_code_variable',
        'expected_amount',
        'received_amount',
        'status',
        'cancellation_reason',
        'cancelled_at',
        'indicated_at',
        'received_at',
        'communication_deadline',
        'communication_completed_at',
        'execution_deadline',
        'application_deadline',
        'execution_completed_at',
        'accountability_deadline',
        'accountability_completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'responsible_user_id' => 'integer',
            'indicated_at' => 'date',
            'received_at' => 'date',
            'communication_deadline' => 'date',
            'communication_completed_at' => 'date',
            'execution_deadline' => 'date',
            'application_deadline' => 'date',
            'cancelled_at' => 'datetime',
            'execution_completed_at' => 'date',
            'accountability_deadline' => 'date',
            'accountability_completed_at' => 'date',
            'risk_score' => 'integer',
            'risk_reasons' => 'array',
            'risk_calculated_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_IDENTIFIED => 'Identificada',
            self::STATUS_PLAN_PENDING => 'Plano de trabalho pendente',
            self::STATUS_UNDER_ANALYSIS => 'Em análise',
            self::STATUS_APPROVED => 'Aprovada',
            self::STATUS_RESOURCE_RECEIVED => 'Recurso recebido',
            self::STATUS_EXECUTING => 'Em execução',
            self::STATUS_ACCOUNTABILITY_PENDING => 'Prestação de contas pendente',
            self::STATUS_COMPLETED => 'Concluída',
            self::STATUS_BLOCKED => 'Com impedimento',
            self::STATUS_CANCELLED => 'Cancelada',
        ];
    }

    /** @return array<string, string> */
    public static function expenseDestinations(): array
    {
        return ['cost' => 'Custeio', 'investment' => 'Investimento'];
    }

    /** @return array<string, string> */
    public static function bankTrackingTypes(): array
    {
        return [
            'specific_account' => 'Conta bancária específica',
            'municipal_direct_codes' => 'Execução direta com rastreabilidade contábil',
        ];
    }

    public function expenseDestinationLabel(): string
    {
        return self::expenseDestinations()[$this->expense_destination] ?? 'Não informado';
    }

    public function bankTrackingTypeLabel(): string
    {
        return self::bankTrackingTypes()[$this->bank_tracking_type] ?? 'Não informado';
    }

    /** @return array<string, string> */
    public static function governmentSpheres(): array
    {
        return ['federal' => 'Federal', 'state' => 'Estadual', 'municipal' => 'Municipal'];
    }

    /** @return array<string, string> */
    public static function authorshipTypes(): array
    {
        return [
            'individual' => 'Individual',
            'caucus' => 'Bancada',
            'commission' => 'Comissão',
            'other' => 'Outra',
        ];
    }

    /** @return array<string, string> */
    public static function transferTypes(): array
    {
        return [
            'direct_execution' => 'Execução direta pelo Município',
            'special' => 'Transferência especial',
            'defined_purpose' => 'Finalidade definida',
            'agreement' => 'Convênio ou contrato de repasse',
            'fund_to_fund' => 'Fundo a fundo',
            'other' => 'Outra modalidade',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function governmentSphereLabel(): string
    {
        return self::governmentSpheres()[$this->government_sphere] ?? $this->government_sphere;
    }

    public function authorshipTypeLabel(): string
    {
        return self::authorshipTypes()[$this->authorship_type] ?? $this->authorship_type;
    }

    public function transferTypeLabel(): string
    {
        return self::transferTypes()[$this->transfer_type] ?? $this->transfer_type;
    }

    public function supportsTcespCompliance(): bool
    {
        $municipality = $this->relationLoaded('municipality')
            ? $this->municipality
            : $this->municipality()->first(['state', 'ibge_code']);

        return $this->government_sphere === 'municipal'
            && $municipality?->state === 'SP'
            && (string) $municipality?->ibge_code !== self::SAO_PAULO_CAPITAL_IBGE_CODE;
    }

    public function riskLabel(): string
    {
        return match ($this->risk_level) {
            self::RISK_CRITICAL => 'Crítico',
            self::RISK_HIGH => 'Alto',
            self::RISK_MODERATE => 'Moderado',
            default => 'Baixo',
        };
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function regulatoryProfile(): BelongsTo
    {
        return $this->belongsTo(MunicipalRegulatoryProfile::class, 'municipal_regulatory_profile_id');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')
            ->latest('created_at')
            ->latest('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AmendmentDocument::class)
            ->latest('created_at')
            ->latest('id');
    }

    public function executionStages(): HasMany
    {
        return $this->hasMany(ExecutionStage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function financialCommitments(): HasMany
    {
        return $this->hasMany(FinancialCommitment::class)
            ->latest('committed_at')
            ->latest('id');
    }

    public function financialPayments(): HasMany
    {
        return $this->hasMany(FinancialPayment::class)
            ->latest('paid_at')
            ->latest('id');
    }

    public function financialLiquidations(): HasMany
    {
        return $this->hasMany(FinancialLiquidation::class)
            ->latest('liquidated_at')
            ->latest('id');
    }

    public function audespRegistration(): HasOne
    {
        return $this->hasOne(AudespAmendmentRegistration::class);
    }

    public function transparencyEvents(): HasMany
    {
        return $this->hasMany(AmendmentTransparencyEvent::class)
            ->latest('occurred_at')
            ->latest('id');
    }

    public function physicalExecutionPercentage(): int
    {
        $stages = $this->relationLoaded('executionStages')
            ? $this->executionStages
            : $this->executionStages()->get();

        return $stages->isEmpty()
            ? 0
            : (int) round($stages->average('progress_percentage'));
    }

    public function accountabilityProcess(): HasOne
    {
        return $this->hasOne(AccountabilityProcess::class);
    }

    public function accountabilityRequirements(): HasMany
    {
        return $this->hasMany(AccountabilityRequirement::class);
    }

    public function accountabilityDiligences(): HasMany
    {
        return $this->hasMany(AccountabilityDiligence::class);
    }

    public function integrityAlerts(): HasMany
    {
        return $this->hasMany(IntegrityAlert::class);
    }

    public function externalCandidates(): HasMany
    {
        return $this->hasMany(ExternalAmendmentCandidate::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(MunicipalWorkItem::class);
    }

    public function complianceReviews(): HasMany
    {
        return $this->hasMany(AmendmentComplianceReview::class);
    }

    public function municipalWorkPlan(): HasOne
    {
        return $this->hasOne(MunicipalWorkPlan::class);
    }

    public function technicalImpediments(): HasMany
    {
        return $this->hasMany(TechnicalImpediment::class)->latest('identified_at')->latest('id');
    }

    public function technicalDiligences(): HasMany
    {
        return $this->hasMany(TechnicalDiligence::class)->latest('due_at')->latest('id');
    }

    public function remappings(): HasMany
    {
        return $this->hasMany(AmendmentRemapping::class)->latest('created_at');
    }

    public function municipalAdmissibilityReviews(): HasMany
    {
        return $this->hasMany(MunicipalAdmissibilityReview::class);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'municipality.users',
            fn (Builder $query) => $query->where('users.id', $user->id),
        );
    }

    /** @return array{label: string, date: Carbon}|null */
    public function nextDeadline(): ?array
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return null;
        }

        return collect([
            ['label' => 'Comunicação e publicidade', 'date' => $this->communication_deadline, 'completed_at' => $this->communication_completed_at],
            ['label' => 'Execução', 'date' => $this->execution_deadline, 'completed_at' => $this->execution_completed_at],
            ['label' => 'Prestação de contas', 'date' => $this->accountability_deadline, 'completed_at' => $this->accountability_completed_at],
        ])->filter(fn (array $deadline) => $deadline['date'] !== null && $deadline['completed_at'] === null)
            ->sortBy('date')
            ->first();
    }

    public function hasOverdueDeadline(): bool
    {
        $deadline = $this->nextDeadline();

        return $deadline !== null && $deadline['date']->isBefore(today());
    }

    public function hasUpcomingDeadline(): bool
    {
        $deadline = $this->nextDeadline();

        return $deadline !== null
            && $deadline['date']->betweenIncluded(today(), today()->addDays(30));
    }
}
