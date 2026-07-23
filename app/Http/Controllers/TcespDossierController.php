<?php

namespace App\Http\Controllers;

use App\Models\AmendmentComplianceReview;
use App\Models\IntegrityAlert;
use App\Models\ParliamentaryAmendment;
use App\Services\CurrentMunicipality;
use App\Services\TcespComplianceFramework;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TcespDossierController extends Controller
{
    public function __invoke(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        TcespComplianceFramework $framework,
    ): Response {
        $amendment = $currentMunicipality->get($request)
            ->amendments()
            ->with([
                'municipality',
                'responsibleUser',
                'documents.documentType',
                'documents.executionStage',
                'complianceReviews.document.documentType',
                'complianceReviews.reviewer',
                'integrityAlerts' => fn ($query) => $query->where('status', IntegrityAlert::STATUS_OPEN)->latest('severity')->latest('detected_at'),
                'municipalWorkPlan.stages',
                'technicalImpediments.diligences',
                'audespRegistration',
                'financialCommitments.payments',
                'financialCommitments.liquidations.payments',
                'financialPayments',
                'executionStages.responsibleUser',
                'internalControlReviews.actions',
                'accountabilityProcess.requirements',
            ])
            ->findOrFail($emenda);

        abort_unless($framework->appliesTo($amendment), 404);

        $matrix = $framework->matrix($amendment);

        return Pdf::loadView('amendments.tcesp-dossier', [
            'amendment' => $amendment,
            'matrix' => $matrix,
            'summary' => $framework->summary($matrix),
            'categories' => $framework->categories(),
            'statuses' => AmendmentComplianceReview::statuses(),
            'sourceLabel' => TcespComplianceFramework::SOURCE_LABEL,
            'frameworkVersion' => TcespComplianceFramework::VERSION,
            'generatedAt' => now(),
        ])->setPaper('a4')->download($this->filename($amendment));
    }

    private function filename(ParliamentaryAmendment $amendment): string
    {
        return 'dossie-tcesp-'.(Str::slug($amendment->reference) ?: $amendment->id).'.pdf';
    }
}
