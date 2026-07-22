@extends('layouts.app')

@section('title', 'Saúde e LC 141 | TrilhaGov')

@section('content')
    @php
        $money = function ($value) {
            return 'R$ '.number_format((float) $value, 2, ',', '.');
        };
    @endphp
    <div class="page-heading health-heading">
        <div><span class="page-kicker">Controle municipal</span><h1>Saúde e LC 141</h1><p>Reserva das emendas e enquadramento técnico das despesas em ASPS.</p></div>
        <div class="health-heading-actions"><a class="btn btn-outline-secondary" href="{{ route('specialized-reports.index') }}"><i data-lucide="file-chart-column" aria-hidden="true"></i>Relatórios</a><a class="btn btn-primary" href="{{ route('municipal-rules.index') }}"><i data-lucide="landmark" aria-hidden="true"></i>Regra local</a></div>
    </div>

    <div class="health-context-band">
        <div><span>Exercício</span><strong>{{ $year }}</strong></div>
        <div><span>Regra municipal</span><strong>{{ $profile ? 'Versão '.$profile->version : 'Não configurada' }}</strong></div>
        <div><span>Reserva local</span><strong>{{ $profile?->health_reserve_percentage !== null ? number_format((float) $profile->health_reserve_percentage, 2, ',', '.').'%' : 'A confirmar' }}</strong></div>
        <div><span>Forma de apuração</span><strong>{{ $profile?->health_reserve_method ? App\Models\MunicipalRegulatoryProfile::healthReserveMethods()[$profile->health_reserve_method] : 'A confirmar' }}</strong></div>
    </div>

    <div class="metric-strip health-metrics">
        <article><span>Destinado à saúde</span><strong>{{ $money($metrics['designated_amount']) }}</strong><small>Conforme planos de trabalho</small></article>
        <article><span>Reserva mínima local</span><strong>{{ $metrics['required_amount'] === null ? 'Não calculada' : $money($metrics['required_amount']) }}</strong><small>Sobre emendas individuais municipais</small></article>
        <article><span>Com parecer ASPS favorável</span><strong>{{ $money($metrics['eligible_amount']) }}</strong><small>{{ $money($metrics['eligible_paid']) }} pago</small></article>
        <article class="{{ $metrics['pending'] + $metrics['ineligible'] > 0 ? 'metric-attention' : '' }}"><span>Pendências de enquadramento</span><strong>{{ $metrics['pending'] + $metrics['ineligible'] }}</strong><small>{{ $metrics['pending'] }} sem parecer · {{ $metrics['ineligible'] }} não computável</small></article>
    </div>

    <section class="health-method-band">
        <div><i data-lucide="heart-pulse" aria-hidden="true"></i><span><strong>Destinação à saúde</strong><small>Controle da reserva prevista na norma municipal</small></span></div>
        <i data-lucide="arrow-right" aria-hidden="true"></i>
        <div><i data-lucide="scan-search" aria-hidden="true"></i><span><strong>Enquadramento ASPS</strong><small>Critérios dos arts. 2º a 4º da LC 141</small></span></div>
        <i data-lucide="arrow-right" aria-hidden="true"></i>
        <div><i data-lucide="shield-check" aria-hidden="true"></i><span><strong>Parecer municipal</strong><small>Conclusão versionada e preservada</small></span></div>
    </section>

    <section class="content-panel health-list-panel">
        <div class="content-panel-header"><div><span class="page-kicker">Carteira da saúde</span><h2 class="h5 mb-0">Emendas para análise</h2></div><span class="record-count">{{ $amendments->total() }}</span></div>
        @if ($amendments->isEmpty())
            <div class="empty-state"><i data-lucide="heart-pulse" aria-hidden="true"></i><h3>Nenhuma emenda de saúde</h3><p>As emendas aparecem após a classificação no plano de trabalho.</p></div>
        @else
            <div class="table-responsive"><table class="table app-table align-middle mb-0"><thead><tr><th>Emenda</th><th>Valor</th><th>Plano</th><th>Parecer ASPS vigente</th><th>Versão em andamento</th><th class="text-end">Análise</th></tr></thead><tbody>
                @foreach ($amendments as $amendment)
                    @php
                        $current = $amendment->healthAspsAssessments->first();
                        $issued = $amendment->healthAspsAssessments->where('status', 'issued')->first();
                    @endphp
                    <tr>
                        <td><strong>{{ $amendment->reference }}</strong><small class="table-subtitle">{{ $amendment->object }}</small><small class="table-subtitle">{{ $amendment->author_name }} · {{ $amendment->responsible_department }}</small></td>
                        <td><strong>{{ $money($amendment->expected_amount) }}</strong><small class="table-subtitle">{{ $money($amendment->paid_amount ?? 0) }} pago</small></td>
                        <td><span class="status-pill {{ $amendment->municipalWorkPlan?->health_reserve_verified ? 'is-success' : 'is-warning' }}">{{ $amendment->municipalWorkPlan?->health_reserve_verified ? 'Reserva conferida' : 'Conferir reserva' }}</span></td>
                        <td>@if($issued)<span class="status-pill {{ $issued->conclusion === 'eligible' ? 'is-success' : 'is-danger' }}">{{ $issued->conclusionLabel() }}</span><small class="table-subtitle">{{ $issued->code() }}</small>@else<span class="status-pill is-warning">Sem parecer emitido</span>@endif</td>
                        <td>{{ $current ? $current->statusLabel() : 'Não iniciado' }}@if($current && $current->status !== 'issued')<small class="table-subtitle">{{ $current->code() }}</small>@endif</td>
                        <td class="text-end"><a class="icon-button" href="{{ route('health-asps.show', $amendment) }}" title="Abrir enquadramento" aria-label="Analisar {{ $amendment->reference }}"><i data-lucide="arrow-right" aria-hidden="true"></i></a></td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <div class="panel-pagination">{{ $amendments->links() }}</div>
        @endif
    </section>

    <div class="report-notice"><i data-lucide="info" aria-hidden="true"></i><div><strong>Escopo do controle</strong><p>O parecer classifica a despesa da emenda. O percentual mínimo municipal depende da base completa de receitas e despesas declarada no SIOPS e demonstrada no RREO.</p></div></div>
@endsection
