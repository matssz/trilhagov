<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $report->code() }}</title>
    <style>
        @page { margin: 26px 30px 32px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #12263f; font-family: DejaVu Sans, sans-serif; font-size: 8px; }
        header { margin-bottom: 16px; padding-bottom: 11px; border-bottom: 3px solid #cba62b; }
        .brand { color: #0b315d; font-size: 19px; font-weight: bold; } .brand span { color: #b58b16; }
        .meta { float: right; text-align: right; } h1 { margin: 10px 0 3px; font-size: 17px; } p { margin: 2px 0; }
        .clear { clear: both; } .muted { color: #617083; } .kicker { color: #9b7610; font-size: 7px; font-weight: bold; text-transform: uppercase; }
        .metrics { width: 100%; margin: 0 0 14px; border-spacing: 6px 0; } .metrics td { width: 20%; padding: 8px; border: 1px solid #dbe2e9; background: #f8fafb; }
        .metrics small { display: block; color: #657385; font-size: 6.5px; text-transform: uppercase; } .metrics strong { display: block; margin-top: 4px; font-size: 11px; }
        h2 { margin: 13px 0 6px; color: #0b315d; font-size: 11px; }
        table.data { width: 100%; border-collapse: collapse; } table.data th { padding: 5px; background: #0b315d; color: #fff; font-size: 6px; text-align: left; text-transform: uppercase; }
        table.data td { padding: 5px; border-bottom: 1px solid #dfe5ea; vertical-align: top; } table.data tr:nth-child(even) td { background: #f7f9fa; }
        .right { text-align: right; white-space: nowrap; } .center { text-align: center; } .object { width: 22%; }
        .matrix { width: 100%; margin-bottom: 12px; border-collapse: collapse; } .matrix td { width: 33.33%; padding: 7px; border: 1px solid #dfe5ea; }
        .ok { color: #167555; font-weight: bold; } .attention { color: #a35d00; font-weight: bold; }
        .notice { margin-top: 13px; padding: 8px; border-left: 3px solid #cba62b; background: #f8f6ee; color: #4f5d6d; }
        footer { position: fixed; right: 0; bottom: -19px; left: 0; border-top: 1px solid #dfe5ea; padding-top: 5px; color: #718093; font-size: 6px; }
        .hash { font-family: DejaVu Sans Mono, monospace; word-break: break-all; }
    </style>
</head>
<body>
    @php($totals = $snapshot['totals'])
    <header>
        <div class="meta"><strong>{{ $report->code() }}</strong><br>{{ $report->statusLabel() }} · competência {{ $report->periodLabel() }}<br>Dados de {{ $report->data_generated_at->format('d/m/Y H:i') }}</div>
        <div class="brand">Trilha<span>Gov</span></div>
        <div class="clear"></div>
        <p class="kicker">Governança municipal das emendas parlamentares</p>
        <h1>Relatório mensal de execução e controle</h1>
        <p>{{ $snapshot['municipality']['name'] }}/{{ $snapshot['municipality']['state'] }} · CNPJ {{ $snapshot['municipality']['cnpj'] }} · IBGE {{ $snapshot['municipality']['ibge_code'] }}</p>
    </header>

    <table class="metrics"><tr>
        <td><small>Valor previsto</small><strong>R$ {{ number_format($totals['expected'], 2, ',', '.') }}</strong></td>
        <td><small>Recebido</small><strong>R$ {{ number_format($totals['received'], 2, ',', '.') }}</strong></td>
        <td><small>Empenhado</small><strong>R$ {{ number_format($totals['committed'], 2, ',', '.') }}</strong></td>
        <td><small>Liquidado</small><strong>R$ {{ number_format($totals['liquidated'], 2, ',', '.') }}</strong></td>
        <td><small>Pago · saldo</small><strong>R$ {{ number_format($totals['paid'], 2, ',', '.') }}</strong><span>Saldo R$ {{ number_format($totals['balance'], 2, ',', '.') }}</span></td>
    </tr></table>

    <h2>Matriz de acompanhamento do controle interno</h2>
    <table class="matrix"><tr>
        @foreach ($snapshot['control_matrix'] as $index => $check)
            @if ($index > 0 && $index % 3 === 0)</tr><tr>@endif
            <td><strong>{{ $check['label'] }}</strong><br><span class="{{ $check['status'] === 'controlled' ? 'ok' : 'attention' }}">{{ $check['status'] === 'controlled' ? 'Controlado' : 'Requer atenção' }}</span> · {{ $check['met'] }} atendida(s), {{ $check['pending'] }} pendente(s)</td>
        @endforeach
    </tr></table>

    <h2>Execução consolidada por emenda</h2>
    <table class="data">
        <thead><tr><th>Emenda / autor</th><th class="object">Objeto / órgão</th><th>Situação</th><th class="right">Recebido</th><th class="right">Empenhado</th><th class="right">Liquidado</th><th class="right">Pago</th><th class="right">Saldo</th><th>Controle</th></tr></thead>
        <tbody>@foreach ($snapshot['amendments'] as $row)<tr>
            <td><strong>{{ $row['reference'] }}</strong><br><span class="muted">{{ $row['author'] }}</span></td>
            <td>{{ $row['object'] }}<br><span class="muted">{{ $row['department'] ?: 'Órgão não informado' }}</span></td>
            <td>{{ $row['status_label'] }}<br><span class="muted">Físico {{ $row['physical_execution'] }}%</span></td>
            <td class="right">{{ number_format($row['received'], 2, ',', '.') }}</td><td class="right">{{ number_format($row['committed'], 2, ',', '.') }}</td><td class="right">{{ number_format($row['liquidated'], 2, ',', '.') }}</td><td class="right">{{ number_format($row['paid'], 2, ',', '.') }}</td><td class="right"><strong>{{ number_format($row['balance'], 2, ',', '.') }}</strong></td>
            <td>{{ $row['compliance_percentage'] }}% conformidade<br><span class="muted">{{ $row['open_alerts'] }} alerta(s) · {{ $row['open_impediments'] }} impedimento(s)</span></td>
        </tr>@endforeach</tbody>
    </table>

    @if ($report->management_notes)<h2>Observações da gestão</h2><p>{{ $report->management_notes }}</p>@endif
    <div class="notice"><strong>Escopo institucional.</strong> {{ $snapshot['disclaimer'] }}<br><span class="hash">SHA-256: {{ $report->snapshot_sha256 }}</span></div>
    <footer>{{ $snapshot['basis']['manual'] }} · {{ $snapshot['basis']['governance_notice'] }} · {{ $snapshot['basis']['chamber_report_notice'] }}</footer>
</body>
</html>
