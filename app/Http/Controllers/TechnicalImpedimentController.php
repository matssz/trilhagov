<?php

namespace App\Http\Controllers;

use App\Models\TechnicalImpediment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\MunicipalRuleApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TechnicalImpedimentController extends Controller
{
    public function index(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalRuleApplicationService $municipalRules,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()
            ->with([
                'municipality',
                'responsibleUser',
                'regulatoryProfile',
                'documents.documentType',
                'technicalImpediments.assignedUser',
                'technicalImpediments.evidenceDocument.documentType',
                'technicalImpediments.diligences.assignedUser',
                'technicalImpediments.diligences.evidenceDocument.documentType',
                'technicalImpediments.remappings.requester',
                'technicalImpediments.remappings.decider',
            ])
            ->findOrFail($emenda);
        $canEdit = $request->user()->canEditMunicipality($municipality->id);
        $isManager = $request->user()->roleForMunicipality($municipality->id) === 'manager';
        $impediments = $amendment->technicalImpediments;
        $openImpediments = $impediments->filter->isOpen();
        $openDiligences = $impediments->flatMap->diligences
            ->where('status', 'open');

        return view('amendments.impediments', [
            'amendment' => $amendment,
            'impediments' => $impediments,
            'canEdit' => $canEdit,
            'isManager' => $isManager,
            'categories' => TechnicalImpediment::categories(),
            'natures' => TechnicalImpediment::natures(),
            'statuses' => TechnicalImpediment::statuses(),
            'availableStatuses' => $isManager
                ? TechnicalImpediment::statuses()
                : collect(TechnicalImpediment::statuses())->except([
                    TechnicalImpediment::STATUS_CONFIRMED,
                    TechnicalImpediment::STATUS_REMAPPED,
                ])->all(),
            'responsibleUsers' => $municipality->users()
                ->wherePivotIn('role', ['manager', 'editor'])
                ->orderBy('name')
                ->get(),
            'documents' => $amendment->documents->sortByDesc('created_at'),
            'summary' => [
                'total' => $impediments->count(),
                'open' => $openImpediments->count(),
                'overdue' => $openImpediments->filter->isOverdue()->count() + $openDiligences->filter->isOverdue()->count(),
                'insurmountable' => $impediments->where('nature', TechnicalImpediment::NATURE_INSURMOUNTABLE)->count(),
            ],
            'regulatoryProfile' => $amendment->regulatoryProfile,
            'suggestedResolutionDueAt' => $municipalRules->suggestedImpedimentDueDate($amendment, today()),
            'suggestedCommunicationDueAt' => $municipalRules->suggestedImpedimentCommunicationDate($amendment, today()),
            'createToken' => $canEdit
                ? $formSubmission->issue($request, "technical-impediment-create-{$amendment->id}")
                : null,
            'updateTokens' => $canEdit
                ? $impediments->mapWithKeys(fn (TechnicalImpediment $impediment) => [
                    $impediment->id => $formSubmission->issue($request, "technical-impediment-update-{$impediment->id}"),
                ])
                : collect(),
            'diligenceCreateTokens' => $canEdit
                ? $impediments->mapWithKeys(fn (TechnicalImpediment $impediment) => [
                    $impediment->id => $formSubmission->issue($request, "technical-diligence-create-{$impediment->id}"),
                ])
                : collect(),
            'diligenceUpdateTokens' => $canEdit
                ? $impediments->flatMap->diligences->mapWithKeys(fn ($diligence) => [
                    $diligence->id => $formSubmission->issue($request, "technical-diligence-update-{$diligence->id}"),
                ])
                : collect(),
            'remappingCreateTokens' => $canEdit
                ? $impediments->mapWithKeys(fn (TechnicalImpediment $impediment) => [
                    $impediment->id => $formSubmission->issue($request, "amendment-remapping-create-{$impediment->id}"),
                ])
                : collect(),
            'remappingSubmitTokens' => $canEdit
                ? $impediments->flatMap->remappings->where('status', 'draft')->mapWithKeys(fn ($remapping) => [
                    $remapping->id => $formSubmission->issue($request, "amendment-remapping-submit-{$remapping->id}"),
                ])
                : collect(),
            'remappingUpdateTokens' => $canEdit
                ? $impediments->flatMap->remappings->where('status', 'draft')->mapWithKeys(fn ($remapping) => [
                    $remapping->id => $formSubmission->issue($request, "amendment-remapping-update-{$remapping->id}"),
                ])
                : collect(),
            'remappingDecisionTokens' => $isManager
                ? $impediments->flatMap->remappings->where('status', 'submitted')->mapWithKeys(fn ($remapping) => [
                    $remapping->id => $formSubmission->issue($request, "amendment-remapping-decide-{$remapping->id}"),
                ])
                : collect(),
        ]);
    }

    public function store(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
        MunicipalRuleApplicationService $municipalRules,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->with('regulatoryProfile')->findOrFail($emenda);
        $validated = $request->validate($this->rules($municipality->id, $amendment->id));
        if (blank($validated['resolution_due_at'] ?? null)) {
            $validated['resolution_due_at'] = $municipalRules->suggestedImpedimentDueDate(
                $amendment,
                $validated['identified_at'],
            );
        }
        $validated['communication_due_at'] = $municipalRules->suggestedImpedimentCommunicationDate(
            $amendment,
            $validated['identified_at'],
        );

        if (! $formSubmission->consume($request, "technical-impediment-create-{$amendment->id}")) {
            return back()->with('warning', 'Este impedimento já foi registrado.');
        }

        DB::transaction(function () use ($request, $validated, $municipality, $amendment, $auditTrail): void {
            $impediment = $amendment->technicalImpediments()->create([
                ...$validated,
                'municipality_id' => $municipality->id,
                'created_by' => $request->user()->id,
                'municipal_regulatory_profile_id' => $amendment->municipal_regulatory_profile_id,
                'status' => TechnicalImpediment::STATUS_IDENTIFIED,
            ]);
            $auditTrail->recordOperation($request, $amendment, 'technical_impediment_created', [
                'impediment' => $impediment->title,
                'impediment_category' => $impediment->category,
                'impediment_nature' => $impediment->nature,
                'impediment_due_at' => $impediment->resolution_due_at,
                'communication_due_at' => $impediment->communication_due_at,
                'assigned_user_id' => $impediment->assigned_user_id,
            ]);
        });

        return back()->with('status', 'Impedimento registrado e incluído no acompanhamento.');
    }

    public function update(
        Request $request,
        int $emenda,
        int $impedimento,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $impediment = $amendment->technicalImpediments()->findOrFail($impedimento);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'nature' => ['required', Rule::in(array_keys(TechnicalImpediment::natures()))],
            'status' => ['required', Rule::in(array_keys(TechnicalImpediment::statuses()))],
            'assigned_user_id' => ['nullable', 'integer', $this->municipalUserRule($municipality->id)],
            'resolution_due_at' => ['nullable', 'date', 'after_or_equal:identified_at'],
            'communicated_at' => ['nullable', 'date', 'after_or_equal:identified_at', 'before_or_equal:today', 'required_with:communication_reference'],
            'communication_reference' => ['nullable', 'string', 'max:180', 'required_with:communicated_at'],
            'identified_at' => ['required', 'date'],
            'resolution_notes' => ['nullable', 'string', 'max:5000'],
            'evidence_document_id' => ['nullable', 'integer', $this->documentRule($municipality->id, $amendment->id)],
        ]);

        if (! $formSubmission->consume($request, "technical-impediment-update-{$impediment->id}")) {
            return back()->with('warning', 'Esta atualização já foi processada.');
        }

        if (in_array($validated['status'], [TechnicalImpediment::STATUS_RESOLVED, TechnicalImpediment::STATUS_CONFIRMED], true)
            && blank($validated['resolution_notes'])) {
            throw ValidationException::withMessages([
                'resolution_notes' => 'Registre a fundamentação antes de concluir a análise do impedimento.',
            ]);
        }

        if ($validated['status'] === TechnicalImpediment::STATUS_CONFIRMED
            && $validated['nature'] !== TechnicalImpediment::NATURE_INSURMOUNTABLE) {
            throw ValidationException::withMessages([
                'nature' => 'Um impedimento confirmado como definitivo deve ser classificado como insuperável.',
            ]);
        }

        if (in_array($validated['status'], [TechnicalImpediment::STATUS_CONFIRMED, TechnicalImpediment::STATUS_REMAPPED], true)
            && $request->user()->roleForMunicipality($municipality->id) !== 'manager') {
            abort(403);
        }

        if ($validated['status'] === TechnicalImpediment::STATUS_REMAPPED
            && ! $impediment->remappings()->where('status', 'approved')->exists()) {
            throw ValidationException::withMessages([
                'status' => 'O remanejamento precisa ser aprovado antes de concluir o impedimento como remanejado.',
            ]);
        }

        DB::transaction(function () use ($request, $validated, $amendment, $impediment, $auditTrail): void {
            $oldValues = $impediment->only(['nature', 'status', 'assigned_user_id', 'resolution_due_at', 'communicated_at', 'communication_reference', 'resolution_notes']);
            $terminal = in_array($validated['status'], [
                TechnicalImpediment::STATUS_RESOLVED,
                TechnicalImpediment::STATUS_CONFIRMED,
                TechnicalImpediment::STATUS_REMAPPED,
            ], true);
            $impediment->update([
                ...$validated,
                'resolved_at' => $terminal ? ($impediment->resolved_at ?? now()) : null,
            ]);
            $auditTrail->recordOperation($request, $amendment, 'technical_impediment_updated', [
                'impediment' => $impediment->title,
                'impediment_nature' => $impediment->nature,
                'impediment_status' => $impediment->status,
                'assigned_user_id' => $impediment->assigned_user_id,
                'impediment_due_at' => $impediment->resolution_due_at,
                'communicated_at' => $impediment->communicated_at,
                'communication_reference' => $impediment->communication_reference,
                'resolution_notes' => $impediment->resolution_notes,
            ], [
                'impediment' => $impediment->title,
                'impediment_nature' => $oldValues['nature'],
                'impediment_status' => $oldValues['status'],
                'assigned_user_id' => $oldValues['assigned_user_id'],
                'impediment_due_at' => $oldValues['resolution_due_at'],
                'communicated_at' => $oldValues['communicated_at'],
                'communication_reference' => $oldValues['communication_reference'],
                'resolution_notes' => $oldValues['resolution_notes'],
            ]);
        });

        return back()->with('status', 'Impedimento atualizado com rastreabilidade.');
    }

    /** @return array<string, mixed> */
    private function rules(int $municipalityId, int $amendmentId): array
    {
        return [
            '_submission_token' => ['required', 'string'],
            'category' => ['required', Rule::in(array_keys(TechnicalImpediment::categories()))],
            'nature' => ['required', Rule::in(array_keys(TechnicalImpediment::natures()))],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:5000'],
            'impact' => ['required', 'string', 'max:5000'],
            'assigned_user_id' => ['nullable', 'integer', $this->municipalUserRule($municipalityId)],
            'evidence_document_id' => ['nullable', 'integer', $this->documentRule($municipalityId, $amendmentId)],
            'identified_at' => ['required', 'date'],
            'resolution_due_at' => ['nullable', 'date', 'after_or_equal:identified_at'],
        ];
    }

    private function municipalUserRule(int $municipalityId): Exists
    {
        return Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query
            ->where('municipality_id', $municipalityId)
            ->whereIn('role', ['manager', 'editor']));
    }

    private function documentRule(int $municipalityId, int $amendmentId): Exists
    {
        return Rule::exists('amendment_documents', 'id')->where(fn ($query) => $query
            ->where('municipality_id', $municipalityId)
            ->where('parliamentary_amendment_id', $amendmentId));
    }
}
