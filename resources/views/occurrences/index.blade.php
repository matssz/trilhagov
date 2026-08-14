@extends('layouts.app')

@section('title', 'Central de Ocorrências')

@section('content')
    @php
        $summary = $panel['summary'];
        $occurrences = $panel['occurrences'];
        $levelLabels = [
            'error' => 'Erro',
            'warning' => 'Atenção',
            'critical' => 'Crítico',
            'alert' => 'Alerta',
            'emergency' => 'Emergência',
        ];
    @endphp

    <section class="occurrence-page">
        <div class="page-heading d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
            <div>
                <span class="section-kicker">Suporte operacional</span>
                <h1>Central de Ocorrências</h1>
                <p class="text-muted mb-0">Erros técnicos, falhas recentes e ações sensíveis para acompanhar antes que o usuário fique travado.</p>
            </div>
            <a class="btn btn-outline-primary" href="{{ route('infrastructure-monitor.index') }}">
                <i data-lucide="activity" aria-hidden="true"></i>
                Ver infraestrutura
            </a>
        </div>

        <section class="metric-grid mb-4" aria-label="Resumo de ocorrências">
            <article class="metric-card">
                <span class="metric-icon"><i data-lucide="circle-alert" aria-hidden="true"></i></span>
                <div class="metric-label">Abertas</div>
                <div class="metric-value">{{ $summary['open'] }}</div>
            </article>
            <article class="metric-card">
                <span class="metric-icon"><i data-lucide="radar" aria-hidden="true"></i></span>
                <div class="metric-label">Monitoramento</div>
                <div class="metric-value">{{ $summary['monitoring'] }}</div>
            </article>
            <article class="metric-card {{ $summary['critical'] > 0 ? 'border-danger' : '' }}">
                <span class="metric-icon"><i data-lucide="triangle-alert" aria-hidden="true"></i></span>
                <div class="metric-label">Críticas</div>
                <div class="metric-value {{ $summary['critical'] > 0 ? 'text-danger' : '' }}">{{ $summary['critical'] }}</div>
            </article>
            <article class="metric-card">
                <span class="metric-icon"><i data-lucide="clock-3" aria-hidden="true"></i></span>
                <div class="metric-label">Últimas 24h</div>
                <div class="metric-value">{{ $summary['last_24h'] }}</div>
            </article>
            <article class="metric-card">
                <span class="metric-icon"><i data-lucide="check-check" aria-hidden="true"></i></span>
                <div class="metric-label">Resolvidas</div>
                <div class="metric-value">{{ $summary['resolved'] }}</div>
            </article>
        </section>

        <section class="content-panel mb-4">
            <div class="content-panel-header">
                <form class="occurrence-filters" method="GET" action="{{ route('occurrences.index') }}">
                    <label>
                        <span>Situação</span>
                        <select class="form-select" name="status">
                            <option value="">Abertas e monitoramento</option>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span>Origem</span>
                        <select class="form-select" name="source">
                            <option value="">Todas</option>
                            <option value="exception" @selected(request('source') === 'exception')>Exceção</option>
                            <option value="log" @selected(request('source') === 'log')>Log</option>
                        </select>
                    </label>
                    <label>
                        <span>Nível</span>
                        <select class="form-select" name="level">
                            <option value="">Todos</option>
                            @foreach ($levelLabels as $value => $label)
                                <option value="{{ $value }}" @selected(request('level') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="btn btn-primary" type="submit">
                        <i data-lucide="list-filter" aria-hidden="true"></i>
                        Filtrar
                    </button>
                </form>
            </div>

            <div class="occurrence-list">
                @forelse ($occurrences as $occurrence)
                    <article class="occurrence-card occurrence-{{ $occurrence->severity() }}">
                        <span class="occurrence-icon">
                            <i data-lucide="{{ $occurrence->severity() === 'critical' ? 'triangle-alert' : ($occurrence->severity() === 'attention' ? 'shield-alert' : 'bug') }}" aria-hidden="true"></i>
                        </span>
                        <div class="occurrence-main">
                            <div class="occurrence-topline">
                                <span>{{ $occurrence->sourceLabel() }}</span>
                                <span>{{ $levelLabels[$occurrence->level] ?? Str::headline($occurrence->level) }}</span>
                                <span>{{ $occurrence->statusLabel() }}</span>
                                <span>{{ $occurrence->occurrence_count }} ocorrência(s)</span>
                            </div>
                            <h2>{{ $occurrence->title }}</h2>
                            <p>{{ $occurrence->message }}</p>
                            <dl class="occurrence-facts">
                                <div><dt>Rota</dt><dd>{{ $occurrence->route_name ?: 'Não identificada' }}</dd></div>
                                <div><dt>Método</dt><dd>{{ $occurrence->method ?: 'N/D' }}</dd></div>
                                <div><dt>Última vez</dt><dd>{{ $occurrence->last_seen_at?->format('d/m/Y H:i') ?: 'N/D' }}</dd></div>
                                <div><dt>Usuário</dt><dd>{{ $occurrence->user?->name ?: 'Não identificado' }}</dd></div>
                            </dl>
                            @if ($occurrence->resolution_notes)
                                <div class="occurrence-note">
                                    <strong>Tratativa registrada</strong>
                                    <p>{{ $occurrence->resolution_notes }}</p>
                                </div>
                            @endif
                        </div>
                        <form class="occurrence-action" method="POST" action="{{ route('occurrences.update', $occurrence) }}">
                            @csrf
                            @method('PATCH')
                            <label>
                                <span>Status</span>
                                <select class="form-select form-select-sm" name="status" required>
                                    @foreach ($statuses as $value => $label)
                                        <option value="{{ $value }}" @selected($occurrence->status === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>
                                <span>Nota de suporte</span>
                                <textarea class="form-control form-control-sm" name="resolution_notes" rows="3" maxlength="4000" placeholder="Causa provável, correção aplicada ou próximo passo...">{{ $occurrence->resolution_notes }}</textarea>
                            </label>
                            <button class="btn btn-primary btn-sm" type="submit">
                                <i data-lucide="save" aria-hidden="true"></i>
                                Salvar tratativa
                            </button>
                        </form>
                    </article>
                @empty
                    <div class="empty-state py-5">
                        <i data-lucide="circle-check" aria-hidden="true"></i>
                        <strong>Nenhuma ocorrência encontrada</strong>
                        <p>Não há erro aberto para os filtros atuais. Continue acompanhando após novos deploys e testes de usuário.</p>
                    </div>
                @endforelse
            </div>

            @if ($occurrences->hasPages())
                <div class="content-panel-body border-top">{{ $occurrences->links() }}</div>
            @endif
        </section>

        <section class="content-panel">
            <div class="content-panel-header">
                <div>
                    <p class="panel-kicker mb-1">Auditoria</p>
                    <h2 class="h5 mb-0">Ações sensíveis recentes</h2>
                </div>
            </div>
            <div class="occurrence-audit-list">
                @forelse ($panel['sensitiveActions'] as $action)
                    <article>
                        <i data-lucide="history" aria-hidden="true"></i>
                        <div>
                            <strong>{{ $action->actionLabel() }}</strong>
                            <p>{{ $action->actor_name ?: 'Usuário não identificado' }} em {{ $action->created_at->format('d/m/Y H:i') }}</p>
                        </div>
                        <span>{{ $action->ip_address ?: 'IP não registrado' }}</span>
                    </article>
                @empty
                    <div class="empty-state compact">
                        <i data-lucide="history" aria-hidden="true"></i>
                        <strong>Nenhuma ação sensível recente</strong>
                        <p>Alterações de perfil, módulos, normas, importações e documentos aparecerão aqui.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </section>
@endsection
