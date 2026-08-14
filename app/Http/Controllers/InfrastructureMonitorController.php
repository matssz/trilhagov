<?php

namespace App\Http\Controllers;

use App\Services\InfrastructureMonitorService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InfrastructureMonitorController extends Controller
{
    public function __invoke(Request $request, InfrastructureMonitorService $monitor): View
    {
        return view('infrastructure-monitor.index', [
            'municipality' => $request->attributes->get('active_municipality'),
            'snapshot' => $monitor->snapshot(),
        ]);
    }
}
