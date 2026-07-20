<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MunicipalRegulatoryProfile extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const REGIME_UNDER_REVIEW = 'under_review';

    public const REGIME_NOT_INSTITUTED = 'not_instituted';

    public const REGIME_INSTITUTED = 'instituted';

    protected $fillable = [
        'municipality_id', 'created_by', 'updated_by', 'activated_by',
        'audesp_responsible_user_id', 'fiscal_year', 'version', 'status',
        'regime_status', 'previous_year_rcl', 'individual_limit_percentage',
        'health_reserve_percentage', 'health_reserve_method',
        'amendments_per_councilor_limit', 'minimum_amendment_amount',
        'generic_amendments_prohibited', 'prior_technical_review_required',
        'work_plan_required', 'pca_check_required', 'impediment_notice_days',
        'impediment_correction_days', 'publication_business_days',
        'document_retention_years', 'bank_traceability_rule',
        'audesp_registration_status', 'legal_review_responsible',
        'legal_review_reference', 'legal_reviewed_at', 'notes', 'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_year_rcl' => 'decimal:2',
            'individual_limit_percentage' => 'decimal:4',
            'health_reserve_percentage' => 'decimal:4',
            'minimum_amendment_amount' => 'decimal:2',
            'generic_amendments_prohibited' => 'boolean',
            'prior_technical_review_required' => 'boolean',
            'work_plan_required' => 'boolean',
            'pca_check_required' => 'boolean',
            'legal_reviewed_at' => 'date',
            'activated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $profile): void {
            if ($profile->getOriginal('status') === self::STATUS_ACTIVE) {
                throw new LogicException('Uma configuração vigente não pode ser alterada. Crie uma nova revisão.');
            }
        });
    }

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT => 'Em preparação', self::STATUS_ACTIVE => 'Vigente', self::STATUS_ARCHIVED => 'Arquivada'];
    }

    public static function regimeStatuses(): array
    {
        return [
            self::REGIME_UNDER_REVIEW => 'Em análise jurídica',
            self::REGIME_NOT_INSTITUTED => 'Não instituído localmente',
            self::REGIME_INSTITUTED => 'Instituído na Lei Orgânica',
        ];
    }

    public static function healthReserveMethods(): array
    {
        return ['per_councilor' => 'Por vereador', 'global' => 'No total das emendas'];
    }

    public static function bankTraceabilityRules(): array
    {
        return [
            'individual_account' => 'Conta bancária específica por emenda',
            'direct_execution_traceability' => 'Execução direta com rastreabilidade contábil',
            'local_rule' => 'Regra local documentada',
        ];
    }

    public static function audespStatuses(): array
    {
        return ['not_started' => 'Não iniciado', 'in_progress' => 'Em implantação', 'ready' => 'Operação preparada'];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function audespResponsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audesp_responsible_user_id');
    }

    public function instruments(): HasMany
    {
        return $this->hasMany(MunicipalNormativeInstrument::class);
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function regimeStatusLabel(): string
    {
        return self::regimeStatuses()[$this->regime_status] ?? $this->regime_status;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
