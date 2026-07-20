<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudespHomologationItem extends Model
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_DIVERGENT = 'divergent';

    public const STATUS_UNMATCHED = 'unmatched';

    protected $fillable = [
        'municipality_id',
        'audesp_homologation_batch_id',
        'parliamentary_amendment_id',
        'audesp_amendment_registration_id',
        'status',
        'source_scope',
        'source_amendment_number',
        'source_amendment_year',
        'operation',
        'source_snapshot',
        'local_snapshot',
        'differences',
    ];

    protected function casts(): array
    {
        return [
            'source_amendment_year' => 'integer',
            'source_snapshot' => 'array',
            'local_snapshot' => 'array',
            'differences' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AudespHomologationBatch::class, 'audesp_homologation_batch_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(AudespAmendmentRegistration::class, 'audesp_amendment_registration_id');
    }
}
