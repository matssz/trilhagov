<?php

namespace App\Http\Controllers;

use App\Models\ParliamentaryAmendment;
use App\Services\AmendmentAnalyticsService;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        IntegrityAlertService $integrityAlertService,
        AmendmentAnalyticsService $analyticsService,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $integrityAlertService->sync($municipality);
        $filters = $request->only(['year', 'sphere', 'status', 'department']);
        $analytics = $analyticsService->dashboard($municipality, $filters);
        $options = $analyticsService->filterOptions($municipality);
        $amendments = $analytics['amendments'];

        $deadlines = $amendments
            ->map(fn (ParliamentaryAmendment $amendment) => [
                'amendment' => $amendment,
                'deadline' => $amendment->nextDeadline(),
            ])
            ->filter(fn (array $item) => $item['deadline'] !== null)
            ->sortBy(fn (array $item) => $item['deadline']['date'])
            ->take(6);

        $isManager = $request->user()->roleForMunicipality($municipality->id) === 'manager';

        return view('dashboard', [
            'municipality' => $municipality,
            'analytics' => $analytics,
            'filters' => $filters,
            'years' => $options['years'],
            'departments' => $options['departments'],
            'statuses' => ParliamentaryAmendment::statuses(),
            'spheres' => ParliamentaryAmendment::governmentSpheres(),
            'deadlines' => $deadlines,
            'recentAmendments' => $amendments->take(5),
            'canEdit' => $request->user()->canEditMunicipality($municipality->id),
            'isManager' => $isManager,
            'transparencyToken' => $isManager
                ? $formSubmission->issue($request, "transparency-settings-{$municipality->id}")
                : null,
        ]);
    }
}
