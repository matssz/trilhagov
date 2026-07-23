<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Dossie TCESP - {{ $amendment->reference }}</title>
    <style>
        @page { margin: 30px 34px; }
        body { color: #172133; font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.42; }
        h1, h2, h3 { color: #071f3d; margin: 0; }
        h1 { font-size: 20px; }
        h2 { margin: 16px 0 7px; font-size: 13px; }
        h3 { font-size: 10px; }
        p { margin: 3px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px; border: 1px solid #dce3ec; vertical-align: top; }
        th { background: #eef3f8; color: #0a2f5a; text-align: left; }
        .header { margin-bottom: 16px; padding-bottom: 12px; border-bottom: 3px solid #0a2f5a; }
        .brand { color: #0a2f5a; font-size: 17px; font-weight: bold; }
        .brand span { color: #b98910; }
        .muted { color: #647085; }
        .section { margin-top: 14px; page-break-inside: avoid; }
        .summary td { width: 25%; }
        .summary strong { display: block; margin-top: 3px; color: #071f3d; font-size: 12px; }
        .badge { display: inline-block; padding: 2px 5px; background: #e8eff7; color: #0a2f5a; font-weight: bold; }
        .critical { background: #fde8e8; color: #bd2c2c; }
        .warning { background: #fff4d8; color: #7a5400; }
        .ready { background: #e8f6ef; color: #157f57; }
        .page-break { page-break-before: always; }
        .footer-note { margin-top: 18px; padding-top: 8px; border-top: 1px solid #dce3ec; color: #647085; }
    </style>
</head>
<body>
@php
    $money = fn ($value) => 'R$ '.number_format((float) $value, 2, ',', '.');
    $openEssential = $matrix->filter(fn ($item) => $item['critical'] && ! in_array($item['status'], [
        App\Models\AmendmentComplianceReview::STATUS_COMPLIANT,
        App\Models\AmendmentComplianceReview::STATUS_NOT_APPLICABLE,
    ], true));
    $paid = $amendment->financialCommitments->sum(fn ($commitment) => $commitment->payments->sum('amount')) + $amendment->financialPayments->sum('amount');
@endphp
    <header class="header">
        <div class="brand">Trilha<span>Gov</span></div>
        <p class="muted">Dossie municipal de aderencia TCESP</p>
        <h1>{{ $amendment->reference }}</h1>
        <p>{{ $amendment->municipality->name }} / {{ $amendment->municipality->state }} - exercicio {{ $amendment->fiscal_year }}</p>
        <p>Gerado em {{ $generatedAt->format('d/m/Y') }} as {{ $generatedAt->format('H:i') }} - {{ $frameworkVersion }}</p>
    </header>

    <section class="section">
        <h2>Resumo executivo</h2>
        <table class="summary">
            <tr>
                <td><span class="muted">Conformidade</span><strong>{{ $summary['percentage'] }}%</strong></td>
                <td><span class="muted">Essenciais abertos</span><strong>{{ $openEssential->count() }}</strong></td>
                <td><span class="muted">Alertas abertos</span><strong>{{ $amendment->integrityAlerts->count() }}</strong></td>
                <td><span class="muted">Risco atual</span><strong>{{ $amendment->riskLabel() }}</strong></td>
            </tr>
        </table>
        <p>
            <span class="badge {{ $openEssential->isEmpty() ? 'ready' : 'warning' }}">
                {{ $openEssential->isEmpty() ? 'Itens essenciais saneados' : 'Saneamento TCESP pendente' }}
            </span>
        </p>
        @foreach ($openEssential->take(6) as $item)
            <p>- {{ $item['code'] }}: {{ $item['title'] }}</p>
        @endforeach
    </section>

    <section class="section">
        <h2>Dados da emenda</h2>
        <table>
            <tr><th>Objeto</th><td colspan="3">{{ $amendment->object }}</td></tr>
            <tr><th>Autor</th><td>{{ $amendment->author_name }}</td><th>Modalidade</th><td>{{ $amendment->transferTypeLabel() }}</td></tr>
            <tr><th>Responsavel</th><td>{{ $amendment->responsibleUser?->name ?? 'Nao definido' }}</td><th>Orgao executor</th><td>{{ $amendment->responsible_department ?: 'Nao informado' }}</td></tr>
            <tr><th>Valor previsto</th><td>{{ $money($amendment->expected_amount) }}</td><th>Valor recebido</th><td>{{ $money($amendment->received_amount) }}</td></tr>
        </table>
    </section>

    <section class="section">
        <h2>Execucao e rastreabilidade</h2>
        <table class="summary">
            <tr>
                <td><span class="muted">Execucao fisica</span><strong>{{ $amendment->physicalExecutionPercentage() }}%</strong></td>
                <td><span class="muted">Empenhado</span><strong>{{ $money($amendment->financialCommitments->sum('committed_amount')) }}</strong></td>
                <td><span class="muted">Pago</span><strong>{{ $money($paid) }}</strong></td>
                <td><span class="muted">Audesp</span><strong>{{ $amendment->audespRegistration ? 'Preparado' : 'Pendente' }}</strong></td>
            </tr>
        </table>
    </section>

    <section class="section">
        <h2>Alertas abertos</h2>
        <table>
            <thead><tr><th>Gravidade</th><th>Categoria</th><th>Alerta</th><th>Prazo</th></tr></thead>
            <tbody>
                @forelse ($amendment->integrityAlerts as $alert)
                    <tr><td>{{ $alert->severityLabel() }}</td><td>{{ $alert->categoryLabel() }}</td><td>{{ $alert->title }}<br><span class="muted">{{ $alert->message }}</span></td><td>{{ $alert->due_at?->format('d/m/Y') ?? '-' }}</td></tr>
                @empty
                    <tr><td colspan="4">Nenhum alerta aberto no momento da geracao.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="section page-break">
        <h2>Matriz TCESP</h2>
        <table>
            <thead><tr><th>Regra</th><th>Categoria</th><th>Situacao</th><th>Evidencia</th><th>Fonte</th></tr></thead>
            <tbody>
                @foreach ($matrix as $item)
                    <tr>
                        <td><strong>{{ $item['code'] }}</strong><br>{{ $item['title'] }}@if($item['critical'])<br><span class="badge critical">Essencial</span>@endif</td>
                        <td>{{ $categories[$item['category']]['label'] ?? $item['category'] }}</td>
                        <td>{{ $statuses[$item['status']] ?? $item['status'] }}</td>
                        <td>{{ $item['review']?->evidence_notes ?: ($item['review']?->document?->original_name ?: 'Nao registrada') }}</td>
                        <td>{{ $item['source'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="section page-break">
        <h2>Documentos vinculados</h2>
        <table>
            <thead><tr><th>Tipo</th><th>Arquivo</th><th>Versao</th><th>Etapa</th><th>Envio</th></tr></thead>
            <tbody>
                @forelse ($amendment->documents as $document)
                    <tr><td>{{ $document->documentType->name }}</td><td>{{ $document->original_name }}</td><td>{{ $document->version }}</td><td>{{ $document->executionStage?->title ?? 'Geral' }}</td><td>{{ $document->created_at->format('d/m/Y H:i') }}</td></tr>
                @empty
                    <tr><td colspan="5">Nenhum documento anexado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="section">
        <h2>Controle interno e prestacao de contas</h2>
        <table>
            <tr><th>Revisoes de controle</th><td>{{ $amendment->internalControlReviews->count() }}</td><th>Providencias de controle</th><td>{{ $amendment->internalControlReviews->flatMap->actions->count() }}</td></tr>
            <tr><th>Prestacao de contas</th><td>{{ $amendment->accountabilityProcess?->statusLabel() ?? 'Nao iniciada' }}</td><th>Requisitos</th><td>{{ $amendment->accountabilityProcess?->requirements->count() ?? 0 }}</td></tr>
        </table>
    </section>

    <p class="footer-note">
        Fonte de referencia: {{ $sourceLabel }}. Este dossie consolida registros operacionais do municipio e nao substitui parecer juridico, validacao contabil nem manifestacao formal do TCESP.
    </p>
</body>
</html>
