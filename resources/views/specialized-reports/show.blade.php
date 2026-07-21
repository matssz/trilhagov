@extends('layouts.app')

@section('title', $report->code().' | TrilhaGov')

@section('content')
    @php
        $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
        $summary = $snapshot['summary'];
    @endphp
    <a class="back-link mb-3" href="{{ route('specialized-reports.index') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Relatórios especializados</a>
    <div class="page-heading report-heading">
        <div><span class="page-kicker">{{ $report->code() }}</span><h1>{{ $report->typeLabel() }}</h1><p>{{ $municipality->name }} · Competência {{ $report->periodLabel() }}</p></div>
        <div class="report-actions">
            <span class="status-pill {{ $report->status === 'issued' ? 'is-success' : 'is-warning' }}">{{ $report->statusLabel() }}</span>
            <a class="btn btn-outline-secondary" href="{{ route('specialized-reports.csv', $report) }}"><i data-lucide="sheet" aria-hidden="true"></i>CSV</a>
            <a class="btn btn-primary" href="{{ route('specialized-reports.pdf', $report) }}"><i data-lucide="file-down" aria-hidden="true"></i>PDF</a>
        </div>
    </div>

    @if ($report->report_type === 'health')
        <div class="metric-strip report-metrics">
            <article><span>Reserva exigida</span><strong>{{ $money($summary['required_health_reserve']) }}</strong><small>{{ number_format($snapshot['profile']['percentage'], 2, ',', '.') }}% · {{ $snapshot['profile']['method_label'] }}</small></article>
            <article><span>Reservado em saúde</span><strong>{{ $money($summary['reserved_for_health']) }}</strong><small>{{ $summary['health_amendments'] }} emenda(s)</small></article>
            <article class="{{ $summary['shortfall'] > 0 ? 'metric-attention' : '' }}"><span>Insuficiência</span><strong>{{ $money($summary['shortfall']) }}</strong><small>{{ $summary['unclassified'] }} sem classificação</small></article>
            <article><span>Pago em saúde</span><strong>{{ $money($summary['paid']) }}</strong><small>{{ $summary['average_physical_execution'] }}% físico médio</small></article>
        </div>

        @if ($summary['status'] !== 'compliant')
            <div class="report-callout is-warning"><i data-lucide="triangle-alert" aria-hidden="true"></i><div><strong>Conferência necessária</strong><span>{{ $summary['unclassified'] > 0 ? 'Classifique os planos de trabalho pendentes e confira a reserva com a contabilidade.' : 'A reserva calculada requer ajuste ou validação da regra municipal.' }}</span></div></div>
        @endif

        <section class="content-panel">
            <div class="content-panel-header"><h2 class="h5 mb-0">Reserva por autor</h2><span class="record-count">{{ count($snapshot['authors']) }}</span></div>
            <div class="table-responsive"><table class="table app-table align-middle mb-0"><thead><tr><th>Autor</th><th>Emendas</th><th>Reserva exigida</th><th>Saúde</th><th>Insuficiência</th><th>Situação</th></tr></thead><tbody>
                @foreach ($snapshot['authors'] as $author)
                    <tr><td><strong>{{ $author['author'] }}</strong></td><td>{{ $money($author['expected']) }}</td><td>{{ $money($author['required']) }}</td><td>{{ $money($author['reserved']) }}</td><td>{{ $money($author['shortfall']) }}</td><td><span class="status-pill {{ $author['status'] === 'compliant' ? 'is-success' : 'is-warning' }}">{{ $author['status'] === 'compliant' ? 'Atendida' : 'Revisar' }}</span></td></tr>
                @endforeach
            </tbody></table></div>
        </section>
    @elseif ($report->report_type === 'divergences')
        <div class="metric-strip report-metrics">
            <article><span>Emendas analisadas</span><strong>{{ $summary['amendments_analyzed'] }}</strong><small>Tolerância de {{ $snapshot['threshold'] }} p.p.</small></article>
            <article class="metric-attention"><span>Com divergência</span><strong>{{ $summary['divergent_amendments'] }}</strong><small>{{ $summary['occurrences'] }} ocorrência(s)</small></article>
            <article class="metric-critical"><span>Críticas</span><strong>{{ $summary['critical_amendments'] }}</strong><small>Priorização imediata</small></article>
            <article><span>Sem divergência</span><strong>{{ $summary['aligned_amendments'] }}</strong><small>Nos critérios automáticos</small></article>
        </div>

        <section class="content-panel">
            <div class="content-panel-header"><h2 class="h5 mb-0">Matriz de divergências</h2><span class="record-count">{{ count($snapshot['rows']) }}</span></div>
            @if (empty($snapshot['rows']))
                <div class="empty-state compact"><i data-lucide="circle-check-big" aria-hidden="true"></i><h3>Nenhuma divergência automática</h3><p>Os registros estão alinhados nos critérios desta competência.</p></div>
            @else
                <div class="divergence-list">
                    @foreach ($snapshot['rows'] as $row)
                        <article class="divergence-row">
                            <div class="divergence-main"><span class="severity-marker is-{{ $row['highest_severity'] }}"></span><div><strong>{{ $row['reference'] }} · {{ $row['object'] }}</strong><small>{{ $row['author'] }} · {{ $row['department'] }}</small></div><a class="icon-button" href="{{ route('emendas.show', $row['id']) }}" title="Abrir emenda" aria-label="Abrir {{ $row['reference'] }}"><i data-lucide="external-link" aria-hidden="true"></i></a></div>
                            <div class="execution-comparison"><span><small>Financeiro</small><strong>{{ $row['financial_execution'] }}%</strong></span><div><i style="width: {{ $row['financial_execution'] }}%"></i><b style="width: {{ $row['physical_execution'] }}%"></b></div><span><small>Físico</small><strong>{{ $row['physical_execution'] }}%</strong></span></div>
                            <div class="divergence-flags">@foreach ($row['divergences'] as $item)<span class="flag is-{{ $item['severity'] }}"><i data-lucide="{{ $item['severity'] === 'critical' ? 'circle-alert' : 'triangle-alert' }}" aria-hidden="true"></i>{{ $item['label'] }}</span>@endforeach</div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @else
        <div class="metric-strip report-metrics">
            <article><span>Emendas</span><strong>{{ $summary['amendments'] }}</strong><small>{{ $money($summary['expected']) }} previsto</small></article>
            <article><span>Pago no período</span><strong>{{ $money($summary['paid']) }}</strong><small>{{ $money($summary['balance']) }} de saldo</small></article>
            <article><span>Relatórios mensais</span><strong>{{ $snapshot['coverage']['months_with_issued_report'] }}/{{ $snapshot['coverage']['months_expected'] }}</strong><small>Cobertura até a competência</small></article>
            <article class="{{ $snapshot['coverage']['accountability_with_pending_issues'] > 0 ? 'metric-attention' : '' }}"><span>Prestações com pendência</span><strong>{{ $snapshot['coverage']['accountability_with_pending_issues'] }}</strong><small>{{ $snapshot['coverage']['accountability_approved'] }} aprovada(s)</small></article>
        </div>

        <section class="dossier-band">
            @foreach ([
                ['Comunicações', $snapshot['coverage']['official_documents'], $snapshot['coverage']['official_documents_acknowledged'].' recebida(s)', 'send'],
                ['Programas de auditoria', $snapshot['coverage']['audit_programs'], $snapshot['coverage']['audit_programs_concluded'].' concluído(s)', 'scan-search'],
                ['Pareceres do controle', $snapshot['coverage']['internal_control_reviews'], 'Emitidos no exercício', 'shield-check'],
                ['Prestações de contas', $snapshot['coverage']['accountability_processes'], $snapshot['coverage']['accountability_approved'].' aprovada(s)', 'receipt-text'],
            ] as [$label, $value, $detail, $icon])
                <article><i data-lucide="{{ $icon }}" aria-hidden="true"></i><div><span>{{ $label }}</span><strong>{{ $value }}</strong><small>{{ $detail }}</small></div></article>
            @endforeach
        </section>

        <section class="content-panel">
            <div class="content-panel-header"><h2 class="h5 mb-0">Cobertura dos controles</h2></div>
            <div class="control-grid">@foreach ($snapshot['control_matrix'] as $control)<article><div><strong>{{ $control['label'] }}</strong><span>{{ $control['met'] }} atendida(s) · {{ $control['pending'] }} pendente(s)</span></div><span class="status-pill {{ $control['status'] === 'controlled' ? 'is-success' : 'is-warning' }}">{{ $control['status'] === 'controlled' ? 'Controlado' : 'Atenção' }}</span></article>@endforeach</div>
        </section>
    @endif

    <div class="report-notice"><i data-lucide="info" aria-hidden="true"></i><div><strong>Limite de uso</strong><p>{{ $snapshot['specific_disclaimer'] }}</p></div></div>

    <section class="report-integrity">
        <div><span>Integridade da fotografia</span><code>{{ $report->snapshot_sha256 }}</code><small>Gerada em {{ $report->data_generated_at->format('d/m/Y H:i') }} · {{ $report->status === 'issued' ? 'Emitida por '.$report->issuer?->name.' em '.$report->issued_at->format('d/m/Y H:i') : 'Versão ainda editável' }}</small></div>
    </section>

    @if ($canEdit)
        <section class="content-panel mt-4"><div class="content-panel-header"><h2 class="h5 mb-0">Revisão técnica</h2></div><div class="content-panel-body"><form method="POST" action="{{ route('specialized-reports.refresh', $report) }}" data-prevent-double-submit>@csrf @method('PATCH')<input name="_submission_token" type="hidden" value="{{ $refreshToken }}"><label class="form-label w-100">Observações da gestão<textarea class="form-control" name="management_notes" rows="3" maxlength="4000">{{ old('management_notes', $report->management_notes) }}</textarea></label><button class="btn btn-outline-primary" type="submit"><i data-lucide="refresh-cw" aria-hidden="true"></i>Recalcular dados</button></form></div></section>
    @elseif ($report->management_notes)
        <section class="content-panel mt-4"><div class="content-panel-header"><h2 class="h5 mb-0">Observações da gestão</h2></div><div class="content-panel-body"><p class="mb-0 text-pre-wrap">{{ $report->management_notes }}</p></div></section>
    @endif

    @if ($canIssue)
        <section class="governance-issue-panel mt-4"><div><i data-lucide="shield-check" aria-hidden="true"></i><span><strong>Emissão institucional</strong><small>A versão emitida ficará imutável e identificada pelo hash SHA-256.</small></span></div><form method="POST" action="{{ route('specialized-reports.issue', $report) }}" data-prevent-double-submit>@csrf<input name="_submission_token" type="hidden" value="{{ $issueToken }}"><label><input name="confirm_snapshot" type="checkbox" value="1" required> Dados e ressalvas revisados</label><button class="btn btn-primary" type="submit"><i data-lucide="badge-check" aria-hidden="true"></i>Emitir versão</button></form></section>
    @endif
@endsection
