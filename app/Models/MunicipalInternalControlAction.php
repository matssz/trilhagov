<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MunicipalInternalControlAction extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESPONDED = 'responded';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'municipality_id', 'parliamentary_amendment_id', 'municipal_internal_control_review_id',
        'responsible_user_id', 'created_by', 'responded_by', 'resolved_by', 'status',
        'title', 'instructions', 'due_at', 'response_summary', 'responded_at',
        'resolution_notes', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'date',
            'responded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN => 'Aguardando providência',
            self::STATUS_RESPONDED => 'Aguardando validação',
            self::STATUS_RESOLVED => 'Saneada',
            self::STATUS_RETURNED => 'Devolvida para correção',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_RETURNED], true)
            && $this->due_at->isBefore(today());
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(MunicipalInternalControlReview::class, 'municipal_internal_control_review_id');
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

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MunicipalInternalControlActionEvent::class)
            ->latest('created_at')
            ->latest('id');
    }
}
