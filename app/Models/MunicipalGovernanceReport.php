<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MunicipalGovernanceReport extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    protected $fillable = [
        'municipality_id', 'created_by', 'updated_by', 'issued_by', 'reference',
        'fiscal_year', 'reference_month', 'version', 'status', 'snapshot',
        'snapshot_sha256', 'management_notes', 'data_generated_at', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'reference_month' => 'integer',
            'version' => 'integer',
            'snapshot' => 'array',
            'data_generated_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $report): void {
            if ($report->getOriginal('status') === self::STATUS_ISSUED) {
                throw new LogicException('Um relatório emitido não pode ser alterado. Gere uma nova versão.');
            }
        });

        static::deleting(function (self $report): void {
            if ($report->status === self::STATUS_ISSUED) {
                throw new LogicException('Um relatório emitido não pode ser excluído.');
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

    public function periodLabel(): string
    {
        return sprintf('%02d/%d', $this->reference_month, $this->fiscal_year);
    }

    public function code(): string
    {
        return sprintf('RGM-%d-%02d-V%d', $this->fiscal_year, $this->reference_month, $this->version);
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

    public function dispatches(): HasMany
    {
        return $this->hasMany(MunicipalReportDispatch::class);
    }

    public function internalControlReviews(): HasMany
    {
        return $this->hasMany(MunicipalInternalControlReview::class);
    }
}
