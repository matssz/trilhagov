@extends('layouts.app')

@section('title', 'Emendas | Emendas Municipais')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <p class="page-kicker mb-2">{{ $municipality->name }} / {{ $municipality->state }}</p>
            <h1 class="h3 mb-1">Emendas parlamentares</h1>
        </div>
        <a class="btn btn-primary" href="{{ route('emendas.create') }}">Nova emenda</a>
    </div>

    <form class="filters-grid mb-4" method="GET" action="{{ route('emendas.index') }}">
        <input class="form-control" name="search" type="search" value="{{ $search }}" placeholder="Buscar identificação, objeto, autor ou órgão" aria-label="Buscar emendas">
        <select class="form-select" name="status" aria-label="Filtrar por situação">
            <option value="">Todas as situações</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <input class="form-control" name="year" type="number" value="{{ $selectedYear }}" min="2000" max="{{ now()->year + 1 }}" placeholder="Exercício" aria-label="Filtrar por exercício">
        <button class="btn btn-outline-primary" type="submit">Filtrar</button>
    </form>

    <div class="content-panel">
        @if ($amendments->isEmpty())
            <div class="empty-state">
                <p class="mb-3">Nenhuma emenda encontrada.</p>
                <a class="btn btn-primary" href="{{ route('emendas.create') }}">Cadastrar emenda</a>
            </div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Identificação</th>
                            <th>Objeto</th>
                            <th>Autor</th>
                            <th>Valor previsto</th>
                            <th>Próximo prazo</th>
                            <th>Situação</th>
                            <th class="text-end">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($amendments as $amendment)
                            @php($deadline = $amendment->nextDeadline())
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $amendment->reference }}</div>
                                    <small class="text-secondary">{{ $amendment->fiscal_year }} · {{ $amendment->governmentSphereLabel() }}</small>
                                </td>
                                <td class="object-text">{{ $amendment->object }}</td>
                                <td>{{ $amendment->author_name }}</td>
                                <td class="text-nowrap">R$ {{ number_format($amendment->expected_amount, 2, ',', '.') }}</td>
                                <td class="text-nowrap">
                                    @if ($deadline)
                                        <span class="{{ $amendment->hasOverdueDeadline() ? 'deadline-overdue' : ($amendment->hasUpcomingDeadline() ? 'deadline-upcoming' : '') }}">
                                            {{ $deadline['date']->format('d/m/Y') }}
                                        </span>
                                        <div class="small text-secondary">{{ $deadline['label'] }}</div>
                                    @else
                                        <span class="text-secondary">Não informado</span>
                                    @endif
                                </td>
                                <td><x-amendment-status-badge :status="$amendment->status" :label="$amendment->statusLabel()" /></td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('emendas.show', $amendment) }}">Ver</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    <div class="mt-3">{{ $amendments->links() }}</div>
@endsection
