<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExecutionStage extends Model
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'municipality_id',
        'responsible_user_id',
        'created_by',
        'title',
        'description',
        'status',
        'progress_percentage',
        'planned_amount',
        'planned_start_at',
        'planned_end_at',
        'completed_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'progress_percentage' => 'integer',
            'planned_amount' => 'decimal:2',
            'planned_start_at' => 'date',
            'planned_end_at' => 'date',
            'completed_at' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PLANNED => 'Planejada',
            self::STATUS_IN_PROGRESS => 'Em andamento',
            self::STATUS_COMPLETED => 'Concluída',
            self::STATUS_BLOCKED => 'Com impedimento',
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

    public function commitments(): HasMany
    {
        return $this->hasMany(FinancialCommitment::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AmendmentDocument::class);
    }

    public function isOverdue(): bool
    {
        return $this->status !== self::STATUS_COMPLETED
            && $this->planned_end_at !== null
            && $this->planned_end_at->isBefore(today());
    }
}
