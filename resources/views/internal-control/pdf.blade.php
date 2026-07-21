<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $review->reference }}</title>
    <style>
        @page { margin: 24mm 18mm 20mm; }
        body { color: #14263a; font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.45; }
        h1 { color: #0a2f5a; font-size: 19px; margin: 0 0 3px; } h2 { border-bottom: 1px solid #cdd6df; color: #0a2f5a; font-size: 12px; margin: 18px 0 8px; padding-bottom: 4px; }
        p { margin: 4px 0; } .muted { color: #5d6b78; } .header { border-bottom: 3px solid #c8a23a; margin-bottom: 16px; padding-bottom: 10px; }
        .brand { color: #0a2f5a; font-size: 13px; font-weight: bold; } .brand span { color: #b78c17; }
        .badge { border: 1px solid #b78c17; color: #765a0d; display: inline-block; font-size: 8px; font-weight: bold; padding: 3px 6px; text-transform: uppercase; }
        table { border-collapse: collapse; margin: 7px 0; width: 100%; } th, td { border: 1px solid #d6dde5; padding: 6px; text-align: left; vertical-align: top; } th { background: #edf2f6; color: #0a2f5a; }
        .facts td { width: 50%; } .criterion-status { font-weight: bold; white-space: nowrap; } .hash { background: #edf2f6; font-family: DejaVu Sans Mono, monospace; font-size: 7px; overflow-wrap: anywhere; padding: 7px; }
        .signature { border-top: 1px solid #7d8995; margin-top: 36px; padding-top: 7px; width: 55%; } .notice { background: #f6f2e5; border-left: 3px solid #c8a23a; margin-top: 18px; padding: 8px; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <div class="header"><div class="brand">Trilha<span>Gov</span> · Controle Interno Municipal</div><h1>Parecer {{ $review->reference }}</h1><p class="muted">{{ $review->municipality->name }}/{{ $review->municipality->state }} · emitido em {{ $review->issued_at->format('d/m/Y H:i') }}</p></div>

    <span class="badge">{{ $review->phaseLabel() }} · {{ $review->conclusionLabel() }}</span>
    <h2>Identificação</h2>
    <table class="facts"><tr><td><strong>Emenda</strong><br>{{ $review->amendment->reference }}</td><td><strong>Exercício</strong><br>{{ $review->amendment->fiscal_year }}</td></tr><tr><td><strong>Autor</strong><br>{{ $review->amendment->author_name }}</td><td><strong>Processo administrativo</strong><br>{{ $review->amendment->administrative_process ?: 'Não informado' }}</td></tr><tr><td colspan="2"><strong>Objeto</strong><br>{{ $review->amendment->object }}</td></tr>@if($review->governanceReport)<tr><td colspan="2"><strong>Relatório mensal relacionado</strong><br>{{ $review->governanceReport->code() }} · competência {{ $review->governanceReport->periodLabel() }}</td></tr>@endif</table>

    <h2>Fundamentação e escopo</h2><p><strong>Plano Anual de Auditoria:</strong> {{ $review->annual_audit_plan_reference }}</p><p>{{ $review->legal_basis }}</p>
    <h2>Verificação padronizada</h2>
    <table><thead><tr><th style="width: 27%">Critério</th><th style="width: 16%">Resultado</th><th>Constatação</th><th style="width: 21%">Referência</th></tr></thead><tbody>@foreach($criteriaDefinitions as $code => $definition)@php($item = $review->criteria[$code])<tr><td><strong>{{ $definition['label'] }}</strong></td><td class="criterion-status">{{ $criterionStatuses[$item['status']] }}</td><td>{{ $item['notes'] ?: 'Sem ressalva registrada.' }}</td><td>{{ $definition['source'] }}</td></tr>@endforeach</tbody></table>

    <h2>Conclusão</h2><p><strong>{{ $review->conclusionLabel() }}</strong></p><p>{{ $review->summary }}</p>@if($review->findings)<p><strong>Achados:</strong> {{ $review->findings }}</p>@endif @if($review->recommendations)<p><strong>Recomendações:</strong> {{ $review->recommendations }}</p>@endif

    @if($review->actions->isNotEmpty())<h2>Providências decorrentes</h2><table><thead><tr><th>Providência</th><th>Responsável</th><th>Prazo</th><th>Situação</th></tr></thead><tbody>@foreach($review->actions as $action)<tr><td>{{ $action->instructions }}</td><td>{{ $action->responsibleUser->name }}</td><td>{{ $action->due_at->format('d/m/Y') }}</td><td>{{ $action->statusLabel() }}</td></tr>@endforeach</tbody></table>@endif

    <div class="signature"><strong>{{ $review->reviewer->name }}</strong><br>Responsável pela emissão no TrilhaGov<br>{{ $review->reviewer->email }}<br>{{ $review->issued_at->format('d/m/Y H:i') }}</div>
    <h2>Integridade da fotografia</h2><p class="hash">SHA-256: {{ $review->snapshot_sha256 }}</p>@if($review->evidence_sha256)<p class="hash">Documento externo SHA-256: {{ $review->evidence_sha256 }}</p>@endif
    <div class="notice">Documento de apoio ao controle municipal, gerado a partir da fotografia preservada no TrilhaGov. A identificação do emissor e o hash comprovam autoria interna e integridade do registro, mas não equivalem, por si sós, a uma assinatura ICP-Brasil.</div>
</body>
</html>
