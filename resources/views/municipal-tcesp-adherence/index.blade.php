@extends('layouts.app')

@section('title', 'Aderência TCESP | TrilhaGov')

@section('content')
    <div class="tcesp-adherence-heading">
        <div>
            <p class="page-kicker mb-2">Manual municipal TCESP</p>
            <h1>Aderência Municipal</h1>
            <p>{{ $municipality->name }} / {{ $municipality->state }} · {{ $frameworkVersion }}</p>
        </div>
        <form class="tcesp-year-filter" method="GET" action="{{ route('municipal-tcesp-adherence.index') }}">
            <label>
                <span>Exercício</span>
                <select class="form-select" name="ano" onchange="this.form.submit()">
                    @foreach ($years as $availableYear)
                        <option value="{{ $availableYear }}" @selected($year === $availableYear)>{{ $availableYear }}</option>
                    @endforeach
                </select>
            </label>
            <a class="btn btn-outline-primary" href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">
                <i data-lucide="external-link" aria-hidden="true"></i>Fonte oficial
            </a>
        </form>
    </div>

    <section class="tcesp-readiness-hero" aria-label="Resumo de aderência">
        <div class="tcesp-readiness-score">
            <div class="compliance-score-ring" style="--score: {{ $summary['score'] }}">
                <strong>{{ $summary['score'] }}%</strong>
                <span>prontidão</span>
            </div>
            <div>
                <span class="tcesp-state {{ $summary['normative_ready'] && $summary['portfolio_ready'] ? 'is-ready' : 'is-preparing' }}">
                    {{ $summary['status_label'] }}
                </span>
                <h2>{{ $summary['normative_ready'] ? 'Norma local rastreável' : 'Norma local exige atenção' }}</h2>
                <p>
                    {{ $summary['amendments_count'] }} emenda(s) municipal(is) no exercício ·
                    {{ $summary['covered_rules'] }} de {{ $summary['rules_total'] }} grupo(s) do manual sem pendência aberta.
                </p>
            </div>
        </div>
        <div class="tcesp-readiness-metrics">
            <article><small>Itens atendidos</small><strong>{{ $summary['compliant'] }}</strong></article>
            <article><small>Pendentes</small><strong>{{ $summary['pending'] }}</strong></article>
            <article><small>Não atendidos</small><strong>{{ $summary['non_compliant'] }}</strong></article>
            <article><small>Críticos abertos</small><strong>{{ $summary['critical_open'] }}</strong></article>
        </div>
    </section>

    <section class="tcesp-guidance-grid">
        <article class="content-panel tcesp-normative-card">
            <div class="content-panel-header">
                <div>
                    <p class="panel-kicker">Liberação normativa</p>
                    <h2 class="h5 mb-0">Exercício {{ $year }}</h2>
                </div>
                <span class="tcesp-state {{ $activeProfile ? 'is-ready' : 'is-preparing' }}">{{ $activeProfile ? 'Vigente' : 'Não vigente' }}</span>
            </div>
            <div class="content-panel-body">
                @if ($profile)
                    <p class="mb-3">{{ $profile->statusLabel() }} · {{ $profile->regimeStatusLabel() }} · revisão {{ $profile->version }}</p>
                    <div class="tcesp-progress">
                        <span style="width: {{ $diagnostic['score'] ?? 0 }}%"></span>
                    </div>
                    <small>{{ $diagnostic['score'] ?? 0 }}% dos requisitos de ativação preenchidos.</small>
                    @if (! empty($diagnostic['blockers']))
                        <ul class="tcesp-check-list mt-3">
                            @foreach (array_slice($diagnostic['blockers'], 0, 5) as $blocker)
                                <li><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $blocker }}</li>
                            @endforeach
                        </ul>
                    @elseif (! empty($diagnostic['warnings']))
                        <ul class="tcesp-check-list mt-3">
                            @foreach (array_slice($diagnostic['warnings'], 0, 4) as $warning)
                                <li><i data-lucide="triangle-alert" aria-hidden="true"></i>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="tcesp-clear mt-3"><i data-lucide="shield-check" aria-hidden="true"></i>Regras locais prontas para liberar a Câmara e a execução municipal.</p>
                    @endif
                @else
                    <p class="mb-3">Nenhuma configuração municipal encontrada para este exercício.</p>
                    <p class="tcesp-clear is-warning"><i data-lucide="circle-alert" aria-hidden="true"></i>Crie e ative a revisão normativa antes de receber indicações da Câmara.</p>
                @endif
            </div>
        </article>

        <article class="content-panel tcesp-actions-card">
            <div class="content-panel-header">
                <div>
                    <p class="panel-kicker">Próximas decisões</p>
                    <h2 class="h5 mb-0">O que fazer agora</h2>
                </div>
            </div>
            <div class="tcesp-action-list">
                @forelse ($nextActions as $action)
                    <div>
                        <span><i data-lucide="{{ $action['icon'] }}" aria-hidden="true"></i></span>
                        <div>
                            <strong>{{ $action['title'] }}</strong>
                            <p>{{ $action['description'] }}</p>
                            @if ($action['route'])
                                <a href="{{ $action['route'] }}">{{ $action['label'] }}</a>
                            @endif
                        </div>
                    </div>
                @empty
                    <div>
                        <span><i data-lucide="shield-check" aria-hidden="true"></i></span>
                        <div>
                            <strong>Nenhuma ação crítica aberta</strong>
                            <p>Mantenha a matriz atualizada quando novas emendas, documentos ou pagamentos forem registrados.</p>
                        </div>
                    </div>
                @endforelse
            </div>
        </article>
    </section>

    <section class="content-panel tcesp-matrix-panel">
        <div class="content-panel-header">
            <div>
                <p class="panel-kicker">Rastreabilidade do manual</p>
                <h2 class="h5 mb-0">Cobertura por obrigação</h2>
            </div>
            <span>{{ $summary['rules_total'] }} itens-base</span>
        </div>

        <div class="tcesp-category-list">
            @foreach ($categories as $categoryCode => $category)
                @php($items = $groupedMatrix->get($categoryCode, collect()))
                <section class="tcesp-category">
                    <header>
                        <span><i data-lucide="{{ $category['icon'] }}" aria-hidden="true"></i></span>
                        <div>
                            <h3>{{ $category['label'] }}</h3>
                            <small>{{ $items->sum('open') }} pendência(s) aberta(s)</small>
                        </div>
                    </header>
                    <div class="tcesp-rule-list">
                        @foreach ($items as $item)
                            <a class="tcesp-rule-row state-{{ $item['state'] }}" href="{{ $amendments->first() ? route('emendas.compliance', $amendments->first()).'#regra-'.$item['code'] : route('emendas.index', ['year' => $year]) }}">
                                <span class="tcesp-rule-code">{{ $item['code'] }}</span>
                                <span class="tcesp-rule-main">
                                    <strong>{{ $item['title'] }}</strong>
                                    <small>{{ $item['source'] }} · {{ $item['critical'] ? 'essencial' : 'complementar' }}</small>
                                </span>
                                <span class="tcesp-rule-counts">
                                    <strong>{{ $item['percentage'] }}%</strong>
                                    <small>{{ $item['compliant'] }}/{{ $item['applicable'] }} atendidos</small>
                                </span>
                                <span class="tcesp-rule-state">{{ match($item['state']) {
                                    'covered' => 'Coberto',
                                    'attention' => 'Atenção',
                                    'pending' => 'Pendente',
                                    default => 'Sem carteira',
                                } }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </section>

    <section class="content-panel mt-4">
        <div class="content-panel-header">
            <div>
                <p class="panel-kicker">Carteira auditável</p>
                <h2 class="h5 mb-0">Emendas municipais do exercício</h2>
            </div>
            <span>{{ $amendments->count() }} registro(s)</span>
        </div>
        @if ($amendments->isEmpty())
            <div class="empty-state">Nenhuma emenda municipal cadastrada para {{ $year }}.</div>
        @else
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Referência</th>
                            <th>Objeto</th>
                            <th>Responsável</th>
                            <th>Situação</th>
                            <th class="text-end">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($amendments->take(12) as $amendment)
                            <tr>
                                <td><strong>{{ $amendment->reference }}</strong></td>
                                <td>{{ $amendment->object }}</td>
                                <td>{{ $amendment->responsibleUser?->name ?? 'Não definido' }}</td>
                                <td><x-amendment-status-badge :status="$amendment->status" :label="$amendment->statusLabel()" /></td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('emendas.compliance', $amendment) }}">Matriz</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <p class="compliance-source-note mt-4">
        Fonte de referência: <a href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">{{ $sourceLabel }}</a>. Esta tela consolida a aderência municipal, mas a validação jurídica, contábil e do Tribunal de Contas continua necessária.
    </p>
@endsection
