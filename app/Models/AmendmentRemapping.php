<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmendmentRemapping extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'technical_impediment_id',
        'requested_by',
        'decided_by',
        'status',
        'original_object',
        'proposed_object',
        'justification',
        'amount',
        'requested_at',
        'decision_notes',
        'decision_reference',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'requested_at' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Rascunho',
            self::STATUS_SUBMITTED => 'Em decisão',
            self::STATUS_APPROVED => 'Aprovado',
            self::STATUS_REJECTED => 'Rejeitado',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function impediment(): BelongsTo
    {
        return $this->belongsTo(TechnicalImpediment::class, 'technical_impediment_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
