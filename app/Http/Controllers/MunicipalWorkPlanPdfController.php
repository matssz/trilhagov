<?php

namespace App\Http\Controllers;

use App\Models\MunicipalAdmissibilityReview;
use App\Models\MunicipalWorkPlan;
use App\Services\CurrentMunicipality;
use App\Services\MunicipalWorkPlanService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MunicipalWorkPlanPdfController extends Controller
{
    public function __invoke(Request $request, int $emenda, CurrentMunicipality $currentMunicipality, MunicipalWorkPlanService $workPlanService): Response
    {
        $amendment = $currentMunicipality->get($request)
            ->amendments()
            ->with(['municipality', 'municipalWorkPlan.stages', 'municipalWorkPlan.reviews.reviewer'])
            ->findOrFail($emenda);
        abort_unless($amendment->supportsTcespCompliance(), 404);
        $plan = $amendment->municipalWorkPlan ?? abort(404);

        return Pdf::loadView('amendments.work-plan-pdf', [
            'amendment' => $amendment,
            'plan' => $plan,
            'readiness' => $workPlanService->readiness($plan, $amendment),
            'criteria' => $workPlanService->admissibilityCriteria(),
            'criterionStatuses' => MunicipalAdmissibilityReview::criterionStatuses(),
            'beneficiaryTypes' => MunicipalWorkPlan::beneficiaryTypes(),
            'engineeringStatuses' => MunicipalWorkPlan::engineeringStatuses(),
            'pcaStatuses' => MunicipalWorkPlan::pcaStatuses(),
        ])->setPaper('a4')->download('plano-de-trabalho-'.str($amendment->reference)->slug().'.pdf');
    }
}
