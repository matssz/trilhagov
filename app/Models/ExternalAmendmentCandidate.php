<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExternalAmendmentCandidate extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_DIVERGENT = 'divergent';

    public const STATUS_LINKED = 'linked';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_IGNORED = 'ignored';

    protected $fillable = [
        'municipality_id',
        'external_data_sync_id',
        'parliamentary_amendment_id',
        'reviewed_by',
        'source',
        'external_id',
        'external_code',
        'amendment_code',
        'fiscal_year',
        'author_name',
        'object',
        'expected_amount',
        'external_status',
        'accepted_at',
        'bank_status',
        'match_status',
        'differences',
        'payload',
        'source_hash',
        'review_notes',
        'last_seen_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'expected_amount' => 'decimal:2',
            'accepted_at' => 'date',
            'differences' => 'array',
            'payload' => 'array',
            'last_seen_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_NEW => 'Nova na fonte',
            self::STATUS_DIVERGENT => 'Com divergência',
            self::STATUS_MATCHED => 'Dados conferem',
            self::STATUS_LINKED => 'Vinculada',
            self::STATUS_IMPORTED => 'Importada',
            self::STATUS_IGNORED => 'Ignorada',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->match_status] ?? $this->match_status;
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function sync(): BelongsTo
    {
        return $this->belongsTo(ExternalDataSync::class, 'external_data_sync_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function financialReconciliations(): HasMany
    {
        return $this->hasMany(ExternalFinancialReconciliation::class)
            ->latest('reconciled_at')
            ->latest('id');
    }

    public function latestFinancialReconciliation(): HasOne
    {
        return $this->hasOne(ExternalFinancialReconciliation::class)->ofMany([
            'reconciled_at' => 'max',
            'id' => 'max',
        ]);
    }
}
