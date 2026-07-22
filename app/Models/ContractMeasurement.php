<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ContractMeasurement extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'municipality_id', 'parliamentary_amendment_id', 'municipal_contract_id',
        'created_by', 'reviewed_by', 'evidence_document_id', 'sequence', 'status',
        'period_start_at', 'period_end_at', 'measured_at', 'amount',
        'cumulative_physical_percentage', 'notes', 'review_notes', 'reviewed_at',
        'snapshot', 'snapshot_sha256',
    ];

    protected function casts(): array
    {
        return [
            'period_start_at' => 'date',
            'period_end_at' => 'date',
            'measured_at' => 'date',
            'amount' => 'decimal:2',
            'cumulative_physical_percentage' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $measurement): void {
            if (in_array($measurement->getOriginal('status'), [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
                throw new LogicException('Medição decidida não pode ser alterada. Registre uma nova medição.');
            }
        });
        static::deleting(function (self $measurement): void {
            if ($measurement->status !== self::STATUS_DRAFT) {
                throw new LogicException('Medição decidida não pode ser excluída.');
            }
        });
    }

    public function statusLabel(): string
    {
        return [self::STATUS_DRAFT => 'Aguardando ateste', self::STATUS_APPROVED => 'Atestada', self::STATUS_REJECTED => 'Rejeitada'][$this->status] ?? $this->status;
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(MunicipalContract::class, 'municipal_contract_id');
    }

    public function evidenceDocument(): BelongsTo
    {
        return $this->belongsTo(AmendmentDocument::class, 'evidence_document_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
