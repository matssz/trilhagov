<?php

namespace App\Http\Controllers;

use App\Models\MunicipalAuditEvidence;
use App\Models\MunicipalAuditFinding;
use App\Models\MunicipalAuditPlan;
use App\Models\MunicipalAuditPlanItem;
use App\Models\MunicipalAuditProcedure;
use App\Models\MunicipalAuditProgram;
use App\Models\Municipality;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use App\Services\MunicipalAuditProgramService;
use App\Services\MunicipalWorkItemService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MunicipalAuditProgramController extends Controller
{
    public function show(
        Request $request,
        int $program,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $forms,
        MunicipalAuditProgramService $service,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $program = $this->program($municipality, $program);
        $program->load([
            'planItem.plan', 'planItem.amendment', 'leadAuditor:id,name,email',
            'supervisor:id,name,email', 'reviewer:id,name', 'concludedBy:id,name',
            'procedures.executor:id,name', 'procedures.evidences',
            'procedures.findings', 'findings.procedure', 'events',
        ]);
        $canManage = $this->canManage($request, $municipality);
        $canEdit = $canManage && $program->isEditable();

        return view('audit-programs.show', [
            'municipality' => $municipality,
            'program' => $program,
            'canManage' => $canManage,
            'canEdit' => $canEdit,
            'isSupervisor' => $program->supervisor_id === $request->user()->id,
            'auditors' => $canEdit
                ? $municipality->users()->wherePivotIn('role', ['manager', 'auditor'])->orderBy('name')->get(['users.id', 'users.name'])
                : collect(),
            'blockers' => $program->isEditable() ? $service->readiness($program) : [],
            'samplingMethods' => MunicipalAuditProgram::samplingMethods(),
            'procedureStatuses' => MunicipalAuditProcedure::statuses(),
            'severities' => MunicipalAuditFinding::severities(),
            'updateToken' => $canEdit ? $forms->issue($request, "audit-program-update-{$program->id}") : null,
            'procedureToken' => $canEdit ? $forms->issue($request, "audit-procedure-create-{$program->id}") : null,
            'procedureUpdateTokens' => $canEdit ? $program->procedures->mapWithKeys(fn ($procedure) => [$procedure->id => $forms->issue($request, "audit-procedure-update-{$procedure->id}")]) : collect(),
            'procedureDeleteTokens' => $canEdit ? $program->procedures->mapWithKeys(fn ($procedure) => [$procedure->id => $forms->issue($request, "audit-procedure-delete-{$procedure->id}")]) : collect(),
            'evidenceTokens' => $canEdit ? $program->procedures->mapWithKeys(fn ($procedure) => [$procedure->id => $forms->issue($request, "audit-evidence-create-{$procedure->id}")]) : collect(),
            'findingToken' => $canEdit ? $forms->issue($request, "audit-finding-create-{$program->id}") : null,
            'findingUpdateTokens' => $canEdit ? $program->findings->mapWithKeys(fn ($finding) => [$finding->id => $forms->issue($request, "audit-finding-update-{$finding->id}")]) : collect(),
            'findingDeleteTokens' => $canEdit ? $program->findings->mapWithKeys(fn ($finding) => [$finding->id => $forms->issue($request, "audit-finding-delete-{$finding->id}")]) : collect(),
            'submitToken' => $canEdit ? $forms->issue($request, "audit-program-submit-{$program->id}") : null,
            'reviewToken' => $program->status === MunicipalAuditProgram::STATUS_UNDER_REVIEW && $program->supervisor_id === $request->user()->id
                ? $forms->issue($request, "audit-program-review-{$program->id}") : null,
            'concludeToken' => $program->status === MunicipalAuditProgram::STATUS_APPROVED && $program->supervisor_id === $request->user()->id
                ? $forms->issue($request, "audit-program-conclude-{$program->id}") : null,
        ]);
    }

    public function store(
        Request $request,
        int $item,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $forms,
        AuditTrail $audit,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $item = $this->item($municipality, $item);
        abort_unless($item->plan->status === MunicipalAuditPlan::STATUS_ISSUED
            && ! in_array($item->status, [MunicipalAuditPlanItem::STATUS_COMPLETED, MunicipalAuditPlanItem::STATUS_CANCELLED], true), 409);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'supervisor_id' => ['required', 'integer', $this->municipalAuditorRule($municipality)],
        ]);
        if ((int) $validated['supervisor_id'] === $item->assigned_user_id) {
            throw ValidationException::withMessages(['supervisor_id' => 'O supervisor deve ser diferente do auditor responsável.']);
        }
        if (! $forms->consume($request, "audit-program-create-{$item->id}")) {
            return back()->with('warning', 'Este Programa de Auditoria já foi processado.');
        }

        $program = DB::transaction(function () use ($request, $municipality, $item, $validated, $audit): MunicipalAuditProgram {
            $locked = MunicipalAuditPlanItem::query()->with(['plan', 'amendment'])->lockForUpdate()->findOrFail($item->id);
            abort_if($locked->program()->exists(), 409);
            $dueAt = $locked->planned_at->isAfter(today()) ? $locked->planned_at : today()->addDays(30);
            $program = $locked->program()->create([
                'municipality_id' => $municipality->id,
                'lead_auditor_id' => $locked->assigned_user_id,
                'supervisor_id' => $validated['supervisor_id'],
                'created_by' => $request->user()->id,
                'status' => MunicipalAuditProgram::STATUS_DRAFT,
                'title' => 'Programa de Auditoria da '.$locked->amendment->reference,
                'objective' => $locked->scope_notes,
                'scope' => 'Examinar os atos, documentos, transações e evidências vinculados à emenda e à fase planejada.',
                'sampling_method' => 'judgmental',
                'population_description' => 'Processos, documentos e transações vinculados à emenda municipal.',
                'materiality_criteria' => 'Materialidade financeira, risco, relevância pública e criticidade do controle.',
                'start_at' => today(),
                'due_at' => $dueAt,
            ]);
            $this->event($program, $request, 'created', 'Programa criado a partir do item emitido do Plano Anual.');
            $audit->recordOperation($request, $locked->amendment, 'municipal_audit_program_created', [
                'audit_program_id' => $program->id,
                'audit_plan_item_id' => $locked->id,
            ]);

            return $program;
        });

        return redirect()->route('audit-programs.show', $program)
            ->with('status', 'Programa de Auditoria criado. Estruture a amostra e os procedimentos.');
    }

    public function update(
        Request $request,
        int $program,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $forms,
        AuditTrail $audit,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $program = $this->editableProgram($municipality, $program);
        $validated = $request->validate($this->programRules($municipality));
        if ((int) $validated['lead_auditor_id'] === (int) $validated['supervisor_id']) {
            throw ValidationException::withMessages(['supervisor_id' => 'O supervisor deve ser diferente do auditor líder.']);
        }
        if ((int) $validated['sample_size'] > (int) $validated['population_size']) {
            throw ValidationException::withMessages(['sample_size' => 'A amostra não pode ser maior que a população.']);
        }
        if ($validated['sampling_method'] === 'census'
            && (int) $validated['sample_size'] !== (int) $validated['population_size']) {
            throw ValidationException::withMessages(['sample_size' => 'No exame integral, amostra e população devem ser iguais.']);
        }
        if (! $forms->consume($request, "audit-program-update-{$program->id}")) {
            return back()->with('warning', 'Esta atualização já foi processada.');
        }

        $leadChanged = $program->lead_auditor_id !== (int) $validated['lead_auditor_id'];
        $program->update($validated);
        if ($leadChanged) {
            $program->planItem->update(['assigned_user_id' => $program->lead_auditor_id]);
        }
        $this->event($program, $request, 'updated', 'Escopo, equipe ou estratégia de amostragem atualizados.');
        $audit->recordOperation($request, $program->planItem->amendment, 'municipal_audit_program_updated', [
            'audit_program_id' => $program->id,
            'sampling_method' => $program->sampling_method,
            'sample_size' => $program->sample_size,
        ]);

        return back()->with('status', 'Estratégia do Programa de Auditoria atualizada.');
    }

    public function storeProcedure(Request $request, int $program, CurrentMunicipality $currentMunicipality, FormSubmission $forms): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $program = $this->editableProgram($municipality, $program);
        $validated = $request->validate($this->procedureRules(includeStatus: false));
        if (! $forms->consume($request, "audit-procedure-create-{$program->id}")) {
            return back()->with('warning', 'Este procedimento já foi processado.');
        }

        DB::transaction(function () use ($request, $program, $validated): void {
            $locked = MunicipalAuditProgram::query()->lockForUpdate()->findOrFail($program->id);
            abort_unless($locked->isEditable(), 409);
            $sequence = ((int) $locked->procedures()->max('sequence')) + 1;
            $procedure = $locked->procedures()->create([
                ...$validated,
                'municipality_id' => $locked->municipality_id,
                'created_by' => $request->user()->id,
                'sequence' => $sequence,
                'status' => MunicipalAuditProcedure::STATUS_PLANNED,
            ]);
            $this->event($locked, $request, 'procedure_created', "Procedimento P{$this->sequence($sequence)} incluído.", ['procedure_id' => $procedure->id]);
        });

        return back()->with('status', 'Procedimento incluído no programa.');
    }

    public function updateProcedure(Request $request, int $procedure, CurrentMunicipality $currentMunicipality, FormSubmission $forms, AuditTrail $audit): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $procedure = $this->procedure($municipality, $procedure);
        abort_unless($procedure->program->isEditable(), 409);
        $validated = $request->validate($this->procedureRules(includeStatus: true));
        if (! $forms->consume($request, "audit-procedure-update-{$procedure->id}")) {
            return back()->with('warning', 'Este resultado já foi processado.');
        }

        DB::transaction(function () use ($request, $procedure, $validated, $audit): void {
            $program = MunicipalAuditProgram::query()->lockForUpdate()->findOrFail($procedure->municipal_audit_program_id);
            abort_unless($program->isEditable(), 409);
            $procedure->update([
                ...$validated,
                'executed_by' => $validated['status'] === MunicipalAuditProcedure::STATUS_PLANNED ? null : $request->user()->id,
                'executed_at' => $validated['status'] === MunicipalAuditProcedure::STATUS_PLANNED ? null : now(),
            ]);
            if ($validated['status'] !== MunicipalAuditProcedure::STATUS_PLANNED && $program->status === MunicipalAuditProgram::STATUS_DRAFT) {
                $program->update(['status' => MunicipalAuditProgram::STATUS_IN_PROGRESS]);
                $item = MunicipalAuditPlanItem::query()->lockForUpdate()->findOrFail($program->municipal_audit_plan_item_id);
                if (in_array($item->status, [MunicipalAuditPlanItem::STATUS_PLANNED, MunicipalAuditPlanItem::STATUS_RESCHEDULED], true)) {
                    $from = $item->status;
                    $item->update([
                        'status' => MunicipalAuditPlanItem::STATUS_IN_PROGRESS,
                        'status_notes' => "Execução iniciada pelo Programa de Auditoria {$program->reference()}.",
                    ]);
                    $item->events()->create([
                        'municipality_id' => $program->municipality_id,
                        'user_id' => $request->user()->id,
                        'actor_name' => $request->user()->name,
                        'event_type' => MunicipalAuditPlanItem::STATUS_IN_PROGRESS,
                        'from_status' => $from,
                        'to_status' => MunicipalAuditPlanItem::STATUS_IN_PROGRESS,
                        'description' => "Execução iniciada pelo Programa de Auditoria {$program->reference()}.",
                        'metadata' => ['audit_program_id' => $program->id],
                    ]);
                }
            }
            $this->event($program, $request, 'procedure_executed', "Resultado do procedimento P{$this->sequence($procedure->sequence)} registrado.", [
                'procedure_id' => $procedure->id,
                'status' => $validated['status'],
            ]);
            $audit->recordOperation($request, $program->planItem->amendment, 'municipal_audit_procedure_recorded', [
                'audit_program_id' => $program->id,
                'procedure_id' => $procedure->id,
                'status' => $validated['status'],
            ]);
        });

        app(MunicipalWorkItemService::class)->synchronize($municipality);
        app(IntegrityAlertService::class)->sync($municipality);

        return back()->with('status', 'Papel de trabalho atualizado.');
    }

    public function destroyProcedure(Request $request, int $procedure, CurrentMunicipality $currentMunicipality, FormSubmission $forms): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $procedure = $this->procedure($municipality, $procedure);
        abort_unless($procedure->program->isEditable(), 409);
        if ($procedure->status !== MunicipalAuditProcedure::STATUS_PLANNED
            || $procedure->evidences()->exists() || $procedure->findings()->exists()) {
            throw ValidationException::withMessages(['procedure' => 'Um procedimento executado, com evidência ou achado não pode ser removido.']);
        }
        if (! $forms->consume($request, "audit-procedure-delete-{$procedure->id}")) {
            return back()->with('warning', 'Esta remoção já foi processada.');
        }
        $program = $procedure->program;
        $sequence = $procedure->sequence;
        $procedure->delete();
        $this->event($program, $request, 'procedure_removed', "Procedimento P{$this->sequence($sequence)} removido antes da execução.");

        return back()->with('status', 'Procedimento não executado removido.');
    }

    public function storeEvidence(Request $request, int $procedure, CurrentMunicipality $currentMunicipality, FormSubmission $forms): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $procedure = $this->procedure($municipality, $procedure);
        abort_unless($procedure->program->isEditable(), 409);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'description' => ['required', 'string', 'min:5', 'max:500'],
            'evidence' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png', 'csv', 'xlsx'])->max(10 * 1024)],
        ]);
        if (! $forms->consume($request, "audit-evidence-create-{$procedure->id}")) {
            return back()->with('warning', 'Esta evidência já foi processada.');
        }

        $stored = $this->storeFile($request->file('evidence'), $municipality, $procedure);
        try {
            DB::transaction(function () use ($request, $municipality, $procedure, $validated, $stored): void {
                $evidence = $procedure->evidences()->create([
                    'municipality_id' => $municipality->id,
                    'uploaded_by' => $request->user()->id,
                    'uploader_name' => $request->user()->name,
                    'description' => $validated['description'],
                    ...$stored,
                ]);
                $this->event($procedure->program, $request, 'evidence_added', "Evidência anexada ao procedimento P{$this->sequence($procedure->sequence)}.", [
                    'procedure_id' => $procedure->id,
                    'evidence_id' => $evidence->id,
                    'sha256' => $evidence->sha256,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($stored['storage_path']);
            throw $exception;
        }

        return back()->with('status', 'Evidência preservada com identificação SHA-256.');
    }

    public function evidence(Request $request, int $evidence, CurrentMunicipality $currentMunicipality): StreamedResponse
    {
        $municipality = $currentMunicipality->get($request);
        $evidence = MunicipalAuditEvidence::query()->where('municipality_id', $municipality->id)->findOrFail($evidence);
        abort_unless(Storage::disk('local')->exists($evidence->storage_path), 404);

        return Storage::disk('local')->download($evidence->storage_path, $evidence->original_name, [
            'Content-Type' => $evidence->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function storeFinding(Request $request, int $program, CurrentMunicipality $currentMunicipality, FormSubmission $forms): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $program = $this->editableProgram($municipality, $program);
        $validated = $request->validate($this->findingRules($program));
        if (! $forms->consume($request, "audit-finding-create-{$program->id}")) {
            return back()->with('warning', 'Este achado já foi processado.');
        }
        $finding = $program->findings()->create([
            ...$validated,
            'municipality_id' => $municipality->id,
            'created_by' => $request->user()->id,
        ]);
        $this->event($program, $request, 'finding_created', "Achado {$finding->title} registrado.", ['finding_id' => $finding->id, 'severity' => $finding->severity]);

        return back()->with('status', 'Achado e recomendação registrados.');
    }

    public function updateFinding(Request $request, int $finding, CurrentMunicipality $currentMunicipality, FormSubmission $forms): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $finding = $this->finding($municipality, $finding);
        abort_unless($finding->program->isEditable(), 409);
        $validated = $request->validate($this->findingRules($finding->program));
        if (! $forms->consume($request, "audit-finding-update-{$finding->id}")) {
            return back()->with('warning', 'Esta atualização já foi processada.');
        }
        $finding->update($validated);
        $this->event($finding->program, $request, 'finding_updated', "Achado {$finding->title} atualizado.", ['finding_id' => $finding->id]);

        return back()->with('status', 'Achado atualizado.');
    }

    public function destroyFinding(Request $request, int $finding, CurrentMunicipality $currentMunicipality, FormSubmission $forms): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $finding = $this->finding($municipality, $finding);
        abort_unless($finding->program->isEditable(), 409);
        if (! $forms->consume($request, "audit-finding-delete-{$finding->id}")) {
            return back()->with('warning', 'Esta remoção já foi processada.');
        }
        $program = $finding->program;
        $title = $finding->title;
        $finding->delete();
        $this->event($program, $request, 'finding_removed', "Achado {$title} removido antes da revisão.");

        return back()->with('status', 'Achado removido antes da revisão.');
    }

    public function submit(Request $request, int $program, CurrentMunicipality $currentMunicipality, FormSubmission $forms, MunicipalAuditProgramService $service): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $program = $this->editableProgram($municipality, $program);
        $request->validate(['_submission_token' => ['required', 'string'], 'confirm_workpapers' => ['accepted']]);
        if (! $forms->consume($request, "audit-program-submit-{$program->id}")) {
            return back()->with('warning', 'Este envio já foi processado.');
        }
        if (($blocker = collect($service->readiness($program))->first()) !== null) {
            throw ValidationException::withMessages(['program' => $blocker]);
        }
        $program->update([
            'status' => MunicipalAuditProgram::STATUS_UNDER_REVIEW,
            'submitted_at' => now(),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'supervisor_notes' => null,
        ]);
        $this->event($program, $request, 'submitted', 'Papéis de trabalho enviados para revisão do supervisor.');

        return back()->with('status', 'Programa enviado ao supervisor para revisão.');
    }

    public function review(Request $request, int $program, CurrentMunicipality $currentMunicipality, FormSubmission $forms, AuditTrail $audit): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $program = $this->program($municipality, $program);
        abort_unless($program->status === MunicipalAuditProgram::STATUS_UNDER_REVIEW, 409);
        abort_unless($program->supervisor_id === $request->user()->id && $program->lead_auditor_id !== $request->user()->id, 403);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'decision' => ['required', Rule::in(['approved', 'returned'])],
            'supervisor_notes' => ['required', 'string', 'min:10', 'max:5000'],
        ]);
        if (! $forms->consume($request, "audit-program-review-{$program->id}")) {
            return back()->with('warning', 'Esta revisão já foi processada.');
        }
        $to = $validated['decision'] === 'approved'
            ? MunicipalAuditProgram::STATUS_APPROVED
            : MunicipalAuditProgram::STATUS_RETURNED;
        $program->update([
            'status' => $to,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'supervisor_notes' => $validated['supervisor_notes'],
        ]);
        $this->event($program, $request, $to, $validated['supervisor_notes']);
        $audit->recordOperation($request, $program->planItem->amendment, 'municipal_audit_program_reviewed', [
            'audit_program_id' => $program->id,
            'decision' => $to,
        ]);

        return back()->with('status', $to === MunicipalAuditProgram::STATUS_APPROVED
            ? 'Papéis de trabalho aprovados pelo supervisor.'
            : 'Programa devolvido ao auditor para ajustes.');
    }

    public function conclude(
        Request $request,
        int $program,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $forms,
        MunicipalAuditProgramService $service,
        AuditTrail $audit,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $program = $this->program($municipality, $program);
        abort_unless($program->status === MunicipalAuditProgram::STATUS_APPROVED, 409);
        abort_unless($program->supervisor_id === $request->user()->id, 403);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'conclusion' => ['required', 'string', 'min:20', 'max:8000'],
            'confirm_conclusion' => ['accepted'],
        ]);
        if (! $forms->consume($request, "audit-program-conclude-{$program->id}")) {
            return back()->with('warning', 'Esta conclusão já foi processada.');
        }

        DB::transaction(function () use ($request, $municipality, $program, $validated, $service, $audit): void {
            $locked = MunicipalAuditProgram::query()->with([
                'municipality', 'planItem.plan', 'planItem.amendment', 'leadAuditor',
                'supervisor', 'reviewer', 'procedures.executor',
                'procedures.evidences', 'findings.procedure',
            ])->lockForUpdate()->findOrFail($program->id);
            abort_unless($locked->status === MunicipalAuditProgram::STATUS_APPROVED, 409);
            $locked->update(['conclusion' => $validated['conclusion']]);
            $snapshot = $service->snapshot($locked->fresh());
            $locked->update([
                'status' => MunicipalAuditProgram::STATUS_CONCLUDED,
                'concluded_by' => $request->user()->id,
                'concluded_at' => now(),
                'snapshot' => $snapshot,
                'snapshot_sha256' => $service->hash($snapshot),
            ]);

            $item = MunicipalAuditPlanItem::query()->lockForUpdate()->findOrFail($locked->municipal_audit_plan_item_id);
            $from = $item->status;
            $item->update([
                'status' => MunicipalAuditPlanItem::STATUS_COMPLETED,
                'completed_by' => $request->user()->id,
                'completed_at' => now(),
                'status_notes' => "Concluída pelo Programa de Auditoria {$locked->reference()}.",
            ]);
            $item->events()->create([
                'municipality_id' => $municipality->id,
                'user_id' => $request->user()->id,
                'actor_name' => $request->user()->name,
                'event_type' => 'completed',
                'from_status' => $from,
                'to_status' => MunicipalAuditPlanItem::STATUS_COMPLETED,
                'description' => "Item concluído pelo Programa de Auditoria {$locked->reference()}.",
                'metadata' => ['audit_program_id' => $locked->id, 'snapshot_sha256' => $locked->snapshot_sha256],
            ]);
            $this->event($locked, $request, 'concluded', 'Programa encerrado formalmente após aprovação do supervisor.', [
                'snapshot_sha256' => $locked->snapshot_sha256,
            ]);
            $audit->recordOperation($request, $locked->planItem->amendment, 'municipal_audit_program_concluded', [
                'audit_program_id' => $locked->id,
                'audit_program_reference' => $locked->reference(),
                'snapshot_sha256' => $locked->snapshot_sha256,
            ]);
        });

        app(MunicipalWorkItemService::class)->synchronize($municipality);
        app(IntegrityAlertService::class)->sync($municipality);

        return back()->with('status', 'Programa concluído e item do Plano Anual encerrado formalmente.');
    }

    public function pdf(Request $request, int $program, CurrentMunicipality $currentMunicipality, MunicipalAuditProgramService $service): Response
    {
        $municipality = $currentMunicipality->get($request);
        $program = $this->program($municipality, $program);
        $document = $program->status === MunicipalAuditProgram::STATUS_CONCLUDED
            ? $program->snapshot
            : $service->snapshot($program);

        return Pdf::loadView('audit-programs.pdf', compact('program', 'document'))
            ->setPaper('a4')
            ->download(Str::lower($program->reference()).'.pdf');
    }

    /** @return array<string, mixed> */
    private function programRules(Municipality $municipality): array
    {
        return [
            '_submission_token' => ['required', 'string'],
            'lead_auditor_id' => ['required', 'integer', $this->municipalAuditorRule($municipality)],
            'supervisor_id' => ['required', 'integer', $this->municipalAuditorRule($municipality)],
            'title' => ['required', 'string', 'min:5', 'max:220'],
            'objective' => ['required', 'string', 'min:20', 'max:5000'],
            'scope' => ['required', 'string', 'min:20', 'max:5000'],
            'sampling_method' => ['required', Rule::in(array_keys(MunicipalAuditProgram::samplingMethods()))],
            'population_description' => ['required', 'string', 'min:10', 'max:3000'],
            'population_size' => ['required', 'integer', 'min:1', 'max:10000000'],
            'sample_size' => ['required', 'integer', 'min:1', 'max:10000000'],
            'materiality_criteria' => ['required', 'string', 'min:10', 'max:3000'],
            'start_at' => ['required', 'date'],
            'due_at' => ['required', 'date', 'after_or_equal:start_at'],
        ];
    }

    /** @return array<string, mixed> */
    private function procedureRules(bool $includeStatus): array
    {
        $rules = [
            '_submission_token' => ['required', 'string'],
            'title' => ['required', 'string', 'min:5', 'max:220'],
            'objective' => ['required', 'string', 'min:10', 'max:3000'],
            'test_method' => ['required', 'string', 'min:10', 'max:5000'],
            'sample_description' => ['required', 'string', 'min:5', 'max:3000'],
            'expected_evidence' => ['required', 'string', 'min:5', 'max:3000'],
        ];
        if ($includeStatus) {
            $rules['status'] = ['required', Rule::in(array_keys(MunicipalAuditProcedure::statuses()))];
            $rules['result'] = ['nullable', 'required_unless:status,planned', 'string', 'min:10', 'max:8000'];
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    private function findingRules(MunicipalAuditProgram $program): array
    {
        return [
            '_submission_token' => ['required', 'string'],
            'municipal_audit_procedure_id' => ['nullable', 'integer', Rule::exists('municipal_audit_procedures', 'id')->where(fn ($query) => $query->where('municipal_audit_program_id', $program->id)->where('municipality_id', $program->municipality_id))],
            'severity' => ['required', Rule::in(array_keys(MunicipalAuditFinding::severities()))],
            'title' => ['required', 'string', 'min:5', 'max:220'],
            'criteria' => ['required', 'string', 'min:10', 'max:3000'],
            'condition' => ['required', 'string', 'min:10', 'max:5000'],
            'cause' => ['nullable', 'string', 'max:3000'],
            'effect' => ['nullable', 'string', 'max:3000'],
            'recommendation' => ['required', 'string', 'min:10', 'max:5000'],
            'recommended_due_at' => ['nullable', 'date'],
        ];
    }

    private function municipalAuditorRule(Municipality $municipality): Exists
    {
        return Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query
            ->where('municipality_id', $municipality->id)
            ->whereIn('role', ['manager', 'auditor']));
    }

    private function program(Municipality $municipality, int $id): MunicipalAuditProgram
    {
        $this->ensureScope($municipality);

        return MunicipalAuditProgram::query()
            ->where('municipality_id', $municipality->id)
            ->with(['planItem.plan', 'planItem.amendment'])
            ->findOrFail($id);
    }

    private function editableProgram(Municipality $municipality, int $id): MunicipalAuditProgram
    {
        $program = $this->program($municipality, $id);
        abort_unless($program->isEditable(), 409);

        return $program;
    }

    private function item(Municipality $municipality, int $id): MunicipalAuditPlanItem
    {
        $this->ensureScope($municipality);

        return MunicipalAuditPlanItem::query()
            ->where('municipality_id', $municipality->id)
            ->with(['plan', 'amendment', 'program'])
            ->findOrFail($id);
    }

    private function procedure(Municipality $municipality, int $id): MunicipalAuditProcedure
    {
        $this->ensureScope($municipality);

        return MunicipalAuditProcedure::query()
            ->where('municipality_id', $municipality->id)
            ->with(['program.planItem.amendment'])
            ->findOrFail($id);
    }

    private function finding(Municipality $municipality, int $id): MunicipalAuditFinding
    {
        $this->ensureScope($municipality);

        return MunicipalAuditFinding::query()
            ->where('municipality_id', $municipality->id)
            ->with(['program.planItem.amendment'])
            ->findOrFail($id);
    }

    /** @return array{original_name:string,storage_path:string,mime_type:string,size_bytes:int,sha256:string} */
    private function storeFile(UploadedFile $file, Municipality $municipality, MunicipalAuditProcedure $procedure): array
    {
        $extension = $file->guessExtension() ?: 'bin';
        $sha256 = hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs(
            "municipalities/{$municipality->id}/audit-programs/{$procedure->municipal_audit_program_id}/evidence",
            Str::uuid().'.'.$extension,
            'local',
        );

        return [
            'original_name' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'sha256' => $sha256,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function event(MunicipalAuditProgram $program, Request $request, string $type, string $description, array $metadata = []): void
    {
        $program->events()->create([
            'municipality_id' => $program->municipality_id,
            'user_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'event_type' => $type,
            'description' => $description,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function sequence(int $sequence): string
    {
        return str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    private function canManage(Request $request, Municipality $municipality): bool
    {
        return in_array($request->user()->roleForMunicipality($municipality->id), ['manager', 'auditor'], true);
    }

    private function ensureScope(Municipality $municipality): void
    {
        abort_unless($municipality->supportsTcespAudesp(), 404);
    }
}
