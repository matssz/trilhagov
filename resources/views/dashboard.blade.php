@extends('layouts.app')

@section('title', 'Inteligência gerencial | TrilhaGov')

@section('content')
    @php
        $summary = $analytics['summary'];
        $query = array_filter($filters, fn ($value) => filled($value));
        $publicUrl = $municipality->transparency_slug
            ? route('transparency.show', ['municipality' => $municipality->transparency_slug])
            : null;
    @endphp

    <header class="analytics-heading">
        <div>
            <p class="page-kicker mb-2">{{ $municipality->name }} / {{ $municipality->state }}</p>
            <h1>Inteligência gerencial</h1>
            <p>Visão consolidada da captação, execução e conformidade das emendas.</p>
        </div>
        <div class="analytics-heading-actions">
            <a class="btn btn-outline-primary" href="{{ route('reports.export', $query) }}">
                <i data-lucide="sheet" aria-hidden="true"></i>Exportar CSV
            </a>
            @if ($canEdit)
                <a class="btn btn-primary" href="{{ route('emendas.create') }}"><i data-lucide="plus" aria-hidden="true"></i>Nova emenda</a>
            @endif
        </div>
    </header>

    <form class="analytics-filters" method="GET" action="{{ route('dashboard') }}">
        <label>
            <span>Exercício</span>
            <select class="form-select" name="year">
                <option value="">Todos</option>
                @foreach ($years as $year)
                    <option value="{{ $year }}" @selected(($filters['year'] ?? '') == $year)>{{ $year }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Esfera</span>
            <select class="form-select" name="sphere">
                <option value="">Todas</option>
                @foreach ($spheres as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['sphere'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Situação</span>
            <select class="form-select" name="status">
                <option value="">Todas</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="analytics-filter-wide">
            <span>Órgão responsável</span>
            <select class="form-select" name="department">
                <option value="">Todos</option>
                @foreach ($departments as $department)
                    <option value="{{ $department }}" @selected(($filters['department'] ?? '') === $department)>{{ $department }}</option>
                @endforeach
            </select>
        </label>
        <div class="analytics-filter-actions">
            @if ($query !== [])
                <a class="icon-button" href="{{ route('dashboard') }}" title="Limpar filtros" aria-label="Limpar filtros">
                    <i data-lucide="filter-x" aria-hidden="true"></i>
                </a>
            @endif
            <button class="btn btn-primary" type="submit"><i data-lucide="list-filter" aria-hidden="true"></i>Aplicar</button>
        </div>
    </form>

    <section class="analytics-metrics" aria-label="Indicadores financeiros e operacionais">
        <article class="analytics-metric">
            <span class="analytics-metric-icon metric-blue"><i data-lucide="landmark" aria-hidden="true"></i></span>
            <div>
                <span>Valor previsto</span>
                <strong>R$ {{ number_format($summary['expected'], 2, ',', '.') }}</strong>
                <small>{{ $summary['count'] }} emenda(s) no recorte</small>
            </div>
        </article>
        <article class="analytics-metric">
            <span class="analytics-metric-icon metric-green"><i data-lucide="circle-dollar-sign" aria-hidden="true"></i></span>
            <div>
                <span>Recursos recebidos</span>
                <strong>R$ {{ number_format($summary['received'], 2, ',', '.') }}</strong>
                <small>{{ $summary['receipt_rate'] }}% do valor previsto</small>
            </div>
        </article>
        <article class="analytics-metric">
            <span class="analytics-metric-icon metric-gold"><i data-lucide="receipt-text" aria-hidden="true"></i></span>
            <div>
                <span>Pagamentos realizados</span>
                <strong>R$ {{ number_format($summary['paid'], 2, ',', '.') }}</strong>
                <small>{{ $summary['payment_rate'] }}% do recurso recebido</small>
            </div>
        </article>
        <article class="analytics-metric {{ $summary['overdue'] + $summary['high_risk'] > 0 ? 'metric-attention' : '' }}">
            <span class="analytics-metric-icon metric-red"><i data-lucide="shield-alert" aria-hidden="true"></i></span>
            <div>
                <span>Atenção gerencial</span>
                <strong>{{ $summary['overdue'] + $summary['high_risk'] }}</strong>
                <small>{{ $summary['overdue'] }} prazos vencidos · {{ $summary['high_risk'] }} riscos elevados</small>
            </div>
        </article>
    </section>

    <section class="analytics-pulse" aria-label="Pulso de execução">
        <div class="pulse-main">
            <span>Execução física média</span>
            <strong>{{ $summary['physical_execution'] }}%</strong>
            <div class="progress" role="progressbar" aria-label="Execução física" aria-valuenow="{{ $summary['physical_execution'] }}" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar" style="width: {{ $summary['physical_execution'] }}%"></div>
            </div>
        </div>
        <div class="pulse-stat">
            <span>Saldo financeiro disponível</span>
            <strong>R$ {{ number_format($summary['available'], 2, ',', '.') }}</strong>
        </div>
        <div class="pulse-stat">
            <span>Qualidade cadastral</span>
            <strong>{{ $summary['data_quality'] }}%</strong>
        </div>
        <div class="pulse-stat">
            <span>Contas aprovadas</span>
            <strong>{{ $summary['accountability_approved'] }}</strong>
        </div>
    </section>

    <div class="analytics-grid analytics-grid-primary">
        <section class="content-panel analytics-chart-panel">
            <div class="content-panel-header">
                <div>
                    <p class="panel-kicker">Fluxo do recurso</p>
                    <h2 class="h5 mb-0">Conversão financeira</h2>
                </div>
                <span class="analytics-legend-note">Valores do recorte atual</span>
            </div>
            <div class="chart-frame chart-frame-wide">
                <canvas data-analytics-chart="financial"
                    data-labels='@json($analytics['charts']['financial']['labels'])'
                    data-values='@json($analytics['charts']['financial']['values'])'></canvas>
            </div>
        </section>

        <section class="content-panel analytics-chart-panel">
            <div class="content-panel-header">
                <p class="panel-kicker">Exposição</p>
                <h2 class="h5 mb-0">Distribuição de risco</h2>
            </div>
            <div class="chart-frame chart-frame-donut">
                <canvas data-analytics-chart="risk"
                    data-labels='@json($analytics['charts']['risks']['labels'])'
                    data-values='@json($analytics['charts']['risks']['values'])'></canvas>
                @if ($summary['count'] > 0)
                    <div class="chart-center-label"><strong>{{ $summary['count'] }}</strong><span>emendas</span></div>
                @endif
            </div>
        </section>
    </div>

    <div class="analytics-grid analytics-grid-secondary">
        <section class="content-panel analytics-chart-panel">
            <div class="content-panel-header">
                <p class="panel-kicker">Ciclo operacional</p>
                <h2 class="h5 mb-0">Emendas por situação</h2>
            </div>
            <div class="chart-frame chart-frame-status">
                <canvas data-analytics-chart="status"
                    data-labels='@json($analytics['charts']['statuses']['labels'])'
                    data-values='@json($analytics['charts']['statuses']['values'])'></canvas>
            </div>
        </section>

        <section class="content-panel insights-panel">
            <div class="content-panel-header">
                <p class="panel-kicker">Leitura automática</p>
                <h2 class="h5 mb-0">Diagnósticos do recorte</h2>
            </div>
            <div class="insight-list">
                @foreach ($analytics['insights'] as $insight)
                    <article class="insight-row insight-{{ $insight['tone'] }}">
                        <span><i data-lucide="{{ $insight['tone'] === 'danger' ? 'triangle-alert' : ($insight['tone'] === 'success' ? 'circle-check' : 'lightbulb') }}" aria-hidden="true"></i></span>
                        <div><strong>{{ $insight['title'] }}</strong><p>{{ $insight['message'] }}</p></div>
                    </article>
                @endforeach
            </div>
        </section>
    </div>

    <div class="analytics-grid analytics-grid-tertiary">
        <section class="content-panel analytics-chart-panel">
            <div class="content-panel-header">
                <p class="panel-kicker">Alocação</p>
                <h2 class="h5 mb-0">Valor previsto por órgão</h2>
            </div>
            <div class="chart-frame chart-frame-ranking">
                <canvas data-analytics-chart="departments"
                    data-labels='@json($analytics['charts']['departments']['labels'])'
                    data-values='@json($analytics['charts']['departments']['values'])'></canvas>
            </div>
        </section>
        <section class="content-panel analytics-chart-panel">
            <div class="content-panel-header">
                <p class="panel-kicker">Origem</p>
                <h2 class="h5 mb-0">Valor previsto por autor</h2>
            </div>
            <div class="chart-frame chart-frame-ranking">
                <canvas data-analytics-chart="authors"
                    data-labels='@json($analytics['charts']['authors']['labels'])'
                    data-values='@json($analytics['charts']['authors']['values'])'></canvas>
            </div>
        </section>
    </div>

    <div class="analytics-grid analytics-grid-operations">
        <section class="content-panel">
            <div class="content-panel-header d-flex justify-content-between align-items-center gap-3">
                <div><p class="panel-kicker">Prioridade</p><h2 class="h5 mb-0">Fila de atenção</h2></div>
                <a href="{{ route('alerts.index') }}">Abrir integridade</a>
            </div>
            <div class="attention-list">
                @forelse ($analytics['attention'] as $amendment)
                    <a class="attention-row" href="{{ route('emendas.show', $amendment) }}">
                        <span class="attention-reference">{{ $amendment->reference }}</span>
                        <span class="attention-object">{{ $amendment->object }}</span>
                        <span class="attention-flags">
                            @if ($amendment->hasOverdueDeadline())<span class="attention-flag danger">Prazo vencido</span>@endif
                            @if ($amendment->responsible_user_id === null)<span class="attention-flag warning">Sem responsável</span>@endif
                            <x-risk-badge :level="$amendment->risk_level" :label="$amendment->riskLabel()" :score="$amendment->risk_score" />
                        </span>
                    </a>
                @empty
                    <div class="empty-state">Nenhum ponto crítico no recorte atual.</div>
                @endforelse
            </div>
        </section>

        <section class="content-panel">
            <div class="content-panel-header"><p class="panel-kicker">Agenda</p><h2 class="h5 mb-0">Próximos marcos</h2></div>
            <div class="content-panel-body">
                @forelse ($deadlines as $item)
                    @php
                        $deadline = $item['deadline'];
                        $isOverdue = $deadline['date']->isBefore(today());
                        $isUpcoming = $deadline['date']->betweenIncluded(today(), today()->addDays(30));
                    @endphp
                    <div class="deadline-row">
                        <div><a class="fw-semibold" href="{{ route('emendas.show', $item['amendment']) }}">{{ $item['amendment']->reference }}</a><div class="small text-secondary">{{ $deadline['label'] }}</div></div>
                        <div class="deadline-date {{ $isOverdue ? 'deadline-overdue' : ($isUpcoming ? 'deadline-upcoming' : '') }}">{{ $deadline['date']->format('d/m/Y') }}@if ($isOverdue)<div class="small">Vencido</div>@endif</div>
                    </div>
                @empty
                    <div class="empty-state">Nenhum prazo registrado.</div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="transparency-control">
        <div class="transparency-control-copy">
            <span class="transparency-symbol"><i data-lucide="globe-2" aria-hidden="true"></i></span>
            <div>
                <p class="panel-kicker">Transparência ativa</p>
                <h2>Portal público municipal</h2>
                <p>Publica indicadores e dados institucionais sem expor documentos, usuários internos ou informações de fornecedores.</p>
            </div>
        </div>
        <div class="transparency-control-actions">
            @if ($municipality->transparency_enabled && $publicUrl)
                <span class="publication-status is-live"><i data-lucide="circle-check" aria-hidden="true"></i>Publicado</span>
                <a class="btn btn-outline-primary" href="{{ $publicUrl }}" target="_blank" rel="noopener"><i data-lucide="external-link" aria-hidden="true"></i>Abrir portal</a>
            @else
                <span class="publication-status"><i data-lucide="circle-minus" aria-hidden="true"></i>Não publicado</span>
            @endif
            @if ($isManager)
                <form method="POST" action="{{ route('transparency.settings.update') }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="_submission_token" value="{{ $transparencyToken }}">
                    <input type="hidden" name="transparency_enabled" value="{{ $municipality->transparency_enabled ? 0 : 1 }}">
                    <button class="btn {{ $municipality->transparency_enabled ? 'btn-outline-danger' : 'btn-primary' }}" type="submit">
                        <i data-lucide="{{ $municipality->transparency_enabled ? 'eye-off' : 'globe-2' }}" aria-hidden="true"></i>
                        {{ $municipality->transparency_enabled ? 'Retirar do ar' : 'Publicar portal' }}
                    </button>
                </form>
            @endif
        </div>
    </section>
@endsection
