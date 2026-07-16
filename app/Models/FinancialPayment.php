<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FinancialPayment extends Model
{
    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'created_by',
        'payment_reference',
        'amount',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Pagamentos registrados não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Pagamentos registrados não podem ser excluídos.'));
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
}
