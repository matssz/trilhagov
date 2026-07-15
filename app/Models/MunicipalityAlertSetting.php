<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MunicipalityAlertSetting extends Model
{
    protected $attributes = [
        'deadline_warning_days' => 30,
        'deadline_critical_days' => 7,
        'overdue_repeat_days' => 7,
    ];

    protected $fillable = [
        'deadline_warning_days',
        'deadline_critical_days',
        'overdue_repeat_days',
    ];

    protected function casts(): array
    {
        return [
            'deadline_warning_days' => 'integer',
            'deadline_critical_days' => 'integer',
            'overdue_repeat_days' => 'integer',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }
}
