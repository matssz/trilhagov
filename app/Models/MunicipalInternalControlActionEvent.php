<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MunicipalInternalControlActionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'municipality_id', 'municipal_internal_control_action_id', 'user_id', 'actor_name',
        'event_type', 'from_status', 'to_status', 'description', 'evidence_path',
        'evidence_original_name', 'evidence_mime', 'evidence_size', 'evidence_sha256',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'evidence_size' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos do Controle Interno não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Eventos do Controle Interno não podem ser excluídos.'));
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(MunicipalInternalControlAction::class, 'municipal_internal_control_action_id');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
