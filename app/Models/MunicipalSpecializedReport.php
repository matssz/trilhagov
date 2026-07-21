<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MunicipalSpecializedReport extends Model
{
    public const TYPE_HEALTH = 'health';

    public const TYPE_DIVERGENCES = 'divergences';

    public const TYPE_ANNUAL_DOSSIER = 'annual_dossier';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    protected $fillable = [
        'municipality_id', 'created_by', 'updated_by', 'issued_by', 'reference',
        'report_type', 'fiscal_year', 'reference_month', 'version', 'status',
        'parameters', 'snapshot', 'snapshot_sha256', 'management_notes',
        'data_generated_at', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'reference_month' => 'integer',
            'version' => 'integer',
            'parameters' => 'array',
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

    /** @return array<string, string> */
    public static function types(): array
    {
        return [
            self::TYPE_HEALTH => 'Reserva e execução em saúde',
            self::TYPE_DIVERGENCES => 'Divergências físico-financeiras',
            self::TYPE_ANNUAL_DOSSIER => 'Dossiê anual municipal',
        ];
    }

    /** @return array<string, string> */
    public static function typeDescriptions(): array
    {
        return [
            self::TYPE_HEALTH => 'Consolida a reserva local, a classificação e a execução das emendas destinadas à saúde.',
            self::TYPE_DIVERGENCES => 'Aponta diferenças entre valor previsto, planejamento, execução financeira e entrega física.',
            self::TYPE_ANNUAL_DOSSIER => 'Reúne emendas, controles, comunicações, auditorias e prestação de contas do exercício.',
        ];
    }

    public static function statuses(): array
    {
        return [self::STATUS_DRAFT => 'Em preparação', self::STATUS_ISSUED => 'Emitido'];
    }

    public function typeLabel(): string
    {
        return self::types()[$this->report_type] ?? $this->report_type;
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
        $prefix = match ($this->report_type) {
            self::TYPE_HEALTH => 'RMS',
            self::TYPE_DIVERGENCES => 'RMD',
            default => 'DAM',
        };

        return sprintf('%s-%d-%02d-V%d', $prefix, $this->fiscal_year, $this->reference_month, $this->version);
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
}
