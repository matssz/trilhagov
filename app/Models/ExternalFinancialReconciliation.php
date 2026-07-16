<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalFinancialReconciliation extends Model
{
    public const STATUS_CONSISTENT = 'consistent';

    public const STATUS_DIVERGENT = 'divergent';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_UNLINKED = 'unlinked';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'municipality_id',
        'external_amendment_candidate_id',
        'parliamentary_amendment_id',
        'initiated_by',
        'source',
        'status',
        'official_committed_amount',
        'official_ordered_amount',
        'official_account_balance',
        'local_expected_amount',
        'local_received_amount',
        'local_committed_amount',
        'local_paid_amount',
        'local_estimated_balance',
        'differences',
        'official_commitments',
        'official_payment_orders',
        'official_account_data',
        'error_message',
        'source_updated_at',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'official_committed_amount' => 'decimal:2',
            'official_ordered_amount' => 'decimal:2',
            'official_account_balance' => 'decimal:2',
            'local_expected_amount' => 'decimal:2',
            'local_received_amount' => 'decimal:2',
            'local_committed_amount' => 'decimal:2',
            'local_paid_amount' => 'decimal:2',
            'local_estimated_balance' => 'decimal:2',
            'differences' => 'array',
            'official_commitments' => 'array',
            'official_payment_orders' => 'array',
            'official_account_data' => 'array',
            'source_updated_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_CONSISTENT => 'Valores conciliados',
            self::STATUS_DIVERGENT => 'Requer conferência',
            self::STATUS_INCOMPLETE => 'Dados insuficientes',
            self::STATUS_UNLINKED => 'Aguardando vínculo',
            self::STATUS_FAILED => 'Consulta indisponível',
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

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ExternalAmendmentCandidate::class, 'external_amendment_candidate_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
