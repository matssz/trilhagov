<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AmendmentDocument extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'document_type_id',
        'execution_stage_id',
        'uploaded_by',
        'uploader_name',
        'original_name',
        'storage_path',
        'mime_type',
        'size_bytes',
        'version',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Documentos anexados não podem ser alterados. Envie uma nova versão.'));
        static::deleting(fn () => throw new LogicException('Documentos anexados não podem ser excluídos.'));
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function executionStage(): BelongsTo
    {
        return $this->belongsTo(ExecutionStage::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function accountabilityRequirements(): HasMany
    {
        return $this->hasMany(AccountabilityRequirement::class);
    }

    public function formattedSize(): string
    {
        if ($this->size_bytes < 1024 * 1024) {
            return number_format(max(1, (int) ceil($this->size_bytes / 1024)), 0, ',', '.').' KB';
        }

        return number_format($this->size_bytes / (1024 * 1024), 1, ',', '.').' MB';
    }
}
