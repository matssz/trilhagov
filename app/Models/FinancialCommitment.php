<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialCommitment extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'municipality_id',
        'execution_stage_id',
        'created_by',
        'commitment_number',
        'supplier_name',
        'supplier_document',
        'procurement_process',
        'object_description',
        'committed_amount',
        'committed_at',
        'status',
        'cancellation_reason',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'committed_amount' => 'decimal:2',
            'committed_at' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function executionStage(): BelongsTo
    {
        return $this->belongsTo(ExecutionStage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancialPayment::class)
            ->orderByDesc('paid_at')
            ->orderByDesc('id');
    }

    public function paidAmount(): float
    {
        return (float) ($this->payments_sum_amount ?? $this->payments()->sum('amount'));
    }

    public function remainingAmount(): float
    {
        return max(0, (float) $this->committed_amount - $this->paidAmount());
    }
}
