<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MunicipalAuditProcedure extends Model
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_COMPLIANT = 'compliant';

    public const STATUS_EXCEPTION = 'exception';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    protected $fillable = [
        'municipality_id', 'municipal_audit_program_id', 'created_by', 'executed_by',
        'sequence', 'title', 'objective', 'test_method', 'sample_description',
        'expected_evidence', 'status', 'result', 'executed_at',
    ];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'executed_at' => 'datetime'];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PLANNED => 'A executar',
            self::STATUS_COMPLIANT => 'Sem exceção',
            self::STATUS_EXCEPTION => 'Com achado',
            self::STATUS_NOT_APPLICABLE => 'Não aplicável',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(MunicipalAuditProgram::class, 'municipal_audit_program_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(MunicipalAuditEvidence::class)->oldest('created_at')->oldest('id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(MunicipalAuditFinding::class);
    }
}
