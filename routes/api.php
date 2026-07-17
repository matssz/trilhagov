<?php

use App\Http\Controllers\ScheduledTaskController;
use Illuminate\Support\Facades\Route;

Route::post('/internal/scheduler', ScheduledTaskController::class)
    ->middleware('throttle:2,1');
