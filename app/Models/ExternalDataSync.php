<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalDataSync extends Model
{
    public const SOURCE_TRANSFEREGOV_SPECIAL = 'transferegov_special';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'municipality_id',
        'initiated_by',
        'source',
        'status',
        'source_updated_at',
        'items_fetched',
        'items_created',
        'items_updated',
        'divergences_found',
        'error_message',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ExternalAmendmentCandidate::class);
    }
}
