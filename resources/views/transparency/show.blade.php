@extends('layouts.app')

@section('title', 'Transparência de emendas | '.$municipality->name)

@section('content')
    @php
        $summary = $analytics['summary'];
        $query = array_filter($filters, fn ($value) => filled($value));
        $routeMunicipality = $municipality->transparency_slug;
        $lastUpdated = $analytics['amendments']->max('updated_at') ?? $municipality->transparency_updated_at;
    @endphp

    <header class="public-transparency-heading">
        <div class="public-transparency-title">
            <span class="transparency-symbol"><i data-lucide="landmark" aria-hidden="true"></i></span>
            <div>
                <p class="page-kicker mb-2">Portal de transparência</p>
                <h1>Emendas parlamentares</h1>
                <p>{{ $municipality->name }} / {{ $municipality->state }} · Código IBGE {{ $municipality->ibge_code }}</p>
            </div>
        </div>
        <div class="public-update"><span>Dados atualizados</span><strong>{{ $lastUpdated?->format('d/m/Y \a\s H:i') ?? 'Sem movimentação' }}</strong></div>
    </header>

    <form class="analytics-filters public-filters" method="GET" action="{{ route('transparency.show', ['municipality' => $routeMunicipality]) }}">
        <label><span>Exercício</span><select class="form-select" name="year"><option value="">Todos</option>@foreach ($options['years'] as $year)<option value="{{ $year }}" @selected(($filters['year'] ?? '') == $year)>{{ $year }}</option>@endforeach</select></label>
        <label><span>Esfera</span><select class="form-select" name="sphere"><option value="">Todas</option>@foreach ($spheres as $value => $label)<option value="{{ $value }}" @selected(($filters['sphere'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label><span>Situação</span><select class="form-select" name="status"><option value="">Todas</option>@foreach ($statuses as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="analytics-filter-wide"><span>Órgão responsável</span><select class="form-select" name="department"><option value="">Todos</option>@foreach ($options['departments'] as $department)<option value="{{ $department }}" @selected(($filters['department'] ?? '') === $department)>{{ $department }}</option>@endforeach</select></label>
        <div class="analytics-filter-actions">
            @if ($query !== [])<a class="icon-button" href="{{ route('transparency.show', ['municipality' => $routeMunicipality]) }}" title="Limpar filtros" aria-label="Limpar filtros"><i data-lucide="filter-x" aria-hidden="true"></i></a>@endif
            <button class="btn btn-primary" type="submit"><i data-lucide="list-filter" aria-hidden="true"></i>Aplicar</button>
        </div>
    </form>

    <section class="public-metric-grid" aria-label="Resumo das emendas">
        <article><span>Emendas monitoradas</span><strong>{{ $summary['count'] }}</strong><small>No recorte selecionado</small></article>
        <article><span>Valor previsto</span><strong>R$ {{ number_format($summary['expected'], 2, ',', '.') }}</strong><small>Recursos identificados</small></article>
        <article><span>Valor recebido</span><strong>R$ {{ number_format($summary['received'], 2, ',', '.') }}</strong><small>{{ $summary['receipt_rate'] }}% do previsto</small></article>
        <article><span>Valor pago</span><strong>R$ {{ number_format($summary['paid'], 2, ',', '.') }}</strong><small>{{ $summary['payment_rate'] }}% do recebido</small></article>
    </section>

    <div class="analytics-grid public-charts-grid">
        <section class="content-panel analytics-chart-panel">
            <div class="content-panel-header"><p class="panel-kicker">Execução financeira</p><h2 class="h5 mb-0">Evolução do recurso</h2></div>
            <div class="chart-frame chart-frame-wide"><canvas data-analytics-chart="financial" data-labels='@json($analytics['charts']['financial']['labels'])' data-values='@json($analytics['charts']['financial']['values'])'></canvas></div>
        </section>
        <section class="content-panel analytics-chart-panel">
            <div class="content-panel-header"><p class="panel-kicker">Andamento</p><h2 class="h5 mb-0">Situação das emendas</h2></div>
            <div class="chart-frame chart-frame-status"><canvas data-analytics-chart="status" data-labels='@json($analytics['charts']['statuses']['labels'])' data-values='@json($analytics['charts']['statuses']['values'])'></canvas></div>
        </section>
    </div>

    <section class="content-panel public-amendments-panel">
        <div class="content-panel-header public-table-heading">
            <div><p class="panel-kicker">Dados abertos</p><h2 class="h5 mb-0">Emendas do município</h2></div>
            <a class="btn btn-outline-primary" href="{{ route('transparency.export', ['municipality' => $routeMunicipality, ...$query]) }}"><i data-lucide="sheet" aria-hidden="true"></i>Baixar CSV</a>
        </div>
        <div class="table-responsive">
            <table class="table public-amendments-table mb-0">
                <thead><tr><th>Emenda</th><th>Objeto</th><th>Situação</th><th>Execução</th><th class="text-end">Valores</th></tr></thead>
                <tbody>
                    @forelse ($analytics['amendments'] as $amendment)
                        <tr>
                            <td data-label="Emenda"><strong>{{ $amendment->reference }}</strong><small>{{ $amendment->fiscal_year }} · {{ $amendment->governmentSphereLabel() }}</small><small>{{ $amendment->author_name }}</small></td>
                            <td data-label="Objeto"><span class="public-object">{{ $amendment->object }}</span><small>{{ $amendment->responsible_department }}</small></td>
                            <td data-label="Situação"><x-amendment-status-badge :status="$amendment->status" :label="$amendment->statusLabel()" /></td>
                            <td data-label="Execução"><div class="public-progress"><span><strong>{{ $amendment->physicalExecutionPercentage() }}%</strong> físico</span><div class="progress"><div class="progress-bar" style="width: {{ $amendment->physicalExecutionPercentage() }}%"></div></div></div></td>
                            <td class="text-end" data-label="Valores"><strong>R$ {{ number_format((float) $amendment->received_amount, 2, ',', '.') }}</strong><small>de R$ {{ number_format((float) $amendment->expected_amount, 2, ',', '.') }}</small><small>R$ {{ number_format($paidAmounts[$amendment->id], 2, ',', '.') }} pagos</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="empty-state">Nenhuma emenda encontrada para os filtros selecionados.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <footer class="public-transparency-footer">
        <span><i data-lucide="shield-check" aria-hidden="true"></i>Dados institucionais publicados pelo município</span>
        <span>TrilhaGov · Gestão e transparência de emendas</span>
    </footer>
@endsection
