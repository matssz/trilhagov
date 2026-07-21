<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MunicipalAuditProgramEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'municipality_id', 'municipal_audit_program_id', 'user_id', 'actor_name',
        'event_type', 'description', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos de auditoria não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Eventos de auditoria não podem ser excluídos.'));
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(MunicipalAuditProgram::class, 'municipal_audit_program_id');
    }
}
