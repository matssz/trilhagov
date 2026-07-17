<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MunicipalWorkPlan extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_ADJUSTMENTS_REQUESTED = 'adjustments_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'municipality_id',
        'created_by',
        'updated_by',
        'status',
        'revision_number',
        'beneficiary_type',
        'beneficiary_name',
        'beneficiary_cnpj',
        'beneficiary_contact',
        'object_description',
        'public_need',
        'physical_target',
        'finalistic_target',
        'budget_program',
        'budget_action',
        'application_plan',
        'cost_memory',
        'maintenance_plan',
        'health_related',
        'health_reserve_verified',
        'includes_engineering',
        'engineering_project_status',
        'environmental_license_status',
        'pca_status',
        'planned_start_at',
        'planned_end_at',
        'submitted_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'health_related' => 'boolean',
            'health_reserve_verified' => 'boolean',
            'includes_engineering' => 'boolean',
            'planned_start_at' => 'date',
            'planned_end_at' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Em elaboração',
            self::STATUS_UNDER_REVIEW => 'Em análise técnica',
            self::STATUS_ADJUSTMENTS_REQUESTED => 'Ajustes solicitados',
            self::STATUS_APPROVED => 'Aprovado',
            self::STATUS_REJECTED => 'Rejeitado',
        ];
    }

    /** @return array<string, string> */
    public static function beneficiaryTypes(): array
    {
        return [
            'municipal_body' => 'Órgão municipal executor',
            'osc' => 'Organização da sociedade civil',
            'other' => 'Outro beneficiário',
        ];
    }

    /** @return array<string, string> */
    public static function engineeringStatuses(): array
    {
        return [
            'not_applicable' => 'Não se aplica',
            'ready' => 'Disponível e aprovado',
            'pending' => 'Pendente / cláusula suspensiva',
        ];
    }

    /** @return array<string, string> */
    public static function pcaStatuses(): array
    {
        return [
            'not_checked' => 'Ainda não verificado',
            'included' => 'Objeto previsto no PCA',
            'update_requested' => 'Atualização do PCA solicitada',
            'not_applicable' => 'Não se aplica',
        ];
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ADJUSTMENTS_REQUESTED], true);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ParliamentaryAmendment::class, 'parliamentary_amendment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(MunicipalWorkPlanStage::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(MunicipalAdmissibilityReview::class)
            ->latest('plan_revision');
    }
}
