<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountabilityDiligence extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'assigned_user_id',
        'created_by',
        'title',
        'description',
        'received_at',
        'due_at',
        'status',
        'response_notes',
        'response_protocol',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'due_at' => 'date',
            'responded_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN => 'Aberta',
            self::STATUS_RESPONDED => 'Respondida',
            self::STATUS_CLOSED => 'Encerrada',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OPEN && $this->due_at->isBefore(today());
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(AccountabilityProcess::class, 'accountability_process_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
