<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmendmentImportRow extends Model
{
    public const STATUS_VALID = 'valid';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_IMPORTED = 'imported';

    protected $fillable = [
        'municipality_id',
        'amendment_import_batch_id',
        'parliamentary_amendment_id',
        'row_number',
        'status',
        'raw_data',
        'normalized_data',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'normalized_data' => 'array',
            'errors' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AmendmentImportBatch::class, 'amendment_import_batch_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }
}
