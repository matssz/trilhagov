<?php

namespace App\Http\Controllers;

use App\Models\AccountabilityProcess;
use App\Models\AccountabilityRequirement;
use App\Models\ParliamentaryAmendment;
use App\Services\AccountabilityService;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountabilityController extends Controller
{
    public function index(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AccountabilityService $accountabilityService,
        IntegrityAlertService $integrityAlertService,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $integrityAlertService->sync($municipality);
        $amendment = $municipality->amendments()
            ->with([
                'municipality',
                'responsibleUser',
                'executionStages.responsibleUser',
                'financialCommitments.payments',
                'documents.documentType',
                'documents.executionStage',
                'accountabilityProcess.responsibleUser',
                'accountabilityProcess.requirements.document.documentType',
                'accountabilityProcess.requirements.completedBy',
                'accountabilityProcess.diligences.assignedUser',
            ])
            ->findOrFail($emenda);
        $process = $amendment->accountabilityProcess;
        $canEdit = $request->user()->canEditMunicipality($municipality->id);

        return view('amendments.accountability', [
            'amendment' => $amendment,
            'process' => $process,
            'canEdit' => $canEdit,
            'responsibleUsers' => $municipality->users()
                ->wherePivotIn('role', ['manager', 'editor'])
                ->orderBy('name')
                ->get(),
            'processStatuses' => AccountabilityProcess::statuses(),
            'requirementCategories' => AccountabilityRequirement::categories(),
            'requirementStatuses' => AccountabilityRequirement::statuses(),
            'readiness' => $process !== null
                ? $accountabilityService->readiness($amendment, $process)
                : null,
            'processCreateToken' => $canEdit && $process === null
                ? $formSubmission->issue($request, "accountability-create-{$amendment->id}")
                : null,
            'processUpdateToken' => $canEdit && $process !== null
                ? $formSubmission->issue($request, "accountability-update-{$process->id}")
                : null,
            'requirementCreateToken' => $canEdit && $process !== null
                ? $formSubmission->issue($request, "accountability-requirement-create-{$process->id}")
                : null,
            'requirementUpdateTokens' => $canEdit && $process !== null
                ? $process->requirements->mapWithKeys(fn ($requirement) => [
                    $requirement->id => $formSubmission->issue($request, "accountability-requirement-update-{$requirement->id}"),
                ])
                : collect(),
            'diligenceCreateToken' => $canEdit && $process !== null
                ? $formSubmission->issue($request, "accountability-diligence-create-{$process->id}")
                : null,
            'diligenceUpdateTokens' => $canEdit && $process !== null
                ? $process->diligences->mapWithKeys(fn ($diligence) => [
                    $diligence->id => $formSubmission->issue($request, "accountability-diligence-update-{$diligence->id}"),
                ])
                : collect(),
        ]);
    }

    public function store(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AccountabilityService $accountabilityService,
        AuditTrail $auditTrail,
        IntegrityAlertService $integrityAlertService,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "accountability-create-{$amendment->id}")) {
            return back()->with('warning', 'A abertura desta prestação já foi processada.');
        }

        if ($amendment->accountabilityProcess()->exists()) {
            return back()->with('warning', 'Esta emenda já possui uma prestação de contas.');
        }

        DB::transaction(function () use ($request, $municipality, $amendment, $accountabilityService, $auditTrail): void {
            $process = $amendment->accountabilityProcess()->create([
                'municipality_id' => $municipality->id,
                'responsible_user_id' => $amendment->responsible_user_id,
                'created_by' => $request->user()->id,
                'status' => AccountabilityProcess::STATUS_PREPARING,
                'due_at' => $amendment->accountability_deadline,
            ]);
            $accountabilityService->seedRequirements($process, $request->user());
            $auditTrail->recordOperation($request, $amendment, 'accountability_created', [
                'accountability_status' => $process->statusLabel(),
                'accountability_due_at' => $process->due_at,
            ]);
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Prestação de contas iniciada com checklist operacional.');
    }

    public function update(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AccountabilityService $accountabilityService,
        AuditTrail $auditTrail,
        IntegrityAlertService $integrityAlertService,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()
            ->with(['executionStages', 'financialCommitments.payments', 'documents', 'accountabilityProcess.requirements', 'accountabilityProcess.diligences'])
            ->findOrFail($emenda);
        $process = $amendment->accountabilityProcess ?? abort(404);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'responsible_user_id' => ['nullable', 'integer', Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query
                ->where('municipality_id', $municipality->id)
                ->whereIn('role', ['manager', 'editor']))],
            'status' => ['required', Rule::in(array_keys(AccountabilityProcess::statuses()))],
            'due_at' => ['nullable', 'date'],
            'submitted_at' => ['nullable', 'date'],
            'protocol_number' => ['nullable', 'string', 'max:100'],
            'approved_at' => ['nullable', 'date'],
            'returned_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'returned_at' => ['nullable', 'date'],
            'return_reference' => ['nullable', 'string', 'max:120'],
            'reconciliation_notes' => ['nullable', 'string', 'max:3000'],
            'submission_notes' => ['nullable', 'string', 'max:3000'],
        ], [
            'responsible_user_id.exists' => 'Selecione um responsável com perfil de gestor ou editor neste município.',
        ]);

        if (! $formSubmission->consume($request, "accountability-update-{$process->id}")) {
            return back()->with('warning', 'Esta atualização da prestação já foi processada.');
        }

        $submittedStatuses = [
            AccountabilityProcess::STATUS_SUBMITTED,
            AccountabilityProcess::STATUS_UNDER_REVIEW,
            AccountabilityProcess::STATUS_APPROVED,
        ];

        if (in_array($validated['status'], $submittedStatuses, true)
            && (blank($validated['submitted_at']) || blank($validated['protocol_number']))) {
            throw ValidationException::withMessages([
                'protocol_number' => 'Informe a data de envio e o protocolo antes de alterar para esta situação.',
            ]);
        }

        if ($validated['status'] === AccountabilityProcess::STATUS_APPROVED && blank($validated['approved_at'])) {
            throw ValidationException::withMessages([
                'approved_at' => 'Informe a data de aprovação da prestação de contas.',
            ]);
        }

        if ((float) $validated['returned_amount'] > 0
            && (blank($validated['returned_at']) || blank($validated['return_reference']))) {
            throw ValidationException::withMessages([
                'return_reference' => 'Informe a data e a referência da devolução do saldo.',
            ]);
        }

        DB::transaction(function () use ($request, $validated, $submittedStatuses, $amendment, $process, $accountabilityService, $auditTrail): void {
            $oldValues = $process->only([
                'responsible_user_id', 'status', 'due_at', 'submitted_at', 'protocol_number',
                'approved_at', 'returned_amount', 'returned_at', 'return_reference',
            ]);
            $process->fill($validated);

            if (in_array($process->status, $submittedStatuses, true)) {
                $accountabilityService->ensureReadyForSubmission($amendment, $process);
            }

            $process->save();

            if ($process->status === AccountabilityProcess::STATUS_APPROVED) {
                $amendment->update([
                    'status' => ParliamentaryAmendment::STATUS_COMPLETED,
                    'accountability_completed_at' => $process->approved_at,
                ]);
            } elseif (in_array($process->status, [
                AccountabilityProcess::STATUS_SUBMITTED,
                AccountabilityProcess::STATUS_UNDER_REVIEW,
                AccountabilityProcess::STATUS_PENDING_CORRECTION,
            ], true)) {
                $amendment->update(['status' => ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING]);
            }

            $auditTrail->recordOperation(
                $request,
                $amendment,
                'accountability_updated',
                $process->only(array_keys($oldValues)),
                $oldValues,
            );
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Prestação de contas atualizada com sucesso.');
    }
}
