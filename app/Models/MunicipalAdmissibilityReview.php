<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MunicipalAdmissibilityReview extends Model
{
    public const UPDATED_AT = null;

    public const CONCLUSION_APPROVED = 'approved';

    public const CONCLUSION_ADJUSTMENTS = 'adjustments_requested';

    public const CONCLUSION_REJECTED = 'rejected';

    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'reviewed_by',
        'plan_revision',
        'conclusion',
        'criteria',
        'rationale',
        'corrections_requested',
        'plan_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'plan_revision' => 'integer',
            'criteria' => 'array',
            'plan_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Pareceres emitidos não podem ser alterados.'));
        static::deleting(fn () => throw new LogicException('Pareceres emitidos não podem ser excluídos.'));
    }

    /** @return array<string, string> */
    public static function conclusions(): array
    {
        return [
            self::CONCLUSION_APPROVED => 'Aprovado',
            self::CONCLUSION_ADJUSTMENTS => 'Devolvido para ajustes',
            self::CONCLUSION_REJECTED => 'Rejeitado',
        ];
    }

    /** @return array<string, string> */
    public static function criterionStatuses(): array
    {
        return [
            'met' => 'Atendido',
            'not_met' => 'Não atendido',
            'not_applicable' => 'Não se aplica',
        ];
    }

    public function conclusionLabel(): string
    {
        return self::conclusions()[$this->conclusion] ?? $this->conclusion;
    }

    public function workPlan(): BelongsTo
    {
        return $this->belongsTo(MunicipalWorkPlan::class, 'municipal_work_plan_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
