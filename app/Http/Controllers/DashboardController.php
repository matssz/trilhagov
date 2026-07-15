<?php

namespace App\Http\Controllers;

use App\Models\ParliamentaryAmendment;
use App\Services\CurrentMunicipality;
use App\Services\IntegrityAlertService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        IntegrityAlertService $integrityAlertService,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $integrityAlertService->sync($municipality);
        $query = $municipality->amendments();
        $amendments = (clone $query)->with('responsibleUser')->get();

        $deadlines = $amendments
            ->map(fn (ParliamentaryAmendment $amendment) => [
                'amendment' => $amendment,
                'deadline' => $amendment->nextDeadline(),
            ])
            ->filter(fn (array $item) => $item['deadline'] !== null)
            ->sortBy(fn (array $item) => $item['deadline']['date'])
            ->take(6);

        return view('dashboard', [
            'municipality' => $municipality,
            'amendmentCount' => $amendments->count(),
            'expectedTotal' => $amendments->sum('expected_amount'),
            'receivedTotal' => $amendments->sum('received_amount'),
            'overdueCount' => $amendments->filter->hasOverdueDeadline()->count(),
            'highRiskCount' => $amendments
                ->whereIn('risk_level', [ParliamentaryAmendment::RISK_HIGH, ParliamentaryAmendment::RISK_CRITICAL])
                ->count(),
            'deadlines' => $deadlines,
            'recentAmendments' => (clone $query)->with('responsibleUser')->latest()->limit(5)->get(),
            'canEdit' => $request->user()->canEditMunicipality($municipality->id),
        ]);
    }
}
