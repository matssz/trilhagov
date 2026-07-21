<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MunicipalAuditFinding extends Model
{
    protected $fillable = [
        'municipality_id', 'municipal_audit_program_id', 'municipal_audit_procedure_id',
        'created_by', 'severity', 'title', 'criteria', 'condition', 'cause',
        'effect', 'recommendation', 'recommended_due_at',
    ];

    protected function casts(): array
    {
        return ['recommended_due_at' => 'date'];
    }

    public static function severities(): array
    {
        return ['low' => 'Baixa', 'moderate' => 'Moderada', 'high' => 'Alta', 'critical' => 'Crítica'];
    }

    public function severityLabel(): string
    {
        return self::severities()[$this->severity] ?? $this->severity;
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(MunicipalAuditProgram::class, 'municipal_audit_program_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(MunicipalAuditProcedure::class, 'municipal_audit_procedure_id');
    }
}
