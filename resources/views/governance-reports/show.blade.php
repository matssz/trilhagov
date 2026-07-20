@extends('layouts.app')

@section('title', $report->code().' | TrilhaGov')

@section('content')
    @php($totals = $snapshot['totals'])
    <div class="governance-heading mb-4">
        <div>
            <a class="back-link" href="{{ route('governance-reports.index') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Relatórios mensais</a>
            <p class="page-kicker mt-3 mb-2">{{ $snapshot['municipality']['name'] }}/{{ $snapshot['municipality']['state'] }} · competência {{ $report->periodLabel() }}</p>
            <div class="d-flex flex-wrap align-items-center gap-2"><h1 class="h3 mb-0">{{ $report->code() }}</h1><span class="governance-status status-{{ $report->status }}">{{ $report->statusLabel() }}</span></div>
            <p class="text-secondary mb-0 mt-2">Dados consolidados em {{ $report->data_generated_at->format('d/m/Y H:i') }} · hash {{ substr($report->snapshot_sha256, 0, 12) }}…</p>
        </div>
        <div class="governance-actions">
            <a class="btn btn-outline-primary" href="{{ route('governance-reports.csv', $report) }}"><i data-lucide="file-spreadsheet" aria-hidden="true"></i>CSV</a>
            <a class="btn btn-primary" href="{{ route('governance-reports.pdf', $report) }}"><i data-lucide="file-down" aria-hidden="true"></i>PDF</a>
        </div>
    </div>

    <x-validation-summary />

    @if (!$report->isDraft())
        <div class="governance-seal mb-4"><i data-lucide="badge-check" aria-hidden="true"></i><div><strong>Versão fechada para auditoria</strong><p>Emitida em {{ $report->issued_at->format('d/m/Y H:i') }} por {{ $report->issuer->name }}. O conteúdo e o hash desta fotografia não podem ser alterados.</p></div><code>{{ $report->snapshot_sha256 }}</code></div>

        <section class="dispatch-report-cta mb-4">
            <div><span><i data-lucide="send" aria-hidden="true"></i></span><div><p class="page-kicker mb-1">Fluxo entre órgãos municipais</p><h2 class="h5 mb-1">Protocolo institucional</h2><p>{{ $dispatches->count() }} remessa(s) registrada(s) · {{ $dispatches->where('status', 'acknowledged')->count() }} recebida(s)</p></div></div>
            <a class="btn btn-primary" href="{{ route('report-dispatches.index', $report) }}"><i data-lucide="arrow-right" aria-hidden="true"></i>Abrir remessas</a>
        </section>
    @endif

    <div class="governance-metrics mb-4">
        <article><small>Valor previsto</small><strong>R$ {{ number_format($totals['expected'], 2, ',', '.') }}</strong><span>{{ $totals['amendments'] }} emenda(s)</span></article>
        <article><small>Recebido</small><strong>R$ {{ number_format($totals['received'], 2, ',', '.') }}</strong><span>{{ $totals['expected'] > 0 ? round(($totals['received'] / $totals['expected']) * 100) : 0 }}% do previsto</span></article>
        <article><small>Empenhado</small><strong>R$ {{ number_format($totals['committed'], 2, ',', '.') }}</strong><span>R$ {{ number_format($totals['liquidated'], 2, ',', '.') }} liquidados</span></article>
        <article><small>Pago</small><strong>R$ {{ number_format($totals['paid'], 2, ',', '.') }}</strong><span>R$ {{ number_format($totals['balance'], 2, ',', '.') }} de saldo</span></article>
        <article class="{{ $totals['open_alerts'] > 0 ? 'has-risk' : '' }}"><small>Pendências ativas</small><strong>{{ $totals['open_work_items'] + $totals['open_alerts'] }}</strong><span>{{ $totals['open_impediments'] }} impedimento(s)</span></article>
    </div>

    <div class="governance-overview mb-4">
        <section class="content-panel">
            <div class="content-panel-header governance-panel-header"><div><p class="page-kicker mb-1">Controle interno</p><h2 class="h5 mb-0">Matriz de acompanhamento</h2></div><span class="small text-secondary">Posição da competência</span></div>
            <div class="governance-control-list">
                @foreach ($snapshot['control_matrix'] as $check)
                    <div class="governance-control-row"><span class="control-state {{ $check['status'] }}"><i data-lucide="{{ $check['status'] === 'controlled' ? 'circle-check' : 'circle-alert' }}" aria-hidden="true"></i></span><div><strong>{{ $check['label'] }}</strong><small>{{ $check['met'] }} controlada(s) · {{ $check['pending'] }} pendente(s)</small></div><span class="governance-control-label {{ $check['status'] }}">{{ $check['status'] === 'controlled' ? 'Controlado' : 'Requer atenção' }}</span></div>
                @endforeach
            </div>
        </section>
        <section class="content-panel">
            <div class="content-panel-header governance-panel-header"><div><p class="page-kicker mb-1">Estrutura municipal</p><h2 class="h5 mb-0">Governança da competência</h2></div></div>
            <div class="governance-facts">
                <div><span>Norma municipal vigente</span><strong>{{ $snapshot['governance']['active_normative_profile'] ? 'Sim · versão '.$snapshot['governance']['normative_profile_version'] : 'Não localizada' }}</strong></div>
                <div><span>Instrumentos normativos</span><strong>{{ $snapshot['governance']['normative_instruments'] }}</strong></div>
                <div><span>Portal de transparência</span><strong>{{ $snapshot['governance']['transparency_enabled'] ? 'Publicado' : 'Não publicado' }}</strong></div>
                <div><span>Último lote Audesp</span><strong>{{ $snapshot['governance']['latest_audesp_batch']['status_label'] ?? 'Sem lote na competência' }}</strong></div>
                <div><span>Execução física média</span><strong>{{ $totals['average_physical_execution'] }}%</strong></div>
            </div>
        </section>
    </div>

    <section class="content-panel mb-4">
        <div class="content-panel-header governance-panel-header"><div class="d-flex align-items-center gap-2"><i data-lucide="triangle-alert" aria-hidden="true"></i><h2 class="h5 mb-0">Providências prioritárias</h2></div><span class="small text-secondary">{{ count($snapshot['attention']) }} emenda(s) no recorte</span></div>
        @if (empty($snapshot['attention']))
            <div class="empty-state">Nenhuma pendência prioritária foi identificada nesta fotografia.</div>
        @else
            <div class="governance-attention-list">
                @foreach ($snapshot['attention'] as $row)
                    <a href="{{ route('emendas.show', $row['id']) }}"><span><strong>{{ $row['reference'] }}</strong><small>{{ Str::limit($row['object'], 72) }}</small></span><span class="attention-tags">@if($row['critical_alerts'])<b>{{ $row['critical_alerts'] }} alerta(s) crítico(s)</b>@endif @if($row['overdue_impediments'])<b>{{ $row['overdue_impediments'] }} impedimento(s) vencido(s)</b>@endif @if($row['compliance_pending'])<b>{{ $row['compliance_pending'] }} item(ns) de conformidade</b>@endif @if(!$row['audesp_prepared'])<b>Audesp pendente</b>@endif</span><i data-lucide="arrow-right" aria-hidden="true"></i></a>
                @endforeach
            </div>
        @endif
    </section>

    <section class="content-panel mb-4">
        <div class="content-panel-header governance-panel-header"><div><p class="page-kicker mb-1">Execução consolidada</p><h2 class="h5 mb-0">Emendas da competência</h2></div><span class="small text-secondary">Valores em reais</span></div>
        <div class="governance-table-wrap">
            <table class="governance-table"><thead><tr><th>Emenda</th><th>Situação</th><th>Recebido</th><th>Empenhado</th><th>Liquidado</th><th>Pago</th><th>Saldo</th><th>Execução</th><th>Controles</th></tr></thead><tbody>
                @foreach ($snapshot['amendments'] as $row)
                    <tr><td><a href="{{ route('emendas.show', $row['id']) }}"><strong>{{ $row['reference'] }}</strong></a><small>{{ Str::limit($row['object'], 48) }}</small></td><td><strong>{{ $row['status_label'] }}</strong><small>{{ $row['department'] ?: 'Órgão não informado' }}</small></td><td>{{ number_format($row['received'], 2, ',', '.') }}</td><td>{{ number_format($row['committed'], 2, ',', '.') }}</td><td>{{ number_format($row['liquidated'], 2, ',', '.') }}</td><td>{{ number_format($row['paid'], 2, ',', '.') }}</td><td><strong>{{ number_format($row['balance'], 2, ',', '.') }}</strong></td><td><strong>{{ $row['physical_execution'] }}%</strong><small>{{ $row['work_plan_label'] }}</small></td><td><strong>{{ $row['compliance_percentage'] }}% conformidade</strong><small>{{ $row['open_alerts'] }} alerta(s) · {{ $row['open_impediments'] }} impedimento(s)</small></td></tr>
                @endforeach
            </tbody></table>
        </div>
    </section>

    <div class="governance-basis mb-4" role="note"><i data-lucide="info" aria-hidden="true"></i><div><strong>Escopo institucional</strong><p>{{ $snapshot['disclaimer'] }}</p></div><a href="{{ $snapshot['basis']['manual_url'] }}" target="_blank" rel="noopener noreferrer">Base técnica</a></div>

    @if ($canEdit)
        <section class="content-panel mb-4">
            <div class="content-panel-header governance-panel-header"><div><p class="page-kicker mb-1">Versão {{ $report->version }}</p><h2 class="h5 mb-0">Atualização da fotografia</h2></div><span class="small text-secondary">Última consolidação {{ $report->data_generated_at->format('d/m/Y H:i') }}</span></div>
            <form class="governance-refresh-form" method="POST" action="{{ route('governance-reports.refresh', $report) }}">@csrf @method('PATCH')<input name="_submission_token" type="hidden" value="{{ $refreshToken }}"><div><label class="form-label" for="management_notes">Observações da gestão</label><textarea class="form-control @error('management_notes') is-invalid @enderror" id="management_notes" name="management_notes" rows="3" maxlength="4000">{{ old('management_notes', $report->management_notes) }}</textarea>@error('management_notes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div><button class="btn btn-outline-primary" type="submit"><i data-lucide="refresh-cw" aria-hidden="true"></i>Atualizar dados</button></form>
        </section>
    @elseif ($report->management_notes)
        <section class="content-panel mb-4"><div class="content-panel-header"><h2 class="h5 mb-0">Observações da gestão</h2></div><div class="content-panel-body"><p class="mb-0 text-pre-wrap">{{ $report->management_notes }}</p></div></section>
    @endif

    @if ($canIssue)
        <section class="governance-issue-panel">
            <div><i data-lucide="shield-check" aria-hidden="true"></i><span><strong>Emissão institucional</strong><small>A versão emitida permanece imutável e identificada pelo hash SHA-256.</small></span></div>
            <form method="POST" action="{{ route('governance-reports.issue', $report) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $issueToken }}"><label><input name="confirm_snapshot" type="checkbox" value="1" required> Fotografia mensal revisada</label><button class="btn btn-primary" type="submit"><i data-lucide="badge-check" aria-hidden="true"></i>Emitir versão</button></form>
        </section>
    @endif
@endsection
