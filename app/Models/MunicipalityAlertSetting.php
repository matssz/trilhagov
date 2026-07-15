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
        'escalation_level_one_days' => 1,
        'escalation_level_two_days' => 7,
        'notify_managers_on_warning' => true,
        'notify_editors_on_level_two' => true,
    ];

    protected $fillable = [
        'deadline_warning_days',
        'deadline_critical_days',
        'overdue_repeat_days',
        'escalation_level_one_days',
        'escalation_level_two_days',
        'notify_managers_on_warning',
        'notify_editors_on_level_two',
    ];

    protected function casts(): array
    {
        return [
            'deadline_warning_days' => 'integer',
            'deadline_critical_days' => 'integer',
            'overdue_repeat_days' => 'integer',
            'escalation_level_one_days' => 'integer',
            'escalation_level_two_days' => 'integer',
            'notify_managers_on_warning' => 'boolean',
            'notify_editors_on_level_two' => 'boolean',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }
}
