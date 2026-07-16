<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountabilityProcess extends Model
{
    public const STATUS_PREPARING = 'preparing';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_PENDING_CORRECTION = 'pending_correction';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'municipality_id',
        'responsible_user_id',
        'created_by',
        'status',
        'due_at',
        'submitted_at',
        'protocol_number',
        'approved_at',
        'returned_amount',
        'returned_at',
        'return_reference',
        'reconciliation_notes',
        'submission_notes',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'date',
            'submitted_at' => 'date',
            'approved_at' => 'date',
            'returned_amount' => 'decimal:2',
            'returned_at' => 'date',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PREPARING => 'Em preparação',
            self::STATUS_SUBMITTED => 'Enviada',
            self::STATUS_UNDER_REVIEW => 'Em análise',
            self::STATUS_PENDING_CORRECTION => 'Com diligência',
            self::STATUS_APPROVED => 'Aprovada',
            self::STATUS_REJECTED => 'Rejeitada',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(AccountabilityRequirement::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function diligences(): HasMany
    {
        return $this->hasMany(AccountabilityDiligence::class)
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderBy('due_at')
            ->orderBy('id');
    }
}
