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
        $integrityAlertService->syncIfDue($municipality);
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
        $readiness = $process !== null
            ? $accountabilityService->readiness($amendment, $process)
            : null;

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
            'readiness' => $readiness,
            'accountabilityGuide' => $accountabilityService->guide($amendment, $process, $readiness),
            'prepareToken' => $canEdit
                ? $formSubmission->issue($request, "accountability-prepare-{$amendment->id}")
                : null,
            'processCreateToken' => $canEdit && $process === null
                ? $formSubmission->issue($request, "accountability-create-{$amendment->id}")
                : null,
            'processUpdateToken' => $canEdit && $process !== null
                ? $formSubmission->issue($request, "accountability-update-{$process->id}")
                : null,
            'processSubmitToken' => $canEdit && $process !== null
                ? $formSubmission->issue($request, "accountability-submit-{$process->id}")
                : null,
            'processArchiveToken' => $canEdit && $process !== null
                ? $formSubmission->issue($request, "accountability-archive-{$process->id}")
                : null,
            'quickCheckToken' => $canEdit && $process !== null
                ? $formSubmission->issue($request, "accountability-quick-check-{$process->id}")
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

    public function prepare(
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
            ->with(['executionStages', 'financialCommitments.payments', 'documents.documentType', 'accountabilityProcess.requirements'])
            ->findOrFail($emenda);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "accountability-prepare-{$amendment->id}")) {
            return back()->with('warning', 'A preparacao automatica desta prestacao ja foi processada.');
        }

        $result = DB::transaction(function () use ($request, $municipality, $amendment, $accountabilityService, $auditTrail): array {
            $process = $amendment->accountabilityProcess;
            $created = false;

            if ($process === null) {
                $process = $amendment->accountabilityProcess()->create([
                    'municipality_id' => $municipality->id,
                    'responsible_user_id' => $amendment->responsible_user_id,
                    'created_by' => $request->user()->id,
                    'status' => AccountabilityProcess::STATUS_PREPARING,
                    'due_at' => $amendment->accountability_deadline,
                ]);
                $created = true;
            }

            if (! $process->requirements()->exists()) {
                $accountabilityService->seedRequirements($process, $request->user());
            }

            $process->load('requirements');
            $amendment->setRelation('accountabilityProcess', $process);
            $stats = $accountabilityService->quickCheck($process, $amendment, $request->user());
            $freshAmendment = $amendment->fresh(['executionStages', 'financialCommitments.payments', 'documents']);
            $freshProcess = $process->fresh(['requirements', 'diligences']);
            $readiness = $accountabilityService->readiness($freshAmendment, $freshProcess);

            $auditTrail->recordOperation($request, $amendment, 'accountability_auto_prepared', [
                'created' => $created,
                'updated' => $stats['updated'],
                'score' => $readiness['score'],
                'ready' => $readiness['ready'],
            ]);

            return [
                'updated' => $stats['updated'],
                'score' => $readiness['score'],
                'ready' => $readiness['ready'],
                'first_blocker' => $readiness['blockers']->first(),
            ];
        });

        $integrityAlertService->sync($municipality->fresh());

        $message = $result['ready']
            ? 'Prestacao preparada automaticamente e pronta para informar protocolo.'
            : 'Prestacao preparada automaticamente. Proxima pendencia: '.$result['first_blocker'];

        return back()->with('status', $message);
    }

    public function quickCheck(
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
            ->with(['executionStages', 'financialCommitments.payments', 'documents.documentType', 'accountabilityProcess.requirements'])
            ->findOrFail($emenda);
        $process = $amendment->accountabilityProcess ?? abort(404);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "accountability-quick-check-{$process->id}")) {
            return back()->with('warning', 'Esta pre-conferencia ja foi processada.');
        }

        $stats = DB::transaction(function () use ($request, $amendment, $process, $accountabilityService, $auditTrail): array {
            $stats = $accountabilityService->quickCheck($process, $amendment, $request->user());
            $auditTrail->recordOperation($request, $amendment, 'accountability_quick_checked', $stats);

            return $stats;
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Pre-conferencia concluida: '.$stats['updated'].' item(ns) atualizado(s), '.$stats['completed'].' concluido(s) e '.$stats['notApplicable'].' nao aplicavel(is).');
    }

    public function submit(
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
            'submitted_at' => ['required', 'date'],
            'protocol_number' => ['required', 'string', 'max:100'],
            'submission_notes' => ['nullable', 'string', 'max:3000'],
        ], [
            'submitted_at.required' => 'Informe a data de envio da prestacao.',
            'protocol_number.required' => 'Informe o numero do protocolo de envio.',
        ]);

        if (! $formSubmission->consume($request, "accountability-submit-{$process->id}")) {
            return back()->with('warning', 'Este envio da prestacao ja foi processado.');
        }

        DB::transaction(function () use ($request, $validated, $amendment, $process, $accountabilityService, $auditTrail): void {
            $accountabilityService->ensureReadyForSubmission($amendment, $process);
            $oldValues = $process->only(['status', 'submitted_at', 'protocol_number', 'submission_notes']);

            $process->update([
                'status' => AccountabilityProcess::STATUS_SUBMITTED,
                'submitted_at' => $validated['submitted_at'],
                'protocol_number' => trim($validated['protocol_number']),
                'submission_notes' => $validated['submission_notes'] ?? $process->submission_notes,
            ]);
            $amendment->update(['status' => ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING]);

            $auditTrail->recordOperation(
                $request,
                $amendment,
                'accountability_submitted',
                $process->only(array_keys($oldValues)),
                $oldValues,
            );
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Prestacao enviada e protocolo registrado. O dossie final ja pode ser baixado.');
    }

    public function archive(
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
            'approved_at' => ['required', 'date'],
            'submission_notes' => ['nullable', 'string', 'max:3000'],
        ], [
            'approved_at.required' => 'Informe a data da aprovacao final para arquivar a prestacao.',
        ]);

        if (! $formSubmission->consume($request, "accountability-archive-{$process->id}")) {
            return back()->with('warning', 'Este arquivamento final ja foi processado.');
        }

        if (blank($process->submitted_at) || blank($process->protocol_number)) {
            throw ValidationException::withMessages([
                'protocol_number' => 'Registre o protocolo de envio antes de arquivar a prestacao final.',
            ]);
        }

        DB::transaction(function () use ($request, $validated, $amendment, $process, $accountabilityService, $auditTrail): void {
            $accountabilityService->ensureReadyForSubmission($amendment, $process);
            $oldValues = $process->only(['status', 'approved_at', 'submission_notes']);
            $notes = trim((string) ($validated['submission_notes'] ?? ''));

            $process->update([
                'status' => AccountabilityProcess::STATUS_APPROVED,
                'approved_at' => $validated['approved_at'],
                'submission_notes' => $notes !== ''
                    ? trim((string) $process->submission_notes.PHP_EOL.PHP_EOL.'Fechamento final: '.$notes)
                    : $process->submission_notes,
            ]);
            $amendment->update([
                'status' => ParliamentaryAmendment::STATUS_COMPLETED,
                'accountability_completed_at' => $validated['approved_at'],
            ]);

            $auditTrail->recordOperation(
                $request,
                $amendment,
                'accountability_archived',
                $process->only(array_keys($oldValues)),
                $oldValues,
            );
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Prestacao final aprovada, arquivada e marcada como concluida.');
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
