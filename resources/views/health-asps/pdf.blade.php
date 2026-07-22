<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $assessment->code() }}</title>
    <style>
        @page { margin: 28px 34px; }
        body { color: #172133; font: 10px DejaVu Sans, sans-serif; }
        h1 { color: #0a2f5a; font-size: 19px; margin: 5px 0; }
        h2 { border-bottom: 1px solid #ccd7e2; color: #0a2f5a; font-size: 12px; margin: 17px 0 7px; padding-bottom: 4px; }
        p { line-height: 1.5; }
        .header { border-bottom: 3px solid #1d5f96; margin-bottom: 12px; padding-bottom: 10px; }
        .brand { color: #0a2f5a; font-size: 13px; font-weight: bold; }
        .brand span { color: #b98910; }
        .meta { color: #647085; }
        .conclusion { border: 1px solid #157f57; color: #126746; display: inline-block; font-size: 12px; font-weight: bold; margin: 8px 0; padding: 6px 9px; }
        .conclusion.ineligible { border-color: #bd2c2c; color: #9d2020; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d4dce4; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #edf3f6; color: #0a2f5a; font-size: 8px; text-transform: uppercase; }
        .check { color: #157f57; font-weight: bold; }
        .fail { color: #bd2c2c; font-weight: bold; }
        .notice { background: #edf3f6; border-left: 3px solid #1d5f96; margin-top: 15px; padding: 8px 10px; }
        .signatures { display: table; margin-top: 28px; table-layout: fixed; width: 100%; }
        .signature { display: table-cell; padding: 0 15px; text-align: center; }
        .signature div { border-top: 1px solid #647085; padding-top: 5px; }
        .hash { color: #647085; font-size: 7px; margin-top: 15px; word-break: break-all; }
    </style>
</head>
<body>
    <div class="header"><div class="brand">Trilha<span>Gov</span> · {{ $assessment->municipality->name }}/{{ $assessment->municipality->state }}</div><h1>Parecer de enquadramento em ASPS</h1><div class="meta">{{ $assessment->code() }} · {{ $snapshot['amendment']['reference'] }} · Exercício {{ $snapshot['amendment']['fiscal_year'] }}</div></div>
    <div class="conclusion {{ $assessment->conclusion === 'ineligible' ? 'ineligible' : '' }}">Conclusão: {{ $assessment->conclusionLabel() }}</div>
    <h2>Emenda parlamentar</h2>
    <table><tbody><tr><th>Autor</th><td>{{ $snapshot['amendment']['author'] }}</td><th>Valor previsto</th><td>R$ {{ number_format($snapshot['amendment']['expected_amount'], 2, ',', '.') }}</td></tr><tr><th>Objeto</th><td colspan="3">{{ $snapshot['amendment']['object'] }}</td></tr><tr><th>Órgão executor</th><td colspan="3">{{ $snapshot['amendment']['responsible_department'] }}</td></tr></tbody></table>
    <h2>Classificação e rastreabilidade</h2>
    <table><tbody><tr><th>Categoria ASPS</th><td colspan="3">{{ app(App\Services\HealthAspsFramework::class)->categories()[$snapshot['assessment']['asps_category']] ?? 'Não informada' }}</td></tr><tr><th>Função / subfunção</th><td>{{ $snapshot['assessment']['budget_function'] }} / {{ $snapshot['assessment']['budget_subfunction'] }}</td><th>Fonte / aplicação</th><td>{{ $snapshot['assessment']['funding_source_code'] }} / {{ $snapshot['assessment']['application_code'] ?: '—' }}</td></tr><tr><th>Fundo de Saúde</th><td>{{ $snapshot['assessment']['health_fund_reference'] }}</td><th>Plano Municipal de Saúde</th><td>{{ $snapshot['assessment']['health_plan_reference'] }}</td></tr></tbody></table>
    <h2>Critérios da LC 141</h2>
    <table><thead><tr><th>Critério</th><th>Verificação</th></tr></thead><tbody>@foreach(app(App\Services\HealthAspsFramework::class)->criteria() as $key => $label)<tr><td>{{ $label }}</td><td class="{{ $snapshot['assessment']['criteria'][$key] ?? false ? 'check' : 'fail' }}">{{ $snapshot['assessment']['criteria'][$key] ?? false ? 'Atendido' : 'Não confirmado' }}</td></tr>@endforeach</tbody></table>
    <h2>Fundamentação</h2><p>{{ $snapshot['assessment']['technical_justification'] }}</p>
    @if($snapshot['assessment']['exclusion_reasons'])<h2>Hipóteses de exclusão identificadas</h2><ul>@foreach($snapshot['assessment']['exclusion_reasons'] as $reason)<li>{{ app(App\Services\HealthAspsFramework::class)->exclusions()[$reason] ?? $reason }}</li>@endforeach</ul>@endif
    <h2>Decisão do responsável</h2><p>{{ $snapshot['assessment']['reviewer_notes'] }}</p>
    <div class="notice">{{ $snapshot['disclaimer'] }}</div>
    <div class="signatures"><div class="signature"><div>{{ $assessment->submitter?->name ?? $assessment->creator->name }}<br>Responsável pela instrução</div></div><div class="signature"><div>{{ $assessment->reviewer->name }}<br>Responsável pela decisão</div></div></div>
    <div class="hash">Emitido em {{ $assessment->reviewed_at->format('d/m/Y H:i') }} · SHA-256: {{ $assessment->snapshot_sha256 }}</div>
</body>
</html>
