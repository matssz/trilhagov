<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MunicipalContract extends Model
{
    public const STATUS_PLANNING = 'planning';

    public const STATUS_SELECTION = 'selection';

    public const STATUS_CONTRACTED = 'contracted';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'municipality_id', 'parliamentary_amendment_id', 'created_by', 'updated_by',
        'contract_manager_id', 'contract_inspector_id', 'reference', 'process_number',
        'contract_number', 'object_type', 'is_renovation', 'procurement_method',
        'execution_regime', 'judgment_criterion', 'object', 'site_location',
        'estimated_amount', 'supplier_name', 'supplier_document', 'original_amount',
        'current_amount', 'signed_at', 'effective_start_at', 'effective_end_at',
        'work_order_at', 'status', 'planning_checklist', 'measurement_criteria',
        'payment_terms', 'warranty_months', 'technical_responsible',
        'technical_registration', 'publication_type', 'publication_reference',
        'published_at', 'provisional_acceptance_reference', 'provisional_accepted_at',
        'definitive_acceptance_reference', 'definitive_accepted_at',
        'suspension_reason', 'suspended_at', 'resumed_at', 'cancellation_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_renovation' => 'boolean',
            'estimated_amount' => 'decimal:2',
            'original_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'planning_checklist' => 'array',
            'signed_at' => 'date',
            'effective_start_at' => 'date',
            'effective_end_at' => 'date',
            'work_order_at' => 'date',
            'published_at' => 'date',
            'provisional_accepted_at' => 'date',
            'definitive_accepted_at' => 'date',
            'suspended_at' => 'date',
            'resumed_at' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $contract): void {
            if (in_array($contract->getOriginal('status'), [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
                throw new LogicException('Contrato encerrado não pode ser alterado.');
            }
        });
        static::deleting(fn () => throw new LogicException('Contratos municipais não podem ser excluídos.'));
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PLANNING => 'Planejamento',
            self::STATUS_SELECTION => 'Seleção do fornecedor',
            self::STATUS_CONTRACTED => 'Contratado',
            self::STATUS_EXECUTING => 'Em execução',
            self::STATUS_SUSPENDED => 'Paralisado',
            self::STATUS_COMPLETED => 'Recebido definitivamente',
            self::STATUS_CANCELLED => 'Cancelado',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function code(): string
    {
        return 'CTR-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contract_manager_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contract_inspector_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(ContractMeasurement::class)->orderBy('sequence');
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(ContractAddendum::class)->orderBy('sequence');
    }
}
