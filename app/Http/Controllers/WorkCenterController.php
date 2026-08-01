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
        $selectedQueue = in_array($request->query('queue'), array_keys($this->queueDefinitions()), true)
            ? (string) $request->query('queue')
            : '';

        $query = $municipality->workItems()->with(['amendment', 'responsibleUser', 'events'])
            ->when($selectedStatus === 'active', fn ($query) => $query->whereIn('status', [MunicipalWorkItem::STATUS_PENDING, MunicipalWorkItem::STATUS_IN_PROGRESS]))
            ->when($selectedStatus !== 'active', fn ($query) => $query->where('status', $selectedStatus))
            ->when($selectedPriority !== '', fn ($query) => $query->where('priority', $selectedPriority))
            ->when($selectedCategory !== '', fn ($query) => $query->where('category', $selectedCategory))
            ->when($selectedResponsible === 'unassigned', fn ($query) => $query->whereNull('responsible_user_id'))
            ->when(is_numeric($selectedResponsible), fn ($query) => $query->where('responsible_user_id', (int) $selectedResponsible));
        $this->applyQueueFilter($query, $selectedQueue, $request->user()->id);
        $query->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END")
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
            'selectedQueue' => $selectedQueue,
            'queueCards' => $this->queueCards($activeQuery, $request->user()->id, $selectedQueue),
            'decisionCards' => $this->decisionCards($activeQuery, $request->user()->id),
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

    /** @return array<string, array{label: string, description: string, icon: string}> */
    private function queueDefinitions(): array
    {
        return [
            'mine' => ['label' => 'Minhas ações', 'description' => 'Pendências atribuídas a você.', 'icon' => 'user-check'],
            'unassigned' => ['label' => 'Sem responsável', 'description' => 'Ações que precisam de dono.', 'icon' => 'user-round-x'],
            'overdue' => ['label' => 'Atrasadas', 'description' => 'Prazos vencidos ou críticos.', 'icon' => 'triangle-alert'],
            'chamber' => ['label' => 'Câmara e protocolo', 'description' => 'Comunicações, ofícios e retornos.', 'icon' => 'landmark'],
            'executive' => ['label' => 'Executivo e plano', 'description' => 'Responsáveis, normas e plano de trabalho.', 'icon' => 'clipboard-list'],
            'documents' => ['label' => 'Documentos', 'description' => 'Anexos e evidências obrigatórias.', 'icon' => 'file-check-2'],
            'health_control' => ['label' => 'Saúde e controle', 'description' => 'ASPS, TCESP e Controle Interno.', 'icon' => 'shield-check'],
            'execution' => ['label' => 'Execução e contas', 'description' => 'Financeiro, contratos e prestação.', 'icon' => 'chart-no-axes-combined'],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, description: string, icon: string, count: int, active: bool}>
     */
    private function queueCards($baseQuery, int $userId, string $selectedQueue): array
    {
        $cards = [];
        foreach ($this->queueDefinitions() as $key => $definition) {
            $query = clone $baseQuery;
            $this->applyQueueFilter($query, $key, $userId);
            $cards[] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'icon' => $definition['icon'],
                'count' => $query->count(),
                'active' => $selectedQueue === $key,
            ];
        }

        return $cards;
    }

    /**
     * @return array<int, array{label: string, description: string, icon: string, count: int, href: string, tone: string}>
     */
    private function decisionCards($baseQuery, int $userId): array
    {
        return collect([
            ['queue' => 'overdue', 'label' => 'Resolver atrasos', 'description' => 'Prazos vencidos que podem gerar apontamento.', 'icon' => 'triangle-alert', 'tone' => 'danger'],
            ['queue' => 'unassigned', 'label' => 'Definir responsaveis', 'description' => 'Itens sem dono tendem a parar o fluxo.', 'icon' => 'user-round-plus', 'tone' => 'warning'],
            ['queue' => 'chamber', 'label' => 'Tratar Camara', 'description' => 'Protocolos, comunicacoes e retornos legislativos.', 'icon' => 'landmark', 'tone' => 'primary'],
            ['queue' => 'execution', 'label' => 'Fechar execucao e contas', 'description' => 'Empenhos, pagamentos e prestacoes pendentes.', 'icon' => 'chart-no-axes-combined', 'tone' => 'success'],
            ['queue' => 'health_control', 'label' => 'Conferir saude e TCESP', 'description' => 'Reserva, ASPS, controle interno e matriz TCESP.', 'icon' => 'shield-check', 'tone' => 'primary'],
        ])->map(function (array $definition) use ($baseQuery, $userId): array {
            $query = clone $baseQuery;
            $this->applyQueueFilter($query, $definition['queue'], $userId);

            return [
                'label' => $definition['label'],
                'description' => $definition['description'],
                'icon' => $definition['icon'],
                'count' => $query->count(),
                'href' => route('work-center.index', ['queue' => $definition['queue']]),
                'tone' => $definition['tone'],
            ];
        })->all();
    }

    private function applyQueueFilter($query, string $queue, int $userId): void
    {
        match ($queue) {
            'mine' => $query->where('responsible_user_id', $userId),
            'unassigned' => $query->whereNull('responsible_user_id'),
            'overdue' => $query->whereDate('due_at', '<', today()),
            'chamber' => $query->whereIn('category', ['communication', 'impediment']),
            'executive' => $query->whereIn('category', ['responsibility', 'normative', 'planning']),
            'documents' => $query->where('category', 'document'),
            'health_control' => $query->whereIn('category', ['health', 'control']),
            'execution' => $query->whereIn('category', ['execution', 'financial', 'contract', 'accountability']),
            default => null,
        };
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
