<?php

namespace App\Http\Controllers;

use App\Models\ParliamentaryAmendment;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $municipality = $request->user()->municipalities()->firstOrFail();
        $query = $municipality->amendments();
        $amendments = (clone $query)->get();

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
            'deadlines' => $deadlines,
            'recentAmendments' => (clone $query)->latest()->limit(5)->get(),
        ]);
    }
}
