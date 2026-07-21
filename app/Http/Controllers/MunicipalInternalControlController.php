<?php

namespace App\Http\Controllers;

use App\Models\MunicipalAuditPlan;
use App\Models\MunicipalAuditPlanItem;
use App\Models\MunicipalGovernanceReport;
use App\Models\MunicipalInternalControlAction;
use App\Models\MunicipalInternalControlActionEvent;
use App\Models\MunicipalInternalControlReview;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\MunicipalInternalControlService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MunicipalInternalControlController extends Controller
{
    public function index(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        MunicipalInternalControlService $controlService,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $amendment = $this->amendment($municipality, $emenda);
        $role = $request->user()->roleForMunicipality($municipality->id);
        $canIssue = in_array($role, ['manager', 'auditor'], true);
        $canRespond = in_array($role, ['manager', 'editor'], true);

        $reviews = $amendment->internalControlReviews()
            ->with([
                'reviewer:id,name,email',
                'governanceReport:id,reference,fiscal_year,reference_month,version',
                'auditPlanItem.plan:id,fiscal_year,version,status',
                'actions.responsibleUser:id,name,email',
                'actions.responder:id,name',
                'actions.resolver:id,name',
                'actions.events',
            ])
            ->latest('sequence')
            ->get();

        return view('internal-control.index', [
            'municipality' => $municipality,
            'amendment' => $amendment,
            'reviews' => $reviews,
            'criteria' => $controlService->criteria(),
            'criterionStatuses' => $controlService->criterionStatuses(),
            'canIssue' => $canIssue,
            'canRespond' => $canRespond,
            'role' => $role,
            'issueToken' => $canIssue ? $formSubmission->issue($request, "internal-control-review-create-{$amendment->id}") : null,
            'responseTokens' => $canRespond
                ? $reviews->flatMap->actions->filter(fn ($action) => in_array($action->status, ['open', 'returned'], true))
                    ->mapWithKeys(fn ($action) => [$action->id => $formSubmission->issue($request, "internal-control-action-response-{$action->id}")])
                : collect(),
            'decisionTokens' => $canIssue
                ? $reviews->flatMap->actions->where('status', MunicipalInternalControlAction::STATUS_RESPONDED)
                    ->mapWithKeys(fn ($action) => [$action->id => $formSubmission->issue($request, "internal-control-action-decision-{$action->id}")])
                : collect(),
            'operationalUsers' => $canIssue
                ? $municipality->users()->wherePivotIn('role', ['manager', 'editor'])->orderBy('name')->get(['users.id', 'users.name'])
                : collect(),
            'governanceReports' => $canIssue
                ? $municipality->governanceReports()->where('status', MunicipalGovernanceReport::STATUS_ISSUED)->latest('fiscal_year')->latest('reference_month')->take(18)->get()
                : collect(),
            'auditPlanItems' => $canIssue
                ? $amendment->auditPlanItems()
                    ->whereIn('status', [MunicipalAuditPlanItem::STATUS_PLANNED, MunicipalAuditPlanItem::STATUS_IN_PROGRESS, MunicipalAuditPlanItem::STATUS_RESCHEDULED])
                    ->whereHas('plan', fn ($query) => $query->where('status', MunicipalAuditPlan::STATUS_ISSUED))
                    ->with('plan:id,fiscal_year,version,status')
                    ->orderBy('planned_at')
                    ->get()
                : collect(),
        ]);
    }

    public function store(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        MunicipalInternalControlService $controlService,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $amendment = $this->amendment($municipality, $emenda);
        $criteriaDefinitions = $controlService->criteria();
        $validated = $request->validate($this->reviewRules($municipality, $criteriaDefinitions), [
            'annual_audit_plan_reference.required_without' => 'Selecione um item emitido do Plano Anual de Auditoria ou informe uma referência externa.',
            'responsible_user_id.required_unless' => 'Defina quem será responsável por tratar o apontamento.',
            'corrective_due_at.required_unless' => 'Defina o prazo municipal para a providência.',
        ]);

        if (! $formSubmission->consume($request, "internal-control-review-create-{$amendment->id}")) {
            return back()->with('warning', 'Este parecer já foi processado. Atualize a página para emitir outro.');
        }

        $this->validateConclusion($validated, array_keys($criteriaDefinitions));
        if (($validated['responsible_user_id'] ?? null) === $request->user()->id) {
            throw ValidationException::withMessages([
                'responsible_user_id' => 'Quem emite o parecer não pode ser responsável por responder ao próprio apontamento.',
            ]);
        }

        $storedEvidence = null;
        try {
            $storedEvidence = $this->storeEvidence($request->file('evidence'), $municipality, 'pareceres');
            $review = DB::transaction(function () use (
                $request, $municipality, $amendment, $validated, $controlService, $storedEvidence, $auditTrail
            ): MunicipalInternalControlReview {
                $locked = ParliamentaryAmendment::query()->lockForUpdate()->findOrFail($amendment->id);
                $auditPlanItem = null;
                if (! empty($validated['municipal_audit_plan_item_id'])) {
                    $auditPlanItem = MunicipalAuditPlanItem::query()
                        ->where('municipality_id', $municipality->id)
                        ->where('parliamentary_amendment_id', $locked->id)
                        ->whereIn('status', [MunicipalAuditPlanItem::STATUS_PLANNED, MunicipalAuditPlanItem::STATUS_IN_PROGRESS, MunicipalAuditPlanItem::STATUS_RESCHEDULED])
                        ->whereHas('plan', fn ($query) => $query->where('status', MunicipalAuditPlan::STATUS_ISSUED))
                        ->with('plan')
                        ->lockForUpdate()
                        ->find($validated['municipal_audit_plan_item_id']);

                    if ($auditPlanItem === null || $auditPlanItem->phase !== $validated['phase']) {
                        throw ValidationException::withMessages([
                            'municipal_audit_plan_item_id' => 'O item deve pertencer a um plano emitido, estar ativo e corresponder à fase escolhida.',
                        ]);
                    }
                }
                $sequence = ((int) $locked->internalControlReviews()->max('sequence')) + 1;
                $snapshot = $controlService->snapshot($locked);
                $review = $locked->internalControlReviews()->create([
                    'municipality_id' => $municipality->id,
                    'municipal_governance_report_id' => $validated['municipal_governance_report_id'] ?? null,
                    'municipal_audit_plan_item_id' => $auditPlanItem?->id,
                    'reviewed_by' => $request->user()->id,
                    'sequence' => $sequence,
                    'reference' => sprintf('PCI-%d-%05d-%03d', $locked->fiscal_year, $locked->id, $sequence),
                    'phase' => $validated['phase'],
                    'conclusion' => $validated['conclusion'],
                    'criteria' => $validated['criteria'],
                    'summary' => $validated['summary'],
                    'findings' => $validated['findings'] ?? null,
                    'recommendations' => $validated['recommendations'] ?? null,
                    'annual_audit_plan_reference' => $auditPlanItem?->formalReference() ?? $validated['annual_audit_plan_reference'],
                    'legal_basis' => $validated['legal_basis'],
                    'snapshot' => $snapshot,
                    'snapshot_sha256' => $controlService->hash($snapshot),
                    ...($storedEvidence ?? []),
                    'issued_at' => now(),
                ]);

                if ($auditPlanItem !== null) {
                    $fromStatus = $auditPlanItem->status;
                    $auditPlanItem->update([
                        'status' => MunicipalAuditPlanItem::STATUS_COMPLETED,
                        'completed_by' => $request->user()->id,
                        'completed_at' => now(),
                        'status_notes' => "Concluída pelo parecer {$review->reference}.",
                    ]);
                    $auditPlanItem->events()->create([
                        'municipality_id' => $municipality->id,
                        'user_id' => $request->user()->id,
                        'actor_name' => $request->user()->name,
                        'event_type' => 'completed',
                        'from_status' => $fromStatus,
                        'to_status' => MunicipalAuditPlanItem::STATUS_COMPLETED,
                        'description' => "Item concluído automaticamente pela emissão do parecer {$review->reference}.",
                        'metadata' => ['internal_control_review_id' => $review->id, 'reference' => $review->reference],
                    ]);
                }

                if ($review->conclusion !== MunicipalInternalControlReview::CONCLUSION_REGULAR) {
                    $action = $review->actions()->create([
                        'municipality_id' => $municipality->id,
                        'parliamentary_amendment_id' => $locked->id,
                        'responsible_user_id' => $validated['responsible_user_id'],
                        'created_by' => $request->user()->id,
                        'status' => MunicipalInternalControlAction::STATUS_OPEN,
                        'title' => 'Tratar apontamentos do '.$review->reference,
                        'instructions' => $validated['recommendations'],
                        'due_at' => $validated['corrective_due_at'],
                    ]);
                    $action->events()->create([
                        'municipality_id' => $municipality->id,
                        'user_id' => $request->user()->id,
                        'actor_name' => $request->user()->name,
                        'event_type' => 'created',
                        'to_status' => MunicipalInternalControlAction::STATUS_OPEN,
                        'description' => 'Providência aberta automaticamente a partir do parecer emitido.',
                    ]);
                }

                $auditTrail->recordOperation($request, $locked, 'internal_control_review_issued', [
                    'internal_control_reference' => $review->reference,
                    'internal_control_phase' => $review->phase,
                    'internal_control_conclusion' => $review->conclusion,
                    'snapshot_sha256' => $review->snapshot_sha256,
                ]);

                return $review;
            });
        } catch (Throwable $exception) {
            if ($storedEvidence !== null) {
                Storage::disk('local')->delete($storedEvidence['evidence_path']);
            }
            throw $exception;
        }

        return redirect()->route('emendas.internal-control', $amendment)
            ->with('status', "Parecer {$review->reference} emitido e preservado para auditoria.");
    }

    public function respond(
        Request $request,
        int $action,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $action = $this->action($municipality, $action);
        $role = $request->user()->roleForMunicipality($municipality->id);
        abort_unless($role === 'manager' || $action->responsible_user_id === $request->user()->id, 403);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'response_summary' => ['required', 'string', 'min:10', 'max:5000'],
            'evidence' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(10 * 1024)],
        ]);
        if (! $formSubmission->consume($request, "internal-control-action-response-{$action->id}")) {
            return back()->with('warning', 'Esta resposta já foi processada.');
        }

        $storedEvidence = null;
        try {
            $storedEvidence = $this->storeEvidence($request->file('evidence'), $municipality, 'providencias');
            DB::transaction(function () use ($request, $action, $validated, $storedEvidence, $auditTrail): void {
                $locked = MunicipalInternalControlAction::query()->lockForUpdate()->with('amendment')->findOrFail($action->id);
                abort_unless(in_array($locked->status, [MunicipalInternalControlAction::STATUS_OPEN, MunicipalInternalControlAction::STATUS_RETURNED], true), 409);
                $from = $locked->status;
                $locked->update([
                    'status' => MunicipalInternalControlAction::STATUS_RESPONDED,
                    'response_summary' => $validated['response_summary'],
                    'responded_by' => $request->user()->id,
                    'responded_at' => now(),
                    'resolved_by' => null,
                    'resolved_at' => null,
                    'resolution_notes' => null,
                ]);
                $this->recordActionEvent($locked, $request, 'response', $from, MunicipalInternalControlAction::STATUS_RESPONDED, $validated['response_summary'], $storedEvidence);
                $auditTrail->recordOperation($request, $locked->amendment, 'internal_control_action_responded', [
                    'internal_control_action_id' => $locked->id,
                    'internal_control_response' => $validated['response_summary'],
                ]);
            });
        } catch (Throwable $exception) {
            if ($storedEvidence !== null) {
                Storage::disk('local')->delete($storedEvidence['evidence_path']);
            }
            throw $exception;
        }

        return back()->with('status', 'Providência respondida. O Controle Interno foi acionado para validar o saneamento.');
    }

    public function decide(
        Request $request,
        int $action,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $action = $this->action($municipality, $action);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'decision' => ['required', Rule::in(['resolved', 'returned'])],
            'resolution_notes' => ['required', 'string', 'min:10', 'max:5000'],
            'new_due_at' => ['nullable', 'required_if:decision,returned', 'date', 'after_or_equal:today'],
        ], [
            'new_due_at.required_if' => 'Informe o novo prazo para devolver a providência.',
        ]);
        if (! $formSubmission->consume($request, "internal-control-action-decision-{$action->id}")) {
            return back()->with('warning', 'Esta decisão já foi processada.');
        }
        if ($action->responded_by === $request->user()->id) {
            throw ValidationException::withMessages([
                'decision' => 'Quem respondeu à providência não pode validar o próprio saneamento.',
            ]);
        }

        DB::transaction(function () use ($request, $action, $validated, $auditTrail): void {
            $locked = MunicipalInternalControlAction::query()->lockForUpdate()->with('amendment')->findOrFail($action->id);
            abort_unless($locked->status === MunicipalInternalControlAction::STATUS_RESPONDED, 409);
            $to = $validated['decision'] === 'resolved'
                ? MunicipalInternalControlAction::STATUS_RESOLVED
                : MunicipalInternalControlAction::STATUS_RETURNED;
            $locked->update([
                'status' => $to,
                'due_at' => $validated['new_due_at'] ?? $locked->due_at,
                'resolved_by' => $request->user()->id,
                'resolved_at' => $to === MunicipalInternalControlAction::STATUS_RESOLVED ? now() : null,
                'resolution_notes' => $validated['resolution_notes'],
            ]);
            $this->recordActionEvent($locked, $request, $to === 'resolved' ? 'resolved' : 'returned', MunicipalInternalControlAction::STATUS_RESPONDED, $to, $validated['resolution_notes']);
            $auditTrail->recordOperation($request, $locked->amendment, 'internal_control_action_decided', [
                'internal_control_action_id' => $locked->id,
                'internal_control_action_status' => $to,
                'internal_control_resolution' => $validated['resolution_notes'],
            ]);
        });

        return back()->with('status', $validated['decision'] === 'resolved'
            ? 'Saneamento validado e providência encerrada.'
            : 'Providência devolvida para nova correção.');
    }

    public function pdf(Request $request, int $review, CurrentMunicipality $currentMunicipality): Response
    {
        $municipality = $currentMunicipality->get($request);
        $review = $this->review($municipality, $review);
        $review->load(['municipality', 'amendment', 'reviewer', 'governanceReport', 'actions.responsibleUser', 'actions.events']);

        return Pdf::loadView('internal-control.pdf', [
            'review' => $review,
            'criteriaDefinitions' => app(MunicipalInternalControlService::class)->criteria(),
            'criterionStatuses' => app(MunicipalInternalControlService::class)->criterionStatuses(),
        ])->setPaper('a4')->download(Str::slug($review->reference).'.pdf');
    }

    public function reviewEvidence(Request $request, int $review, CurrentMunicipality $currentMunicipality): StreamedResponse
    {
        $municipality = $currentMunicipality->get($request);
        $review = $this->review($municipality, $review);
        abort_if($review->evidence_path === null || ! Storage::disk('local')->exists($review->evidence_path), 404);

        return Storage::disk('local')->download($review->evidence_path, $review->evidence_original_name, [
            'Content-Type' => $review->evidence_mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function actionEvidence(Request $request, int $event, CurrentMunicipality $currentMunicipality): StreamedResponse
    {
        $municipality = $currentMunicipality->get($request);
        $event = MunicipalInternalControlActionEvent::query()
            ->where('municipality_id', $municipality->id)
            ->findOrFail($event);
        abort_if($event->evidence_path === null || ! Storage::disk('local')->exists($event->evidence_path), 404);

        return Storage::disk('local')->download($event->evidence_path, $event->evidence_original_name, [
            'Content-Type' => $event->evidence_mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, array<string, string>> $criteria */
    private function reviewRules(Municipality $municipality, array $criteria): array
    {
        $rules = [
            '_submission_token' => ['required', 'string'],
            'phase' => ['required', Rule::in(array_keys(MunicipalInternalControlReview::phases()))],
            'conclusion' => ['required', Rule::in(array_keys(MunicipalInternalControlReview::conclusions()))],
            'summary' => ['required', 'string', 'min:20', 'max:5000'],
            'findings' => ['nullable', 'required_unless:conclusion,regular', 'string', 'max:5000'],
            'recommendations' => ['nullable', 'required_unless:conclusion,regular', 'string', 'max:5000'],
            'municipal_audit_plan_item_id' => [
                'nullable', 'integer',
                Rule::exists('municipal_audit_plan_items', 'id')->where(fn ($query) => $query
                    ->where('municipality_id', $municipality->id)),
            ],
            'annual_audit_plan_reference' => ['nullable', 'required_without:municipal_audit_plan_item_id', 'string', 'min:3', 'max:255'],
            'legal_basis' => ['required', 'string', 'min:5', 'max:2000'],
            'municipal_governance_report_id' => [
                'nullable', 'integer',
                Rule::exists('municipal_governance_reports', 'id')->where(fn ($query) => $query
                    ->where('municipality_id', $municipality->id)
                    ->where('status', MunicipalGovernanceReport::STATUS_ISSUED)),
            ],
            'responsible_user_id' => [
                'nullable', 'required_unless:conclusion,regular', 'integer',
                Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query
                    ->where('municipality_id', $municipality->id)
                    ->whereIn('role', ['manager', 'editor'])),
            ],
            'corrective_due_at' => ['nullable', 'required_unless:conclusion,regular', 'date', 'after_or_equal:today'],
            'evidence' => ['nullable', File::types(['pdf'])->max(10 * 1024)],
            'criteria' => ['required', 'array:'.implode(',', array_keys($criteria)), 'size:'.count($criteria)],
        ];
        foreach (array_keys($criteria) as $code) {
            $rules["criteria.{$code}.status"] = ['required', Rule::in(['compliant', 'attention', 'non_compliant', 'not_applicable'])];
            $rules["criteria.{$code}.notes"] = ['nullable', 'string', 'max:1000'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $validated @param array<int, string> $criterionCodes */
    private function validateConclusion(array $validated, array $criterionCodes): void
    {
        $statuses = collect($criterionCodes)->map(fn ($code) => $validated['criteria'][$code]['status']);
        foreach ($criterionCodes as $code) {
            $item = $validated['criteria'][$code];
            if (in_array($item['status'], ['attention', 'non_compliant'], true) && blank($item['notes'] ?? null)) {
                throw ValidationException::withMessages([
                    "criteria.{$code}.notes" => 'Descreva a evidência ou o problema encontrado neste item.',
                ]);
            }
        }

        $conclusion = $validated['conclusion'];
        if ($conclusion === MunicipalInternalControlReview::CONCLUSION_REGULAR
            && $statuses->contains(fn ($status) => in_array($status, ['attention', 'non_compliant'], true))) {
            throw ValidationException::withMessages(['conclusion' => 'Um parecer regular não pode conter ponto de atenção ou não conformidade.']);
        }
        if ($conclusion === MunicipalInternalControlReview::CONCLUSION_RECOMMENDATIONS
            && (! $statuses->contains('attention') || $statuses->contains('non_compliant'))) {
            throw ValidationException::withMessages(['conclusion' => 'A conclusão com recomendações exige ponto de atenção e não admite item não conforme.']);
        }
        if ($conclusion === MunicipalInternalControlReview::CONCLUSION_DILIGENCE
            && ! $statuses->contains(fn ($status) => in_array($status, ['attention', 'non_compliant'], true))) {
            throw ValidationException::withMessages(['conclusion' => 'Uma diligência exige ao menos um ponto de atenção ou item não conforme.']);
        }
        if ($conclusion === MunicipalInternalControlReview::CONCLUSION_IRREGULAR && ! $statuses->contains('non_compliant')) {
            throw ValidationException::withMessages(['conclusion' => 'Um parecer irregular exige ao menos um item não conforme.']);
        }
    }

    /** @return array<string, mixed>|null */
    private function storeEvidence(?UploadedFile $file, Municipality $municipality, string $folder): ?array
    {
        if ($file === null) {
            return null;
        }
        $sha256 = hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs(
            "municipalities/{$municipality->id}/internal-control/{$folder}",
            Str::uuid().'.'.$file->guessExtension(),
            'local',
        );

        return [
            'evidence_path' => $path,
            'evidence_original_name' => $file->getClientOriginalName(),
            'evidence_mime' => $file->getMimeType(),
            'evidence_size' => $file->getSize(),
            'evidence_sha256' => $sha256,
        ];
    }

    /** @param array<string, mixed>|null $evidence */
    private function recordActionEvent(
        MunicipalInternalControlAction $action,
        Request $request,
        string $type,
        ?string $from,
        string $to,
        string $description,
        ?array $evidence = null,
    ): void {
        $action->events()->create([
            'municipality_id' => $action->municipality_id,
            'user_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'description' => $description,
            ...($evidence ?? []),
        ]);
    }

    private function amendment(Municipality $municipality, int $id): ParliamentaryAmendment
    {
        $amendment = $municipality->amendments()->with('municipality')->findOrFail($id);
        abort_unless($amendment->supportsTcespCompliance(), 404);

        return $amendment;
    }

    private function review(Municipality $municipality, int $id): MunicipalInternalControlReview
    {
        return MunicipalInternalControlReview::query()->where('municipality_id', $municipality->id)->findOrFail($id);
    }

    private function action(Municipality $municipality, int $id): MunicipalInternalControlAction
    {
        return MunicipalInternalControlAction::query()
            ->where('municipality_id', $municipality->id)
            ->with(['review', 'amendment'])
            ->findOrFail($id);
    }
}
