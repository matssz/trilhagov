<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AmendmentTransparencyEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'municipality_id',
        'event_type',
        'title',
        'description',
        'changes',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O histórico público não pode ser alterado.'));
        static::deleting(fn () => throw new LogicException('O histórico público não pode ser excluído.'));
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }
}
