<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MunicipalAuditPlan extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    protected $fillable = [
        'municipality_id', 'created_by', 'updated_by', 'issued_by', 'fiscal_year',
        'version', 'status', 'title', 'objective', 'methodology', 'risk_criteria',
        'normative_basis', 'coordination_unit', 'planned_start_at', 'planned_end_at',
        'management_notes', 'snapshot', 'snapshot_sha256', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'version' => 'integer',
            'planned_start_at' => 'date',
            'planned_end_at' => 'date',
            'snapshot' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $plan): void {
            if ($plan->getOriginal('status') === self::STATUS_ISSUED) {
                throw new LogicException('Um Plano Anual de Auditoria emitido não pode ser alterado.');
            }
        });
        static::deleting(function (self $plan): void {
            if ($plan->status === self::STATUS_ISSUED) {
                throw new LogicException('Um Plano Anual de Auditoria emitido não pode ser excluído.');
            }
        });
    }

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT => 'Em preparação', self::STATUS_ISSUED => 'Emitido'];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function reference(): string
    {
        return sprintf('PAA-%d-V%d', $this->fiscal_year, $this->version);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
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

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MunicipalAuditPlanItem::class)
            ->orderBy('planned_at')
            ->orderBy('id');
    }
}
