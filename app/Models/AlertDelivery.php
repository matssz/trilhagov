<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'integrity_alert_id',
        'user_id',
        'channel',
        'cycle_key',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(IntegrityAlert::class, 'integrity_alert_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
