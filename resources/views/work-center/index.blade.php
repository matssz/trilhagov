@extends('layouts.app')

@section('title', 'Central de Trabalho | TrilhaGov')

@section('content')
    @php
        $categoryIcons = [
            'responsibility' => 'user-round-check',
            'communication' => 'message-square',
            'document' => 'file-check-2',
            'planning' => 'clipboard-list',
            'impediment' => 'shield-alert',
            'execution' => 'gauge',
            'financial' => 'receipt-text',
            'accountability' => 'clipboard-check',
        ];
    @endphp

    <header class="work-heading">
        <div>
            <p class="page-kicker mb-2">Operação municipal</p>
            <h1>Central de Trabalho</h1>
            <p>{{ $municipality->name }} · ações organizadas por risco e prazo</p>
        </div>
        @if ($canEdit)
            <form method="POST" action="{{ route('work-center.sync') }}">
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $syncToken }}">
                <button class="btn btn-primary" type="submit"><i data-lucide="refresh-cw" aria-hidden="true"></i>Atualizar plano</button>
            </form>
        @endif
    </header>

    <section class="work-status-band">
        <span><i data-lucide="clipboard-check" aria-hidden="true"></i></span>
        <div><strong>Plano operacional municipal</strong><small>{{ $lastEvaluatedAt ? 'Avaliado em '.\Illuminate\Support\Carbon::parse($lastEvaluatedAt)->format('d/m/Y H:i') : 'Aguardando primeira avaliação' }}</small></div>
        <p>As ações são encerradas quando a pendência de origem é corrigida.</p>
    </section>

    <section class="work-metrics" aria-label="Resumo das ações">
        <article><span class="metric-active"><i data-lucide="list-checks" aria-hidden="true"></i></span><div><small>Ações ativas</small><strong>{{ $metrics['active'] }}</strong></div></article>
        <article><span class="metric-critical"><i data-lucide="triangle-alert" aria-hidden="true"></i></span><div><small>Atrasadas</small><strong>{{ $metrics['overdue'] }}</strong></div></article>
        <article><span class="metric-due"><i data-lucide="calendar-clock" aria-hidden="true"></i></span><div><small>Próximos 7 dias</small><strong>{{ $metrics['next_seven_days'] }}</strong></div></article>
        <article><span class="metric-owner"><i data-lucide="user-round-check" aria-hidden="true"></i></span><div><small>Sem responsável</small><strong>{{ $metrics['unassigned'] }}</strong></div></article>
    </section>

    <form class="work-filters" method="GET" action="{{ route('work-center.index') }}">
        <label><span>Situação</span><select class="form-select" name="status"><option value="active" @selected($selectedStatus === 'active')>Ativas</option>@foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>@endforeach</select></label>
        <label><span>Prioridade</span><select class="form-select" name="priority"><option value="">Todas</option>@foreach ($priorities as $value => $label)<option value="{{ $value }}" @selected($selectedPriority === $value)>{{ $label }}</option>@endforeach</select></label>
        <label><span>Frente</span><select class="form-select" name="category"><option value="">Todas</option>@foreach ($categories as $value => $label)<option value="{{ $value }}" @selected($selectedCategory === $value)>{{ $label }}</option>@endforeach</select></label>
        <label><span>Responsável</span><select class="form-select" name="responsible"><option value="">Todos</option><option value="unassigned" @selected($selectedResponsible === 'unassigned')>Sem responsável</option>@foreach ($responsibleUsers as $member)<option value="{{ $member->id }}" @selected((string) $selectedResponsible === (string) $member->id)>{{ $member->name }}</option>@endforeach</select></label>
        <div class="work-filter-actions"><button class="btn btn-primary" type="submit"><i data-lucide="list-filter" aria-hidden="true"></i>Filtrar</button>@if (request()->query())<a class="icon-button" href="{{ route('work-center.index') }}" title="Limpar filtros" aria-label="Limpar filtros"><i data-lucide="filter-x" aria-hidden="true"></i></a>@endif</div>
    </form>

    <section class="work-queue" aria-label="Fila de trabalho">
        <header><div><p class="panel-kicker">Fila priorizada</p><h2>{{ $selectedStatus === 'active' ? 'Próximas ações' : ($statuses[$selectedStatus] ?? 'Ações') }}</h2></div><span>{{ $items->total() }} resultado(s)</span></header>
        @forelse ($items as $item)
            <details class="work-item priority-{{ $item->priority }} status-{{ $item->status }}">
                <summary>
                    <span class="work-item-icon"><i data-lucide="{{ $categoryIcons[$item->category] ?? 'list-checks' }}" aria-hidden="true"></i></span>
                    <span class="work-item-main"><span><strong>{{ $item->title }}</strong><small>{{ $item->categoryLabel() }}</small></span><small><a href="{{ route('emendas.show', $item->amendment) }}">{{ $item->amendment->reference }}</a> · {{ $item->amendment->responsible_department }}</small></span>
                    <span class="work-item-due {{ $item->due_at?->isBefore(today()) ? 'is-overdue' : '' }}"><small>Prazo</small><strong>{{ $item->due_at?->format('d/m/Y') ?? 'Sem prazo' }}</strong></span>
                    <span class="work-priority">{{ $item->priorityLabel() }}</span>
                    <span class="work-item-owner"><small>Responsável</small><strong>{{ $item->responsibleUser?->name ?? 'Não definido' }}</strong></span>
                    <i class="work-chevron" data-lucide="chevron-down" aria-hidden="true"></i>
                </summary>
                <div class="work-item-detail">
                    <div class="work-guidance"><strong>Próximo passo</strong><p>{{ $item->guidance }}</p>@if ($item->status === 'completed' && $item->completion_reason)<small><i data-lucide="circle-check" aria-hidden="true"></i>{{ $item->completion_reason }} {{ $item->completed_at?->format('d/m/Y H:i') }}</small>@endif</div>
                    <a class="btn btn-outline-primary" href="{{ $item->action_url }}"><i data-lucide="external-link" aria-hidden="true"></i>Abrir contexto</a>
                    @if ($canEdit && $item->status !== 'completed')
                        <form class="work-item-form" method="POST" action="{{ route('work-center.items.update', $item) }}">
                            @csrf
                            @method('PATCH')
                            <input name="_submission_token" type="hidden" value="{{ $updateTokens[$item->id] ?? '' }}">
                            <label><span>Andamento</span><select class="form-select" name="status">@foreach (array_slice($statuses, 0, 2, true) as $value => $label)<option value="{{ $value }}" @selected($item->status === $value)>{{ $label }}</option>@endforeach</select></label>
                            <label><span>Responsável</span><select class="form-select" name="responsible_user_id"><option value="">Não definido</option>@foreach ($responsibleUsers as $member)<option value="{{ $member->id }}" @selected($item->responsible_user_id === $member->id)>{{ $member->name }}</option>@endforeach</select></label>
                            <label class="work-notes"><span>Anotação operacional</span><textarea class="form-control" name="notes" rows="2" maxlength="2000">{{ $item->notes }}</textarea></label>
                            <button class="btn btn-primary" type="submit"><i data-lucide="check" aria-hidden="true"></i>Salvar acompanhamento</button>
                        </form>
                    @elseif ($item->notes)
                        <div class="work-saved-note"><strong>Anotação:</strong> {{ $item->notes }}</div>
                    @endif
                    @if ($item->events->isNotEmpty())
                        <div class="work-history"><strong>Histórico</strong><div>@foreach ($item->events->take(4) as $event)<span><i data-lucide="history" aria-hidden="true"></i><span>{{ $event->description }}<small>{{ $event->actor_name }} · {{ $event->created_at->format('d/m/Y H:i') }}</small></span></span>@endforeach</div></div>
                    @endif
                </div>
            </details>
        @empty
            <div class="work-empty"><span><i data-lucide="clipboard-check" aria-hidden="true"></i></span><h2>{{ $lastEvaluatedAt ? 'Nenhuma ação neste filtro' : 'Plano ainda não avaliado' }}</h2><p>{{ $lastEvaluatedAt ? 'Ajuste os filtros para consultar outras ações.' : 'Atualize o plano para organizar as próximas ações do município.' }}</p></div>
        @endforelse
    </section>

    {{ $items->links() }}
@endsection
