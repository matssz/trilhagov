<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MunicipalAuditPlanItemEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'municipality_id', 'municipal_audit_plan_item_id', 'user_id', 'actor_name',
        'event_type', 'from_status', 'to_status', 'description', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos do Plano Anual de Auditoria não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Eventos do Plano Anual de Auditoria não podem ser excluídos.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MunicipalAuditPlanItem::class, 'municipal_audit_plan_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
