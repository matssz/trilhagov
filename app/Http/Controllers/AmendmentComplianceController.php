<?php

namespace App\Http\Controllers;

use App\Models\AmendmentComplianceReview;
use App\Models\ParliamentaryAmendment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\TcespComplianceFramework;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AmendmentComplianceController extends Controller
{
    public function index(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        TcespComplianceFramework $framework,
        FormSubmission $formSubmission,
    ): View {
        $amendment = $this->amendment($request, $emenda, $currentMunicipality)
            ->load([
                'municipality',
                'documents.documentType',
                'complianceReviews.document.documentType',
                'complianceReviews.reviewer',
            ]);
        abort_unless($framework->appliesTo($amendment), 404);

        $matrix = $framework->matrix($amendment);
        $canEdit = $request->user()->canEditMunicipality($amendment->municipality_id);

        return view('amendments.compliance', [
            'amendment' => $amendment,
            'matrix' => $matrix,
            'groupedMatrix' => $matrix->groupBy('category'),
            'summary' => $framework->summary($matrix),
            'categories' => $framework->categories(),
            'statuses' => AmendmentComplianceReview::statuses(),
            'canEdit' => $canEdit,
            'sourceLabel' => TcespComplianceFramework::SOURCE_LABEL,
            'sourceUrl' => TcespComplianceFramework::SOURCE_URL,
            'frameworkVersion' => TcespComplianceFramework::VERSION,
            'reviewTokens' => $canEdit
                ? $matrix->mapWithKeys(fn (array $item) => [
                    $item['code'] => $formSubmission->issue($request, "compliance-review-{$amendment->id}-{$item['code']}"),
                ])
                : collect(),
        ]);
    }

    public function update(
        Request $request,
        int $emenda,
        string $regra,
        CurrentMunicipality $currentMunicipality,
        TcespComplianceFramework $framework,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $amendment = $this->amendment($request, $emenda, $currentMunicipality)->load('municipality');
        abort_unless($framework->appliesTo($amendment) && $framework->hasRule($regra), 404);

        if (! $formSubmission->consume($request, "compliance-review-{$amendment->id}-{$regra}")) {
            return redirect()
                ->route('emendas.compliance', $amendment)
                ->with('warning', 'Esta revisão já foi processada.');
        }

        $status = (string) $request->input('status');
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(AmendmentComplianceReview::statuses()))],
            'evidence_notes' => [
                'nullable',
                Rule::requiredIf(in_array($status, [
                    AmendmentComplianceReview::STATUS_NON_COMPLIANT,
                    AmendmentComplianceReview::STATUS_NOT_APPLICABLE,
                ], true)),
                'string',
                'max:5000',
            ],
            'amendment_document_id' => [
                'nullable',
                'integer',
                Rule::exists('amendment_documents', 'id')->where(fn ($query) => $query
                    ->where('municipality_id', $amendment->municipality_id)
                    ->where('parliamentary_amendment_id', $amendment->id)),
            ],
        ], [
            'evidence_notes.required' => 'Descreva a constatação ou a justificativa para esta situação.',
            'amendment_document_id.exists' => 'Selecione um documento pertencente a esta emenda.',
        ]);

        if ($status === AmendmentComplianceReview::STATUS_COMPLIANT
            && blank($validated['evidence_notes'] ?? null)
            && blank($validated['amendment_document_id'] ?? null)) {
            throw ValidationException::withMessages([
                'evidence_notes' => 'Para marcar como atendido, descreva a evidência ou vincule um documento.',
            ]);
        }

        if ($status === AmendmentComplianceReview::STATUS_PENDING) {
            $validated['evidence_notes'] = null;
            $validated['amendment_document_id'] = null;
        }

        DB::transaction(function () use ($request, $amendment, $regra, $status, $validated, $auditTrail): void {
            $review = $amendment->complianceReviews()
                ->where('framework_version', TcespComplianceFramework::VERSION)
                ->where('rule_code', $regra)
                ->first();
            $oldValues = $review?->only(['status', 'evidence_notes', 'amendment_document_id']);

            $review = $amendment->complianceReviews()->updateOrCreate(
                [
                    'framework_version' => TcespComplianceFramework::VERSION,
                    'rule_code' => $regra,
                ],
                [
                    'municipality_id' => $amendment->municipality_id,
                    'status' => $status,
                    'evidence_notes' => $validated['evidence_notes'] ?? null,
                    'amendment_document_id' => $validated['amendment_document_id'] ?? null,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => $status === AmendmentComplianceReview::STATUS_PENDING ? null : now(),
                ],
            );

            $documentName = $review->document()->value('original_name');
            $auditTrail->recordOperation($request, $amendment, 'compliance_review_updated', [
                'compliance_rule' => $regra,
                'compliance_status' => $status,
                'compliance_evidence' => $review->evidence_notes,
                'compliance_document' => $documentName,
                'compliance_framework' => TcespComplianceFramework::VERSION,
            ], $oldValues === null ? null : [
                'compliance_rule' => $regra,
                'compliance_status' => $oldValues['status'],
                'compliance_evidence' => $oldValues['evidence_notes'],
                'compliance_document' => $oldValues['amendment_document_id'] === null
                    ? null
                    : $amendment->documents()->whereKey($oldValues['amendment_document_id'])->value('original_name'),
                'compliance_framework' => TcespComplianceFramework::VERSION,
            ]);
        });

        return redirect()
            ->to(route('emendas.compliance', $amendment).'#regra-'.$regra)
            ->with('status', 'Revisão de conformidade salva.');
    }

    private function amendment(Request $request, int $id, CurrentMunicipality $currentMunicipality): ParliamentaryAmendment
    {
        return $currentMunicipality->get($request)->amendments()->findOrFail($id);
    }
}
