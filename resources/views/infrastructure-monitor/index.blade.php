@extends('layouts.app')

@section('title', 'Monitoramento')

@section('content')
    @php
        $statusLabels = ['ok' => 'Operacional', 'attention' => 'Atenção', 'critical' => 'Crítico'];
        $statusIcons = ['ok' => 'circle-check', 'attention' => 'shield-alert', 'critical' => 'triangle-alert'];
        $overall = $snapshot['status'];
    @endphp

    <section class="infra-page">
        <div class="page-heading mb-4">
            <div>
                <span class="section-kicker">Infraestrutura</span>
                <h1>Monitoramento do sistema</h1>
                <p class="text-muted mb-0">Saúde técnica do TrilhaGov: banco, storage, cache, filas, agendador, logs e ambiente.</p>
            </div>
            <form method="GET" action="{{ route('infrastructure-monitor.index') }}">
                <button class="btn btn-outline-primary" type="submit">
                    <i data-lucide="refresh-cw" aria-hidden="true"></i>
                    Atualizar leitura
                </button>
            </form>
        </div>

        <section class="infra-hero infra-{{ $overall }}">
            <div>
                <span class="privacy-pill">
                    <i data-lucide="{{ $statusIcons[$overall] }}" aria-hidden="true"></i>
                    {{ $statusLabels[$overall] }}
                </span>
                <h2>{{ $overall === 'ok' ? 'Infraestrutura pronta para operar' : ($overall === 'critical' ? 'Há ponto crítico para corrigir' : 'Há pontos que merecem atenção') }}</h2>
                <p>Leitura gerada em {{ $snapshot['generated_at']->format('d/m/Y H:i:s') }} para {{ $municipality->name }} / {{ $municipality->state }}.</p>
            </div>
            <div class="infra-score">
                <strong>{{ collect($snapshot['checks'])->where('status', 'ok')->count() }}/{{ count($snapshot['checks']) }}</strong>
                <span>checagens OK</span>
            </div>
        </section>

        <section class="metric-grid my-4" aria-label="Resumo técnico">
            @foreach ($snapshot['metrics'] as $label => $value)
                <article class="metric-card">
                    <span class="metric-label">{{ Str::headline($label) }}</span>
                    <strong class="metric-value">{{ $value }}</strong>
                </article>
            @endforeach
        </section>

        <div class="row g-3">
            @foreach ($snapshot['checks'] as $check)
                <div class="col-12 col-xl-6">
                    <article class="infra-check infra-check-{{ $check['status'] }}">
                        <span class="infra-check-icon">
                            <i data-lucide="{{ $statusIcons[$check['status']] }}" aria-hidden="true"></i>
                        </span>
                        <div>
                            <div class="infra-check-title">
                                <h2>{{ $check['title'] }}</h2>
                                <span>{{ $statusLabels[$check['status']] }}</span>
                            </div>
                            <strong>{{ $check['summary'] }}</strong>
                            <p>{{ $check['description'] }}</p>
                            <small>{{ $check['action'] }}</small>
                            @if (! empty($check['meta']))
                                <dl class="infra-meta">
                                    @foreach ($check['meta'] as $metaLabel => $metaValue)
                                        <div>
                                            <dt>{{ Str::headline($metaLabel) }}</dt>
                                            <dd>{{ $metaValue === null ? 'Não disponível' : (is_bool($metaValue) ? ($metaValue ? 'Sim' : 'Não') : $metaValue) }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </div>
                    </article>
                </div>
            @endforeach
        </div>

        <div class="row g-3 mt-1">
            <div class="col-12 col-xl-5">
                <article class="privacy-panel">
                    <div class="privacy-panel-heading">
                        <span>Deploy</span>
                        <strong>Ambiente Render / aplicação</strong>
                    </div>
                    <dl class="infra-definition-list">
                        @foreach ($snapshot['deployment'] as $label => $value)
                            <div>
                                <dt>{{ Str::headline($label) }}</dt>
                                <dd>{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </article>
            </div>
            <div class="col-12 col-xl-7">
                <article class="privacy-panel">
                    <div class="privacy-panel-heading">
                        <span>Logs</span>
                        <strong>Erros recentes no recorte analisado</strong>
                    </div>
                    @if (empty($snapshot['recent_errors']))
                        <div class="empty-state compact">
                            <i data-lucide="circle-check" aria-hidden="true"></i>
                            <strong>Nenhum erro crítico recente encontrado</strong>
                            <p>O recorte final do log não retornou ERROR, CRITICAL, ALERT ou EMERGENCY.</p>
                        </div>
                    @else
                        <div class="infra-error-list">
                            @foreach ($snapshot['recent_errors'] as $error)
                                <div>
                                    <span>{{ $error['level'] }}</span>
                                    <p>{{ $error['message'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </article>
            </div>
        </div>
    </section>
@endsection
