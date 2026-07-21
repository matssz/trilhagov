<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MunicipalAuditPlanItem extends Model
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESCHEDULED = 'rescheduled';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'municipality_id', 'municipal_audit_plan_id', 'parliamentary_amendment_id',
        'assigned_user_id', 'created_by', 'completed_by', 'phase', 'priority',
        'frequency', 'status', 'planned_at', 'scope_notes', 'status_notes',
        'completed_at',
    ];

    protected function casts(): array
    {
        return ['planned_at' => 'date', 'completed_at' => 'datetime'];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PLANNED => 'Planejada',
            self::STATUS_IN_PROGRESS => 'Em andamento',
            self::STATUS_RESCHEDULED => 'Reprogramada',
            self::STATUS_COMPLETED => 'Concluída',
            self::STATUS_CANCELLED => 'Cancelada',
        ];
    }

    public static function priorities(): array
    {
        return ['critical' => 'Crítica', 'high' => 'Alta', 'normal' => 'Normal'];
    }

    public static function frequencies(): array
    {
        return [
            'once' => 'Verificação única',
            'monthly' => 'Mensal',
            'quarterly' => 'Trimestral',
            'milestones' => 'Por marcos da execução',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function priorityLabel(): string
    {
        return self::priorities()[$this->priority] ?? $this->priority;
    }

    public function frequencyLabel(): string
    {
        return self::frequencies()[$this->frequency] ?? $this->frequency;
    }

    public function phaseLabel(): string
    {
        return MunicipalInternalControlReview::phases()[$this->phase] ?? $this->phase;
    }

    public function formalReference(): string
    {
        return $this->plan->reference().' · item '.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [self::STATUS_PLANNED, self::STATUS_IN_PROGRESS, self::STATUS_RESCHEDULED], true)
            && $this->planned_at->isBefore(today());
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MunicipalAuditPlan::class, 'municipal_audit_plan_id');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(MunicipalInternalControlReview::class);
    }

    public function program(): HasOne
    {
        return $this->hasOne(MunicipalAuditProgram::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MunicipalAuditPlanItemEvent::class)
            ->latest('created_at')
            ->latest('id');
    }
}
