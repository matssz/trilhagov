<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MunicipalAuditEvidence extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'municipal_audit_evidences';

    protected $fillable = [
        'municipality_id', 'municipal_audit_procedure_id', 'uploaded_by',
        'uploader_name', 'description', 'original_name', 'storage_path',
        'mime_type', 'size_bytes', 'sha256',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evidências de auditoria não podem ser alteradas.'));
        static::deleting(fn () => throw new LogicException('Evidências de auditoria não podem ser excluídas.'));
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(MunicipalAuditProcedure::class, 'municipal_audit_procedure_id');
    }
}
