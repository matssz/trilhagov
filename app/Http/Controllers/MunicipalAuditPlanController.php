<?php

namespace App\Http\Controllers;

use App\Models\MunicipalAuditPlan;
use App\Models\MunicipalAuditPlanItem;
use App\Models\MunicipalInternalControlReview;
use App\Models\Municipality;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\MunicipalAuditPlanService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class MunicipalAuditPlanController extends Controller
{
    public function index(Request $request, CurrentMunicipality $currentMunicipality, FormSubmission $forms): View
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $canManage = $this->canManage($request, $municipality);
        $plans = $municipality->auditPlans()->withCount([
            'items',
            'items as completed_items_count' => fn ($query) => $query->where('status', MunicipalAuditPlanItem::STATUS_COMPLETED),
            'items as overdue_items_count' => fn ($query) => $query->whereIn('status', ['planned', 'in_progress', 'rescheduled'])->whereDate('planned_at', '<', today()),
        ])->latest('fiscal_year')->latest('version')->get();

        return view('audit-plans.index', [
            'municipality' => $municipality,
            'plans' => $plans,
            'canManage' => $canManage,
            'createToken' => $canManage ? $forms->issue($request, 'municipal-audit-plan-create') : null,
        ]);
    }

    public function store(Request $request, CurrentMunicipality $currentMunicipality, FormSubmission $forms, AuditTrail $audit): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $validated = $request->validate($this->planRules());
        if (! $forms->consume($request, 'municipal-audit-plan-create')) {
            return back()->with('warning', 'Esta solicitação já foi processada.');
        }

        $plan = DB::transaction(function () use ($request, $municipality, $validated, $audit): MunicipalAuditPlan {
            Municipality::query()->whereKey($municipality->id)->lockForUpdate()->firstOrFail();
            $version = ((int) $municipality->auditPlans()->where('fiscal_year', $validated['fiscal_year'])->max('version')) + 1;
            $plan = $municipality->auditPlans()->create([
                ...$validated,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'version' => $version,
                'status' => MunicipalAuditPlan::STATUS_DRAFT,
            ]);
            $audit->recordMunicipalityOperation($request, $municipality, 'municipal_audit_plan_created', ['audit_plan_reference' => $plan->reference()]);

            return $plan;
        });

        return redirect()->route('audit-plans.show', $plan)->with('status', 'Minuta do Plano Anual de Auditoria criada.');
    }

    public function show(Request $request, int $plan, CurrentMunicipality $currentMunicipality, MunicipalAuditPlanService $service, FormSubmission $forms): View
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $plan = $this->plan($municipality, $plan);
        $plan->load(['creator:id,name', 'issuer:id,name', 'items.amendment', 'items.assignedUser:id,name,email', 'items.reviews', 'items.events']);
        $canManage = $this->canManage($request, $municipality);

        return view('audit-plans.show', [
            'municipality' => $municipality,
            'plan' => $plan,
            'canManage' => $canManage,
            'recommendations' => $plan->isDraft() && $canManage ? $service->recommendations($municipality, $plan) : collect(),
            'auditors' => $canManage ? $municipality->users()->wherePivotIn('role', ['manager', 'auditor'])->orderBy('name')->get(['users.id', 'users.name']) : collect(),
            'updateToken' => $plan->isDraft() && $canManage ? $forms->issue($request, "municipal-audit-plan-update-{$plan->id}") : null,
            'itemToken' => $plan->isDraft() && $canManage ? $forms->issue($request, "municipal-audit-plan-item-create-{$plan->id}") : null,
            'issueToken' => $plan->isDraft() && $canManage ? $forms->issue($request, "municipal-audit-plan-issue-{$plan->id}") : null,
            'itemUpdateTokens' => $plan->isDraft() && $canManage ? $plan->items->mapWithKeys(fn ($item) => [$item->id => $forms->issue($request, "municipal-audit-plan-item-update-{$item->id}")]) : collect(),
            'itemDeleteTokens' => $plan->isDraft() && $canManage ? $plan->items->mapWithKeys(fn ($item) => [$item->id => $forms->issue($request, "municipal-audit-plan-item-delete-{$item->id}")]) : collect(),
            'progressTokens' => ! $plan->isDraft() && $canManage ? $plan->items->whereNotIn('status', ['completed', 'cancelled'])->mapWithKeys(fn ($item) => [$item->id => $forms->issue($request, "municipal-audit-plan-item-progress-{$item->id}")]) : collect(),
            'blockers' => $plan->isDraft() ? $service->readiness($plan) : [],
            'phases' => MunicipalInternalControlReview::phases(),
            'priorities' => MunicipalAuditPlanItem::priorities(),
            'frequencies' => MunicipalAuditPlanItem::frequencies(),
        ]);
    }

    public function update(Request $request, int $plan, CurrentMunicipality $currentMunicipality, FormSubmission $forms, AuditTrail $audit): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $plan = $this->plan($municipality, $plan);
        abort_unless($plan->isDraft(), 409);
        $validated = $request->validate($this->planRules(includeYear: false));
        if (! $forms->consume($request, "municipal-audit-plan-update-{$plan->id}")) {
            return back()->with('warning', 'Esta atualização já foi processada.');
        }
        $plan->update([...$validated, 'updated_by' => $request->user()->id]);
        $audit->recordMunicipalityOperation($request, $municipality, 'municipal_audit_plan_updated', ['audit_plan_reference' => $plan->reference()]);

        return back()->with('status', 'Minuta do Plano Anual atualizada.');
    }

    public function addItem(Request $request, int $plan, CurrentMunicipality $currentMunicipality, FormSubmission $forms, AuditTrail $audit): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $plan = $this->plan($municipality, $plan);
        abort_unless($plan->isDraft(), 409);
        $validated = $request->validate($this->itemRules($municipality, $plan));
        if (! $forms->consume($request, "municipal-audit-plan-item-create-{$plan->id}")) {
            return back()->with('warning', 'Este item já foi processado.');
        }
        $item = $plan->items()->create([...$validated, 'municipality_id' => $municipality->id, 'created_by' => $request->user()->id, 'status' => 'planned']);
        $this->event($item, $request, 'created', null, 'planned', 'Item incluído na minuta do Plano Anual.');
        $audit->recordMunicipalityOperation($request, $municipality, 'municipal_audit_plan_item_created', ['audit_plan_reference' => $plan->reference(), 'amendment_id' => $item->parliamentary_amendment_id]);

        return back()->with('status', 'Emenda incluída na agenda anual.');
    }

    public function updateItem(Request $request, int $item, CurrentMunicipality $currentMunicipality, FormSubmission $forms, AuditTrail $audit): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $item = $this->item($municipality, $item);
        abort_unless($item->plan->isDraft(), 409);
        $validated = $request->validate($this->itemRules($municipality, $item->plan, $item));
        if (! $forms->consume($request, "municipal-audit-plan-item-update-{$item->id}")) {
            return back()->with('warning', 'Esta atualização já foi processada.');
        }
        $item->update($validated);
        $audit->recordMunicipalityOperation($request, $municipality, 'municipal_audit_plan_item_updated', [
            'audit_plan_reference' => $item->plan->reference(),
            'amendment_id' => $item->parliamentary_amendment_id,
        ]);

        return back()->with('status', 'Agenda da auditoria atualizada.');
    }

    public function removeItem(Request $request, int $item, CurrentMunicipality $currentMunicipality, FormSubmission $forms, AuditTrail $audit): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $item = $this->item($municipality, $item);
        abort_unless($item->plan->isDraft(), 409);
        if (! $forms->consume($request, "municipal-audit-plan-item-delete-{$item->id}")) {
            return back()->with('warning', 'Esta remoção já foi processada.');
        }
        $reference = $item->plan->reference();
        $amendmentId = $item->parliamentary_amendment_id;
        $item->delete();
        $audit->recordMunicipalityOperation($request, $municipality, 'municipal_audit_plan_item_removed', [
            'audit_plan_reference' => $reference,
            'amendment_id' => $amendmentId,
        ]);

        return back()->with('status', 'Item removido da minuta.');
    }

    public function issue(Request $request, int $plan, CurrentMunicipality $currentMunicipality, MunicipalAuditPlanService $service, FormSubmission $forms, AuditTrail $audit): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $plan = $this->plan($municipality, $plan);
        abort_unless($plan->isDraft(), 409);
        $request->validate(['_submission_token' => ['required', 'string'], 'confirm_plan' => ['accepted']]);
        if (! $forms->consume($request, "municipal-audit-plan-issue-{$plan->id}")) {
            return back()->with('warning', 'Esta emissão já foi processada.');
        }
        if (($blocker = collect($service->readiness($plan))->first()) !== null) {
            throw ValidationException::withMessages(['plan' => $blocker]);
        }

        DB::transaction(function () use ($request, $municipality, $plan, $service, $audit): void {
            $locked = MunicipalAuditPlan::query()->lockForUpdate()->findOrFail($plan->id);
            abort_unless($locked->isDraft(), 409);
            $snapshot = $service->snapshot($locked);
            $locked->update(['status' => 'issued', 'issued_by' => $request->user()->id, 'issued_at' => now(), 'snapshot' => $snapshot, 'snapshot_sha256' => $service->hash($snapshot)]);
            $audit->recordMunicipalityOperation($request, $municipality, 'municipal_audit_plan_issued', ['audit_plan_reference' => $locked->reference(), 'snapshot_sha256' => $locked->snapshot_sha256]);
        });

        return back()->with('status', 'Plano Anual de Auditoria emitido e fechado para auditoria.');
    }

    public function progress(Request $request, int $item, CurrentMunicipality $currentMunicipality, FormSubmission $forms, AuditTrail $audit): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $item = $this->item($municipality, $item);
        abort_unless(! $item->plan->isDraft() && ! in_array($item->status, ['completed', 'cancelled'], true), 409);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'status' => ['required', Rule::in(['in_progress', 'rescheduled', 'cancelled'])],
            'status_notes' => ['required', 'string', 'min:5', 'max:3000'],
            'planned_at' => ['nullable', 'required_if:status,rescheduled', 'date'],
        ]);
        if (! $forms->consume($request, "municipal-audit-plan-item-progress-{$item->id}")) {
            return back()->with('warning', 'Esta movimentação já foi processada.');
        }
        $from = $item->status;
        $item->update(['status' => $validated['status'], 'status_notes' => $validated['status_notes'], 'planned_at' => $validated['planned_at'] ?? $item->planned_at]);
        $this->event($item, $request, $validated['status'], $from, $validated['status'], $validated['status_notes']);
        $audit->recordMunicipalityOperation($request, $municipality, 'municipal_audit_plan_item_progressed', [
            'audit_plan_reference' => $item->plan->reference(),
            'amendment_id' => $item->parliamentary_amendment_id,
            'status' => $validated['status'],
        ], ['status' => $from]);

        return back()->with('status', 'Situação da auditoria atualizada.');
    }

    public function pdf(Request $request, int $plan, CurrentMunicipality $currentMunicipality, MunicipalAuditPlanService $service): Response
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $plan = $this->plan($municipality, $plan);
        $plan->load(['municipality', 'creator', 'issuer', 'items.amendment', 'items.assignedUser', 'items.reviews']);
        $document = $plan->isDraft() ? $service->snapshot($plan) : $plan->snapshot;
        $document['plan']['planned_start_label'] = Carbon::parse($document['plan']['planned_start_at'])->format('d/m/Y');
        $document['plan']['planned_end_label'] = Carbon::parse($document['plan']['planned_end_at'])->format('d/m/Y');
        $document['items'] = collect($document['items'])->map(function (array $item): array {
            return [
                ...$item,
                'planned_at_label' => Carbon::parse($item['planned_at'])->format('d/m/Y'),
                'phase_label' => MunicipalInternalControlReview::phases()[$item['phase']] ?? $item['phase'],
                'priority_label' => MunicipalAuditPlanItem::priorities()[$item['priority']] ?? $item['priority'],
                'frequency_label' => MunicipalAuditPlanItem::frequencies()[$item['frequency']] ?? $item['frequency'],
            ];
        })->all();

        return Pdf::loadView('audit-plans.pdf', compact('plan', 'document'))->setPaper('a4', 'landscape')->download(strtolower($plan->reference()).'.pdf');
    }

    private function planRules(bool $includeYear = true): array
    {
        $rules = [
            '_submission_token' => ['required', 'string'],
            'title' => ['required', 'string', 'min:5', 'max:220'],
            'objective' => ['required', 'string', 'min:20', 'max:5000'],
            'methodology' => ['required', 'string', 'min:20', 'max:5000'],
            'risk_criteria' => ['required', 'string', 'min:20', 'max:5000'],
            'normative_basis' => ['required', 'string', 'min:10', 'max:3000'],
            'coordination_unit' => ['required', 'string', 'min:3', 'max:180'],
            'planned_start_at' => ['required', 'date'],
            'planned_end_at' => ['required', 'date', 'after_or_equal:planned_start_at'],
            'management_notes' => ['nullable', 'string', 'max:5000'],
        ];
        if ($includeYear) {
            $rules['fiscal_year'] = ['required', 'integer', 'between:'.(now()->year - 1).','.(now()->year + 2)];
        }

        return $rules;
    }

    private function itemRules(Municipality $municipality, MunicipalAuditPlan $plan, ?MunicipalAuditPlanItem $item = null): array
    {
        return [
            '_submission_token' => ['required', 'string'],
            'parliamentary_amendment_id' => ['required', 'integer', Rule::exists('parliamentary_amendments', 'id')->where(fn ($query) => $query->where('municipality_id', $municipality->id)->where('government_sphere', 'municipal')), Rule::unique('municipal_audit_plan_items')->where(fn ($query) => $query->where('municipal_audit_plan_id', $plan->id)->where('phase', request('phase')))->ignore($item?->id)],
            'assigned_user_id' => ['required', 'integer', Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query->where('municipality_id', $municipality->id)->whereIn('role', ['manager', 'auditor']))],
            'phase' => ['required', Rule::in(['prior', 'concomitant', 'final'])],
            'priority' => ['required', Rule::in(array_keys(MunicipalAuditPlanItem::priorities()))],
            'frequency' => ['required', Rule::in(array_keys(MunicipalAuditPlanItem::frequencies()))],
            'planned_at' => ['required', 'date', 'after_or_equal:'.$plan->planned_start_at->format('Y-m-d'), 'before_or_equal:'.$plan->planned_end_at->format('Y-m-d')],
            'scope_notes' => ['required', 'string', 'min:10', 'max:3000'],
        ];
    }

    private function event(MunicipalAuditPlanItem $item, Request $request, string $type, ?string $from, string $to, string $description): void
    {
        $item->events()->create(['municipality_id' => $item->municipality_id, 'user_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'event_type' => $type, 'from_status' => $from, 'to_status' => $to, 'description' => $description]);
    }

    private function plan(Municipality $municipality, int $id): MunicipalAuditPlan
    {
        return $municipality->auditPlans()->findOrFail($id);
    }

    private function item(Municipality $municipality, int $id): MunicipalAuditPlanItem
    {
        return MunicipalAuditPlanItem::query()->where('municipality_id', $municipality->id)->with('plan')->findOrFail($id);
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
