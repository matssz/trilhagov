<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ContractAddendum extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'contract_addenda';

    protected $fillable = [
        'municipality_id', 'parliamentary_amendment_id', 'municipal_contract_id',
        'created_by', 'reviewed_by', 'evidence_document_id', 'sequence', 'type',
        'status', 'value_change', 'days_change', 'justification', 'technical_basis',
        'effective_at', 'signed_at', 'publication_reference', 'published_at',
        'advance_effects_justification', 'review_notes', 'reviewed_at', 'snapshot',
        'snapshot_sha256',
    ];

    protected function casts(): array
    {
        return [
            'value_change' => 'decimal:2',
            'days_change' => 'integer',
            'effective_at' => 'date',
            'signed_at' => 'date',
            'published_at' => 'date',
            'reviewed_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $addendum): void {
            if (in_array($addendum->getOriginal('status'), [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
                throw new LogicException('Termo aditivo decidido não pode ser alterado. Registre um novo termo.');
            }
        });
        static::deleting(function (self $addendum): void {
            if ($addendum->status !== self::STATUS_DRAFT) {
                throw new LogicException('Termo aditivo decidido não pode ser excluído.');
            }
        });
    }

    public function typeLabel(): string
    {
        return [
            'increase' => 'Acréscimo quantitativo', 'decrease' => 'Supressão quantitativa',
            'extension' => 'Prorrogação de prazo', 'rebalance' => 'Reequilíbrio econômico-financeiro',
            'project_change' => 'Alteração de projeto ou especificação',
        ][$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return [self::STATUS_DRAFT => 'Em análise', self::STATUS_APPROVED => 'Formalizado', self::STATUS_REJECTED => 'Rejeitado'][$this->status] ?? $this->status;
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
