<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $report->code() }}</title>
    <style>
        @page { margin: 22px 28px; }
        body { color: #172133; font: 10px DejaVu Sans, sans-serif; }
        h1 { color: #0a2f5a; font-size: 19px; margin: 4px 0; }
        h2 { border-bottom: 1px solid #ccd7e2; color: #0a2f5a; font-size: 12px; margin: 17px 0 7px; padding-bottom: 4px; }
        p { line-height: 1.45; }
        .header { border-bottom: 3px solid #1d5f96; margin-bottom: 12px; padding-bottom: 9px; }
        .brand { color: #0a2f5a; font-size: 13px; font-weight: bold; }
        .brand span { color: #b98910; }
        .meta { color: #647085; }
        .status { background: #e8eff7; border-radius: 3px; display: inline-block; font-weight: bold; padding: 3px 7px; }
        .metrics { display: table; table-layout: fixed; width: 100%; }
        .metric { border: 1px solid #d4dce4; display: table-cell; padding: 8px; }
        .metric span { color: #647085; display: block; font-size: 8px; text-transform: uppercase; }
        .metric strong { color: #0a2f5a; display: block; font-size: 13px; margin-top: 3px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #edf3f6; color: #0a2f5a; font-size: 8px; text-align: left; text-transform: uppercase; }
        th, td { border: 1px solid #d4dce4; padding: 5px; vertical-align: top; }
        .critical { color: #a51f1f; font-weight: bold; }
        .warning { color: #8a5c00; font-weight: bold; }
        .notice { background: #f3f6f8; border-left: 3px solid #1d5f96; margin-top: 14px; padding: 8px 10px; }
        .hash { color: #647085; font-family: DejaVu Sans Mono, monospace; font-size: 8px; word-break: break-all; }
        .footer { border-top: 1px solid #d4dce4; color: #647085; margin-top: 14px; padding-top: 7px; }
    </style>
</head>
<body>
@php($money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.'))
<div class="header">
    <div class="brand">Trilha<span>Gov</span> · {{ $snapshot['municipality']['name'] }}/{{ $snapshot['municipality']['state'] }}</div>
    <h1>{{ $report->typeLabel() }}</h1>
    <div class="meta">{{ $report->code() }} · Competência {{ $report->periodLabel() }} · <span class="status">{{ $report->statusLabel() }}</span></div>
</div>

@if ($report->report_type === 'health')
    <div class="metrics">
        <div class="metric"><span>Reserva exigida</span><strong>{{ $money($snapshot['summary']['required_health_reserve']) }}</strong></div>
        <div class="metric"><span>Reservado em saúde</span><strong>{{ $money($snapshot['summary']['reserved_for_health']) }}</strong></div>
        <div class="metric"><span>Insuficiência</span><strong>{{ $money($snapshot['summary']['shortfall']) }}</strong></div>
        <div class="metric"><span>Pago</span><strong>{{ $money($snapshot['summary']['paid']) }}</strong></div>
    </div>
    <h2>Reserva por autor</h2>
    <table><thead><tr><th>Autor</th><th>Total</th><th>Exigida</th><th>Saúde</th><th>Insuficiência</th><th>Situação</th></tr></thead><tbody>
        @foreach ($snapshot['authors'] as $author)<tr><td>{{ $author['author'] }}</td><td>{{ $money($author['expected']) }}</td><td>{{ $money($author['required']) }}</td><td>{{ $money($author['reserved']) }}</td><td>{{ $money($author['shortfall']) }}</td><td>{{ $author['status'] === 'compliant' ? 'Atendida' : 'Revisar' }}</td></tr>@endforeach
    </tbody></table>
@elseif ($report->report_type === 'divergences')
    <div class="metrics">
        <div class="metric"><span>Analisadas</span><strong>{{ $snapshot['summary']['amendments_analyzed'] }}</strong></div>
        <div class="metric"><span>Com divergência</span><strong>{{ $snapshot['summary']['divergent_amendments'] }}</strong></div>
        <div class="metric"><span>Críticas</span><strong>{{ $snapshot['summary']['critical_amendments'] }}</strong></div>
        <div class="metric"><span>Ocorrências</span><strong>{{ $snapshot['summary']['occurrences'] }}</strong></div>
    </div>
    <h2>Matriz de divergências</h2>
    <table><thead><tr><th>Emenda</th><th>Objeto / órgão</th><th>Financeiro</th><th>Físico</th><th>Apontamentos</th></tr></thead><tbody>
        @foreach ($snapshot['rows'] as $row)<tr><td>{{ $row['reference'] }}<br>{{ $row['author'] }}</td><td>{{ $row['object'] }}<br>{{ $row['department'] }}</td><td>{{ $row['financial_execution'] }}%</td><td>{{ $row['physical_execution'] }}%</td><td>@foreach ($row['divergences'] as $item)<div class="{{ $item['severity'] }}">{{ $item['label'] }}</div>@endforeach</td></tr>@endforeach
    </tbody></table>
@else
    <div class="metrics">
        <div class="metric"><span>Emendas</span><strong>{{ $snapshot['summary']['amendments'] }}</strong></div>
        <div class="metric"><span>Valor previsto</span><strong>{{ $money($snapshot['summary']['expected']) }}</strong></div>
        <div class="metric"><span>Valor pago</span><strong>{{ $money($snapshot['summary']['paid']) }}</strong></div>
        <div class="metric"><span>Relatórios mensais</span><strong>{{ $snapshot['coverage']['months_with_issued_report'] }}/{{ $snapshot['coverage']['months_expected'] }}</strong></div>
    </div>
    <h2>Cobertura institucional</h2>
    <table><tbody>
        <tr><th>Comunicações oficiais</th><td>{{ $snapshot['coverage']['official_documents'] }}</td><th>Recebidas</th><td>{{ $snapshot['coverage']['official_documents_acknowledged'] }}</td></tr>
        <tr><th>Programas de auditoria</th><td>{{ $snapshot['coverage']['audit_programs'] }}</td><th>Concluídos</th><td>{{ $snapshot['coverage']['audit_programs_concluded'] }}</td></tr>
        <tr><th>Pareceres do controle</th><td>{{ $snapshot['coverage']['internal_control_reviews'] }}</td><th>Planos anuais emitidos</th><td>{{ $snapshot['coverage']['audit_plans_issued'] }}</td></tr>
        <tr><th>Prestações de contas</th><td>{{ $snapshot['coverage']['accountability_processes'] }}</td><th>Aprovadas</th><td>{{ $snapshot['coverage']['accountability_approved'] }}</td></tr>
    </tbody></table>
    <h2>Emendas do exercício</h2>
    <table><thead><tr><th>Referência</th><th>Autor</th><th>Objeto</th><th>Previsto</th><th>Pago</th><th>Físico</th><th>Situação</th></tr></thead><tbody>
        @foreach ($snapshot['rows'] as $row)<tr><td>{{ $row['reference'] }}</td><td>{{ $row['author'] }}</td><td>{{ $row['object'] }}</td><td>{{ $money($row['expected']) }}</td><td>{{ $money($row['paid']) }}</td><td>{{ $row['physical_execution'] }}%</td><td>{{ $row['status_label'] }}</td></tr>@endforeach
    </tbody></table>
@endif

@if ($report->management_notes)<h2>Observações da gestão</h2><p>{{ $report->management_notes }}</p>@endif
<div class="notice"><strong>Limite de uso:</strong> {{ $snapshot['specific_disclaimer'] }}</div>
<div class="footer">Dados gerados em {{ $report->data_generated_at->format('d/m/Y H:i') }} · Hash SHA-256: <span class="hash">{{ $report->snapshot_sha256 }}</span></div>
</body>
</html>
