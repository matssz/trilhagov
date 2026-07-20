<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AudespHomologationEvent extends Model
{
    protected $fillable = [
        'municipality_id',
        'audesp_homologation_batch_id',
        'created_by',
        'type',
        'external_status',
        'protocol',
        'occurred_at',
        'issue_code',
        'issue_field',
        'message',
        'evidence_original_name',
        'evidence_storage_path',
        'evidence_mime_type',
        'evidence_size_bytes',
        'evidence_sha256',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'evidence_size_bytes' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos de homologação Audesp não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Eventos de homologação Audesp não podem ser excluídos.'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AudespHomologationBatch::class, 'audesp_homologation_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
