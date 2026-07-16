<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MunicipalWorkItemEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'municipality_id',
        'user_id',
        'actor_name',
        'event_type',
        'from_status',
        'to_status',
        'description',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos das ações não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Eventos das ações não podem ser excluídos.'));
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(MunicipalWorkItem::class, 'municipal_work_item_id');
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
