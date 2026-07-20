<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class FinancialLiquidation extends Model
{
    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'created_by',
        'liquidation_reference',
        'amount',
        'liquidated_at',
        'supporting_document',
        'acceptance_reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'liquidated_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Liquidações registradas não podem ser alteradas.'));
        static::deleting(fn () => throw new LogicException('Liquidações registradas não podem ser excluídas.'));
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function commitment(): BelongsTo
    {
        return $this->belongsTo(FinancialCommitment::class, 'financial_commitment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancialPayment::class);
    }

    public function paidAmount(): float
    {
        return (float) ($this->payments_sum_amount ?? $this->payments()->sum('amount'));
    }

    public function availableAmount(): float
    {
        return max(0, (float) $this->amount - $this->paidAmount());
    }
}
