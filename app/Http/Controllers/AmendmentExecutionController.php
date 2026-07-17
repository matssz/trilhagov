<?php

namespace App\Http\Controllers;

use App\Models\ExecutionStage;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AmendmentExecutionController extends Controller
{
    public function __invoke(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        IntegrityAlertService $integrityAlertService,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $integrityAlertService->syncIfDue($municipality);
        $amendment = $municipality->amendments()
            ->with([
                'municipality',
                'responsibleUser',
                'executionStages.responsibleUser',
                'executionStages.documents.documentType',
                'documents.documentType',
                'documents.executionStage',
                'financialCommitments.executionStage',
                'financialCommitments.payments.creator',
            ])
            ->findOrFail($emenda);
        $canEdit = $request->user()->canEditMunicipality($municipality->id);
        $activeCommitments = $amendment->financialCommitments->where('status', 'active');
        $committedAmount = (float) $activeCommitments->sum('committed_amount');
        $paidAmount = (float) $activeCommitments->sum(fn ($commitment) => $commitment->payments->sum('amount'));
        $receivedAmount = (float) ($amendment->received_amount ?? 0);

        return view('amendments.execution', [
            'amendment' => $amendment,
            'canEdit' => $canEdit,
            'stageStatuses' => ExecutionStage::statuses(),
            'responsibleUsers' => $municipality->users()
                ->wherePivotIn('role', ['manager', 'editor'])
                ->orderBy('name')
                ->get(),
            'documentTypes' => $municipality->documentTypes()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'committedAmount' => $committedAmount,
            'paidAmount' => $paidAmount,
            'receivedAmount' => $receivedAmount,
            'availableBalance' => $receivedAmount - $paidAmount,
            'uncommittedBalance' => $receivedAmount - $committedAmount,
            'financialPercentage' => $receivedAmount > 0 ? (int) round(($paidAmount / $receivedAmount) * 100) : 0,
            'physicalPercentage' => $amendment->physicalExecutionPercentage(),
            'stageCreateToken' => $canEdit ? $formSubmission->issue($request, "execution-stage-create-{$amendment->id}") : null,
            'documentSubmissionToken' => $canEdit
                ? $formSubmission->issue($request, "amendment-document-upload-{$amendment->id}")
                : null,
            'stageUpdateTokens' => $canEdit
                ? $amendment->executionStages->mapWithKeys(fn ($stage) => [
                    $stage->id => $formSubmission->issue($request, "execution-stage-update-{$stage->id}"),
                ])
                : collect(),
            'commitmentCreateToken' => $canEdit ? $formSubmission->issue($request, "financial-commitment-create-{$amendment->id}") : null,
            'paymentCreateTokens' => $canEdit
                ? $amendment->financialCommitments->mapWithKeys(fn ($commitment) => [
                    $commitment->id => $formSubmission->issue($request, "financial-payment-create-{$commitment->id}"),
                ])
                : collect(),
            'commitmentCancelTokens' => $canEdit
                ? $amendment->financialCommitments->mapWithKeys(fn ($commitment) => [
                    $commitment->id => $formSubmission->issue($request, "financial-commitment-cancel-{$commitment->id}"),
                ])
                : collect(),
        ]);
    }
}
