<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LegislativeProposalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'municipality_id', 'legislative_proposal_id', 'actor_id', 'event_type',
        'from_status', 'to_status', 'notes', 'snapshot',
    ];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos legislativos não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Eventos legislativos não podem ser excluídos.'));
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(LegislativeProposal::class, 'legislative_proposal_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
