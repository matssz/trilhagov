<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportOccurrence extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_MONITORING = 'monitoring';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'municipality_id',
        'user_id',
        'resolved_by',
        'fingerprint',
        'source',
        'level',
        'status',
        'title',
        'message',
        'route_name',
        'method',
        'url',
        'ip_address',
        'user_agent',
        'context',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'resolution_notes',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN => 'Aberta',
            self::STATUS_MONITORING => 'Em monitoramento',
            self::STATUS_RESOLVED => 'Resolvida',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? (string) $this->status;
    }

    public function severity(): string
    {
        return match ($this->level) {
            'critical', 'alert', 'emergency' => 'critical',
            'warning', 'notice' => 'attention',
            default => 'error',
        };
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'exception' => 'Exceção do sistema',
            'log' => 'Log da aplicação',
            'manual' => 'Registro manual',
            default => ucfirst((string) $this->source),
        };
    }
}
