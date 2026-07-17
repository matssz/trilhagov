<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MunicipalWorkPlanStage extends Model
{
    protected $fillable = [
        'municipality_id',
        'parliamentary_amendment_id',
        'created_by',
        'title',
        'physical_delivery',
        'planned_amount',
        'planned_start_at',
        'planned_end_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'planned_amount' => 'decimal:2',
            'planned_start_at' => 'date',
            'planned_end_at' => 'date',
            'sort_order' => 'integer',
        ];
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
