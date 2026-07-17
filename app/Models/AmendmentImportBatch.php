<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmendmentImportBatch extends Model
{
    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'municipality_id',
        'user_id',
        'original_name',
        'file_hash',
        'status',
        'total_rows',
        'valid_rows',
        'duplicate_rows',
        'invalid_rows',
        'imported_rows',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
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

    public function rows(): HasMany
    {
        return $this->hasMany(AmendmentImportRow::class);
    }
}
