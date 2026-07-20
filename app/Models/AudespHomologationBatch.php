<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AudespHomologationBatch extends Model
{
    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_READY = 'ready_for_submission';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_VALIDATED = 'validated_without_error';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_STORED = 'stored';

    protected $fillable = [
        'municipality_id',
        'created_by',
        'retry_of_id',
        'reference',
        'fiscal_year',
        'reference_month',
        'source_system',
        'source_version',
        'schema_version',
        'status',
        'source_original_name',
        'source_storage_path',
        'source_mime_type',
        'source_size_bytes',
        'source_sha256',
        'item_count',
        'matched_count',
        'divergent_count',
        'unmatched_count',
        'submitted_at',
        'external_protocol',
        'last_return_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'reference_month' => 'integer',
            'source_size_bytes' => 'integer',
            'item_count' => 'integer',
            'matched_count' => 'integer',
            'divergent_count' => 'integer',
            'unmatched_count' => 'integer',
            'submitted_at' => 'datetime',
            'last_return_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_UNDER_REVIEW => 'Em conferência',
            self::STATUS_READY => 'Pronto para o Coletor',
            self::STATUS_SUBMITTED => 'Transmissão registrada',
            self::STATUS_RECEIVED => 'Recebido pelo Audesp',
            self::STATUS_VALIDATED => 'Validado sem erro',
            self::STATUS_REJECTED => 'Rejeitado',
            self::STATUS_STORED => 'Armazenado',
        ];
    }

    /** @return array<string, string> */
    public static function externalStatuses(): array
    {
        return [
            self::STATUS_RECEIVED => 'Documento recebido',
            self::STATUS_VALIDATED => 'Documento validado sem erro',
            self::STATUS_REJECTED => 'Documento rejeitado',
            self::STATUS_STORED => 'Documento armazenado',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_UNDER_REVIEW;
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AudespHomologationItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AudespHomologationEvent::class)
            ->latest('occurred_at')
            ->latest('id');
    }
}
