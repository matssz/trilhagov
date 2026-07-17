<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalDiligence extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'technical_impediment_id',
        'created_by',
        'assigned_user_id',
        'evidence_document_id',
        'status',
        'title',
        'request_details',
        'requested_at',
        'due_at',
        'response_notes',
        'response_protocol',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'date',
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
            self::STATUS_ACCEPTED => 'Resposta aceita',
            self::STATUS_REJECTED => 'Resposta insuficiente',
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

    public function impediment(): BelongsTo
    {
        return $this->belongsTo(TechnicalImpediment::class, 'technical_impediment_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function evidenceDocument(): BelongsTo
    {
        return $this->belongsTo(AmendmentDocument::class, 'evidence_document_id');
    }
}
