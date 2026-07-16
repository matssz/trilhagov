<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountabilityRequirement extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'amendment_document_id',
        'completed_by',
        'created_by',
        'category',
        'title',
        'description',
        'is_required',
        'status',
        'notes',
        'completed_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'completed_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            'document' => 'Documento',
            'physical' => 'Execução física',
            'financial' => 'Financeiro',
            'protocol' => 'Protocolo',
            'other' => 'Outro',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_COMPLETED => 'Concluído',
            self::STATUS_NOT_APPLICABLE => 'Não aplicável',
        ];
    }

    public function categoryLabel(): string
    {
        return self::categories()[$this->category] ?? $this->category;
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(AccountabilityProcess::class, 'accountability_process_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(AmendmentDocument::class, 'amendment_document_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
