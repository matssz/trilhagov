<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrityAlert extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const CATEGORY_DEADLINE = 'deadline';

    public const CATEGORY_DOCUMENT = 'document';

    public const CATEGORY_CONSISTENCY = 'consistency';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'alert_key',
        'category',
        'severity',
        'title',
        'message',
        'due_at',
        'status',
        'detected_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'date',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(AlertDelivery::class);
    }

    public function severityLabel(): string
    {
        return match ($this->severity) {
            self::SEVERITY_CRITICAL => 'Crítico',
            self::SEVERITY_WARNING => 'Atenção',
            default => 'Informativo',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            self::CATEGORY_DEADLINE => 'Prazo',
            self::CATEGORY_DOCUMENT => 'Documento',
            default => 'Consistência',
        };
    }
}
