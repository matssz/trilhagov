<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MunicipalAuditProgram extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_CONCLUDED = 'concluded';

    protected $fillable = [
        'municipality_id', 'municipal_audit_plan_item_id', 'lead_auditor_id',
        'supervisor_id', 'created_by', 'reviewed_by', 'concluded_by', 'status',
        'title', 'objective', 'scope', 'sampling_method', 'population_description',
        'population_size', 'sample_size', 'materiality_criteria', 'start_at',
        'due_at', 'supervisor_notes', 'conclusion', 'snapshot', 'snapshot_sha256',
        'submitted_at', 'reviewed_at', 'concluded_at',
    ];

    protected function casts(): array
    {
        return [
            'population_size' => 'integer',
            'sample_size' => 'integer',
            'start_at' => 'date',
            'due_at' => 'date',
            'snapshot' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'concluded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $program): void {
            if ($program->getOriginal('status') === self::STATUS_CONCLUDED) {
                throw new LogicException('Um Programa de Auditoria concluído não pode ser alterado.');
            }
        });
        static::deleting(fn (self $program) => throw new LogicException('Programas de Auditoria não podem ser excluídos.'));
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Em preparação',
            self::STATUS_IN_PROGRESS => 'Em execução',
            self::STATUS_UNDER_REVIEW => 'Em revisão',
            self::STATUS_RETURNED => 'Devolvido',
            self::STATUS_APPROVED => 'Aprovado',
            self::STATUS_CONCLUDED => 'Concluído',
        ];
    }

    public static function samplingMethods(): array
    {
        return [
            'census' => 'Exame integral (censo)',
            'judgmental' => 'Amostragem por julgamento profissional',
            'random' => 'Amostragem aleatória',
            'statistical' => 'Amostragem estatística',
            'stratified' => 'Amostragem estratificada por risco ou valor',
        ];
    }

    public function reference(): string
    {
        return sprintf('PAT-%d-%05d', $this->planItem->plan->fiscal_year, $this->id);
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_IN_PROGRESS, self::STATUS_RETURNED], true);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function planItem(): BelongsTo
    {
        return $this->belongsTo(MunicipalAuditPlanItem::class, 'municipal_audit_plan_item_id');
    }

    public function leadAuditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_auditor_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function concludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concluded_by');
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(MunicipalAuditProcedure::class)->orderBy('sequence');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(MunicipalAuditFinding::class)->latest('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MunicipalAuditProgramEvent::class)->latest('created_at')->latest('id');
    }
}
