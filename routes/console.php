<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('alerts:process')
    ->hourly()
    ->withoutOverlapping(30);

Schedule::command('work-items:sync')
    ->hourlyAt(15)
    ->withoutOverlapping(30);
