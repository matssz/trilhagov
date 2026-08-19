<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudespRegistrationImportRow extends Model
{
    public const STATUS_VALID = 'valid';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_IMPORTED = 'imported';

    protected $fillable = [
        'municipality_id',
        'audesp_registration_import_batch_id',
        'parliamentary_amendment_id',
        'audesp_amendment_registration_id',
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
        return $this->belongsTo(AudespRegistrationImportBatch::class, 'audesp_registration_import_batch_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(AudespAmendmentRegistration::class, 'audesp_amendment_registration_id');
    }
}
