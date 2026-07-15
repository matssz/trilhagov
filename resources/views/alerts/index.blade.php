@extends('layouts.app')

@section('title', 'Central de Integridade - TrilhaGov')

@section('content')
    <div class="page-heading d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3">
        <div>
            <span class="eyebrow">{{ $municipality->name }} · {{ $municipality->state }}</span>
            <h1>Central de Integridade</h1>
            <p>Prazos, documentos obrigatórios e divergências que exigem ação.</p>
        </div>
        @if (auth()->user()->canEditMunicipality($municipality->id))
            <form method="POST" action="{{ route('alerts.process') }}">
                @csrf
                <button class="btn btn-outline-primary" type="submit">
                    <i data-lucide="refresh-cw" aria-hidden="true"></i>
                    Verificar e notificar
                </button>
            </form>
        @endif
    </div>

    <section class="metric-grid mb-4" aria-label="Resumo de alertas">
        <div class="metric-card">
            <span class="metric-icon"><i data-lucide="shield-alert" aria-hidden="true"></i></span>
            <div class="metric-label">Pendências abertas</div>
            <div class="metric-value">{{ $openCount }}</div>
        </div>
        <div class="metric-card {{ $criticalCount > 0 ? 'border-danger' : '' }}">
            <span class="metric-icon"><i data-lucide="triangle-alert" aria-hidden="true"></i></span>
            <div class="metric-label">Críticas</div>
            <div class="metric-value {{ $criticalCount > 0 ? 'text-danger' : '' }}">{{ $criticalCount }}</div>
        </div>
        <div class="metric-card">
            <span class="metric-icon"><i data-lucide="calendar-clock" aria-hidden="true"></i></span>
            <div class="metric-label">Prazos</div>
            <div class="metric-value">{{ $deadlineCount }}</div>
        </div>
        <div class="metric-card">
            <span class="metric-icon"><i data-lucide="file-warning" aria-hidden="true"></i></span>
            <div class="metric-label">Documentos</div>
            <div class="metric-value">{{ $documentCount }}</div>
        </div>
    </section>

    <section class="content-panel mb-4">
        <div class="content-panel-header">
            <form class="alert-filters" method="GET" action="{{ route('alerts.index') }}">
                <label>
                    <span>Situação</span>
                    <select class="form-select" name="status">
                        <option value="open" @selected($statusFilter === 'open')>Abertas</option>
                        <option value="resolved" @selected($statusFilter === 'resolved')>Resolvidas</option>
                    </select>
                </label>
                <label>
                    <span>Categoria</span>
                    <select class="form-select" name="category">
                        <option value="">Todas</option>
                        <option value="deadline" @selected(request('category') === 'deadline')>Prazo</option>
                        <option value="document" @selected(request('category') === 'document')>Documento</option>
                        <option value="consistency" @selected(request('category') === 'consistency')>Consistência</option>
                    </select>
                </label>
                <label>
                    <span>Gravidade</span>
                    <select class="form-select" name="severity">
                        <option value="">Todas</option>
                        <option value="critical" @selected(request('severity') === 'critical')>Crítico</option>
                        <option value="warning" @selected(request('severity') === 'warning')>Atenção</option>
                        <option value="info" @selected(request('severity') === 'info')>Informativo</option>
                    </select>
                </label>
                <button class="btn btn-primary" type="submit"><i data-lucide="list-filter" aria-hidden="true"></i>Filtrar</button>
            </form>
        </div>
        <div class="alert-list">
            @forelse ($alerts as $alert)
                <article class="integrity-alert integrity-alert-{{ $alert->severity }}">
                    <span class="alert-severity-icon" aria-hidden="true">
                        <i data-lucide="{{ $alert->category === 'deadline' ? 'calendar-clock' : ($alert->category === 'document' ? 'file-warning' : 'shield-alert') }}"></i>
                    </span>
                    <div class="integrity-alert-copy">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="severity-badge severity-{{ $alert->severity }}">{{ $alert->severityLabel() }}</span>
                            <span class="alert-category">{{ $alert->categoryLabel() }}</span>
                            @if ($alert->status === 'resolved')
                                <span class="badge text-bg-success">Resolvido</span>
                            @endif
                        </div>
                        <h2>{{ $alert->title }}</h2>
                        <p>{{ $alert->message }}</p>
                        <small>
                            {{ $alert->amendment->reference }}
                            @if ($alert->due_at)
                                · {{ $alert->due_at->format('d/m/Y') }}
                            @endif
                        </small>
                    </div>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('emendas.show', $alert->amendment) }}">Ver emenda</a>
                </article>
            @empty
                <div class="empty-state py-5">
                    <i data-lucide="circle-check" aria-hidden="true"></i>
                    Nenhuma pendência encontrada para estes filtros.
                </div>
            @endforelse
        </div>
        @if ($alerts->hasPages())
            <div class="content-panel-body border-top">{{ $alerts->links() }}</div>
        @endif
    </section>

    @if ($canManage)
        <section class="content-panel">
            <div class="content-panel-header">
                <h2 class="h5 mb-0">Regras municipais de prazo</h2>
            </div>
            <div class="content-panel-body">
                <form class="row g-3 align-items-end" method="POST" action="{{ route('alerts.settings.update') }}">
                    @csrf
                    @method('PATCH')
                    <div class="col-md-3">
                        <label class="form-label" for="deadline_warning_days">Primeiro aviso</label>
                        <div class="input-group"><input class="form-control" id="deadline_warning_days" name="deadline_warning_days" type="number" min="7" max="90" value="{{ old('deadline_warning_days', $settings->deadline_warning_days) }}" required><span class="input-group-text">dias</span></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="deadline_critical_days">Alerta crítico</label>
                        <div class="input-group"><input class="form-control" id="deadline_critical_days" name="deadline_critical_days" type="number" min="1" value="{{ old('deadline_critical_days', $settings->deadline_critical_days) }}" required><span class="input-group-text">dias</span></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="overdue_repeat_days">Repetir se vencido</label>
                        <div class="input-group"><input class="form-control" id="overdue_repeat_days" name="overdue_repeat_days" type="number" min="1" max="30" value="{{ old('overdue_repeat_days', $settings->overdue_repeat_days) }}" required><span class="input-group-text">dias</span></div>
                    </div>
                    <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Salvar regras</button></div>
                </form>
            </div>
        </section>
    @endif
@endsection
