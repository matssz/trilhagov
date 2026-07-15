@extends('layouts.app')

@section('title', 'Painel | Emendas Municipais')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <p class="page-kicker mb-2">{{ $municipality->name }} / {{ $municipality->state }}</p>
            <h1 class="h3 mb-1">Painel de emendas</h1>
            <p class="text-secondary mb-0">Posição consolidada em {{ now()->format('d/m/Y') }}.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('emendas.create') }}">Nova emenda</a>
    </div>

    <section class="metric-grid mb-4" aria-label="Indicadores">
        <div class="metric-card">
            <div class="metric-label">Emendas registradas</div>
            <div class="metric-value">{{ $amendmentCount }}</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Valor previsto</div>
            <div class="metric-value">R$ {{ number_format($expectedTotal, 2, ',', '.') }}</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Valor recebido</div>
            <div class="metric-value">R$ {{ number_format($receivedTotal, 2, ',', '.') }}</div>
        </div>
        <div class="metric-card {{ $overdueCount > 0 ? 'border-danger' : '' }}">
            <div class="metric-label">Prazos vencidos</div>
            <div class="metric-value {{ $overdueCount > 0 ? 'text-danger' : '' }}">{{ $overdueCount }}</div>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-lg-7">
            <section class="content-panel h-100">
                <div class="content-panel-header d-flex justify-content-between align-items-center gap-3">
                    <h2 class="h5 mb-0">Próximos prazos</h2>
                    <a href="{{ route('emendas.index') }}">Ver todas</a>
                </div>
                <div class="content-panel-body">
                    @forelse ($deadlines as $item)
                        @php
                            $deadline = $item['deadline'];
                            $isOverdue = $deadline['date']->isBefore(today());
                            $isUpcoming = $deadline['date']->betweenIncluded(today(), today()->addDays(30));
                        @endphp
                        <div class="deadline-row">
                            <div>
                                <a class="fw-semibold" href="{{ route('emendas.show', $item['amendment']) }}">{{ $item['amendment']->reference }}</a>
                                <div class="small text-secondary">{{ $deadline['label'] }} · {{ $item['amendment']->responsible_department }}</div>
                            </div>
                            <div class="deadline-date {{ $isOverdue ? 'deadline-overdue' : ($isUpcoming ? 'deadline-upcoming' : '') }}">
                                {{ $deadline['date']->format('d/m/Y') }}
                                @if ($isOverdue)<div class="small">Vencido</div>@endif
                            </div>
                        </div>
                    @empty
                        <div class="empty-state">Nenhum prazo registrado.</div>
                    @endforelse
                </div>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="content-panel h-100">
                <div class="content-panel-header">
                    <h2 class="h5 mb-0">Cadastros recentes</h2>
                </div>
                <div class="content-panel-body">
                    @forelse ($recentAmendments as $amendment)
                        <div class="deadline-row">
                            <div>
                                <a class="fw-semibold" href="{{ route('emendas.show', $amendment) }}">{{ $amendment->reference }}</a>
                                <div class="small text-secondary">{{ $amendment->author_name }}</div>
                            </div>
                            <x-amendment-status-badge :status="$amendment->status" :label="$amendment->statusLabel()" />
                        </div>
                    @empty
                        <div class="empty-state">
                            <p class="mb-3">Nenhuma emenda cadastrada.</p>
                            <a class="btn btn-primary" href="{{ route('emendas.create') }}">Cadastrar primeira emenda</a>
                        </div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
@endsection
