<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MunicipalReportDispatchDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = ['municipal_report_dispatch_id', 'user_id', 'channel', 'cycle_key', 'delivered_at'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(MunicipalReportDispatch::class, 'municipal_report_dispatch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
