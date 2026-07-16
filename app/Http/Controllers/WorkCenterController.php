<?php

namespace App\Http\Controllers;

use App\Models\MunicipalWorkItem;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\MunicipalWorkItemService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WorkCenterController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $canEdit = $request->user()->canEditMunicipality($municipality->id);
        $selectedStatus = in_array($request->query('status'), [...array_keys(MunicipalWorkItem::statuses()), 'active'], true)
            ? $request->query('status')
            : 'active';
        $selectedPriority = array_key_exists((string) $request->query('priority'), MunicipalWorkItem::priorities())
            ? (string) $request->query('priority')
            : '';
        $selectedCategory = array_key_exists((string) $request->query('category'), MunicipalWorkItem::categories())
            ? (string) $request->query('category')
            : '';
        $selectedResponsible = $request->query('responsible');

        $query = $municipality->workItems()->with(['amendment', 'responsibleUser', 'events'])
            ->when($selectedStatus === 'active', fn ($query) => $query->whereIn('status', [MunicipalWorkItem::STATUS_PENDING, MunicipalWorkItem::STATUS_IN_PROGRESS]))
            ->when($selectedStatus !== 'active', fn ($query) => $query->where('status', $selectedStatus))
            ->when($selectedPriority !== '', fn ($query) => $query->where('priority', $selectedPriority))
            ->when($selectedCategory !== '', fn ($query) => $query->where('category', $selectedCategory))
            ->when($selectedResponsible === 'unassigned', fn ($query) => $query->whereNull('responsible_user_id'))
            ->when(is_numeric($selectedResponsible), fn ($query) => $query->where('responsible_user_id', (int) $selectedResponsible))
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END")
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->latest('first_detected_at');
        $items = $query->paginate(30)->withQueryString();
        $activeQuery = $municipality->workItems()->whereIn('status', [
            MunicipalWorkItem::STATUS_PENDING,
            MunicipalWorkItem::STATUS_IN_PROGRESS,
        ]);
        $responsibleUsers = $municipality->users()
            ->wherePivotIn('role', ['manager', 'editor'])
            ->orderBy('name')
            ->get();

        return view('work-center.index', [
            'municipality' => $municipality,
            'items' => $items,
            'canEdit' => $canEdit,
            'responsibleUsers' => $responsibleUsers,
            'statuses' => MunicipalWorkItem::statuses(),
            'priorities' => MunicipalWorkItem::priorities(),
            'categories' => MunicipalWorkItem::categories(),
            'selectedStatus' => $selectedStatus,
            'selectedPriority' => $selectedPriority,
            'selectedCategory' => $selectedCategory,
            'selectedResponsible' => $selectedResponsible,
            'metrics' => [
                'active' => (clone $activeQuery)->count(),
                'overdue' => (clone $activeQuery)->whereDate('due_at', '<', today())->count(),
                'next_seven_days' => (clone $activeQuery)->whereBetween('due_at', [today(), today()->addDays(7)])->count(),
                'unassigned' => (clone $activeQuery)->whereNull('responsible_user_id')->count(),
            ],
            'lastEvaluatedAt' => $municipality->workItems()->max('last_evaluated_at'),
            'syncToken' => $canEdit ? $formSubmission->issue($request, "work-items-sync-{$municipality->id}") : null,
            'updateTokens' => $canEdit ? $items->getCollection()->mapWithKeys(fn ($item) => [
                $item->id => $formSubmission->issue($request, "work-item-update-{$item->id}"),
            ]) : collect(),
        ]);
    }

    public function synchronize(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalWorkItemService $workItemService,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "work-items-sync-{$municipality->id}")) {
            return back()->with('warning', 'Este plano de trabalho já foi atualizado.');
        }

        $stats = $workItemService->synchronize($municipality);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'work_items_synchronized', $stats);

        return back()->with('status', sprintf(
            'Plano atualizado: %d ação(ões) ativa(s), %d nova(s) e %d resolvida(s).',
            $stats['active'],
            $stats['created'] + $stats['reopened'],
            $stats['completed'],
        ));
    }

    public function update(
        Request $request,
        int $item,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $workItem = $municipality->workItems()->findOrFail($item);
        abort_if($workItem->status === MunicipalWorkItem::STATUS_COMPLETED, 422, 'Ação já resolvida pela atualização dos dados de origem.');
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'status' => ['required', Rule::in([MunicipalWorkItem::STATUS_PENDING, MunicipalWorkItem::STATUS_IN_PROGRESS])],
            'responsible_user_id' => ['nullable', Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query
                ->where('municipality_id', $municipality->id)
                ->whereIn('role', ['manager', 'editor']))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $formSubmission->consume($request, "work-item-update-{$workItem->id}")) {
            return back()->with('warning', 'Esta atualização da ação já foi processada.');
        }

        $oldValues = $workItem->only(['status', 'responsible_user_id', 'notes']);
        $workItem->update([
            'status' => $validated['status'],
            'responsible_user_id' => $validated['responsible_user_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);
        $workItem->events()->create([
            'municipality_id' => $municipality->id,
            'user_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'event_type' => 'updated',
            'from_status' => $oldValues['status'],
            'to_status' => $workItem->status,
            'description' => 'Acompanhamento atualizado pela equipe municipal.',
            'metadata' => [
                'old_responsible_user_id' => $oldValues['responsible_user_id'],
                'responsible_user_id' => $workItem->responsible_user_id,
            ],
        ]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'work_item_updated', [
            'work_item' => $workItem->title,
            'work_item_status' => $workItem->statusLabel(),
            'responsible_user_id' => $workItem->responsible_user_id,
            'notes' => $workItem->notes,
        ], $oldValues);

        return back()->with('status', 'Responsabilidade e andamento da ação atualizados.');
    }
}
