<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Dossiê de prestação de contas - {{ $amendment->reference }}</title>
    <style>
        @page { margin: 30px 34px; }
        body { color: #172133; font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.42; }
        h1, h2, h3 { color: #071f3d; margin: 0; }
        h1 { font-size: 20px; }
        h2 { margin-bottom: 8px; font-size: 13px; }
        h3 { font-size: 10px; }
        p { margin: 3px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px; border: 1px solid #dce3ec; vertical-align: top; }
        th { background: #eef3f8; color: #0a2f5a; text-align: left; }
        .header { margin-bottom: 18px; padding-bottom: 12px; border-bottom: 3px solid #d2a62b; }
        .brand { color: #0a2f5a; font-size: 17px; font-weight: bold; }
        .brand span { color: #b98910; }
        .muted { color: #647085; }
        .section { margin-top: 16px; page-break-inside: avoid; }
        .summary { margin-top: 10px; }
        .summary td { width: 25%; }
        .summary strong { display: block; margin-top: 3px; color: #071f3d; font-size: 12px; }
        .status { display: inline-block; padding: 2px 5px; background: #e8eff7; color: #0a2f5a; font-weight: bold; }
        .ready { background: #e8f6ef; color: #157f57; }
        .blocked { background: #fde8e8; color: #bd2c2c; }
        .page-break { page-break-before: always; }
        .footer-note { margin-top: 18px; padding-top: 8px; border-top: 1px solid #dce3ec; color: #647085; }
    </style>
</head>
<body>
    <header class="header">
        <div class="brand">Trilha<span>Gov</span></div>
        <p class="muted">Dossiê operacional de prestação de contas</p>
        <h1>{{ $amendment->reference }}</h1>
        <p>{{ $amendment->municipality->name }} / {{ $amendment->municipality->state }} · exercício {{ $amendment->fiscal_year }}</p>
        <p>Gerado em {{ $generatedAt->format('d/m/Y') }} às {{ $generatedAt->format('H:i') }}</p>
    </header>

    <section class="section">
        <h2>Resumo da prestação</h2>
        <table class="summary">
            <tr>
                <td><span class="muted">Situação</span><strong>{{ $process->statusLabel() }}</strong></td>
                <td><span class="muted">Prontidão</span><strong>{{ $readiness['score'] }}%</strong></td>
                <td><span class="muted">Prazo</span><strong>{{ $process->due_at?->format('d/m/Y') ?? 'Não definido' }}</strong></td>
                <td><span class="muted">Responsável</span><strong>{{ $process->responsibleUser?->name ?? 'Não definido' }}</strong></td>
            </tr>
        </table>
        <p><span class="status {{ $readiness['ready'] ? 'ready' : 'blocked' }}">{{ $readiness['ready'] ? 'Pronta para envio' : 'Com pendências' }}</span></p>
        @foreach ($readiness['blockers'] as $blocker)<p>• {{ $blocker }}</p>@endforeach
    </section>

    <section class="section">
        <h2>Dados da emenda</h2>
        <table>
            <tr><th>Objeto</th><td colspan="3">{{ $amendment->object }}</td></tr>
            <tr><th>Autor</th><td>{{ $amendment->author_name }}</td><th>Modalidade</th><td>{{ $amendment->transferTypeLabel() }}</td></tr>
            <tr><th>Órgão responsável</th><td>{{ $amendment->responsible_department }}</td><th>Código Transferegov</th><td>{{ $amendment->transferegov_code ?: 'Não informado' }}</td></tr>
        </table>
    </section>

    <section class="section">
        <h2>Conciliação financeira</h2>
        <table class="summary">
            <tr>
                <td><span class="muted">Recebido</span><strong>R$ {{ number_format($readiness['financial']['received'], 2, ',', '.') }}</strong></td>
                <td><span class="muted">Pago</span><strong>R$ {{ number_format($readiness['financial']['paid'], 2, ',', '.') }}</strong></td>
                <td><span class="muted">Devolvido</span><strong>R$ {{ number_format($readiness['financial']['returned'], 2, ',', '.') }}</strong></td>
                <td><span class="muted">Sem conciliação</span><strong>R$ {{ number_format($readiness['financial']['unreconciled'], 2, ',', '.') }}</strong></td>
            </tr>
        </table>
        @if ($process->reconciliation_notes)<p><strong>Observações:</strong> {{ $process->reconciliation_notes }}</p>@endif
    </section>

    <section class="section">
        <h2>Execução física</h2>
        <table>
            <thead><tr><th>Etapa</th><th>Situação</th><th>Progresso</th><th>Prazo</th><th>Responsável</th></tr></thead>
            <tbody>
                @forelse ($amendment->executionStages as $stage)
                    <tr><td>{{ $stage->title }}</td><td>{{ $stage->statusLabel() }}</td><td>{{ $stage->progress_percentage }}%</td><td>{{ $stage->planned_end_at?->format('d/m/Y') ?? 'Sem prazo' }}</td><td>{{ $stage->responsibleUser?->name ?? $amendment->responsibleUser?->name ?? 'Não definido' }}</td></tr>
                @empty
                    <tr><td colspan="5">Nenhuma etapa cadastrada.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="section page-break">
        <h2>Empenhos e pagamentos</h2>
        <table>
            <thead><tr><th>Empenho</th><th>Fornecedor</th><th>Processo</th><th>Empenhado</th><th>Pago</th></tr></thead>
            <tbody>
                @forelse ($amendment->financialCommitments as $commitment)
                    <tr><td>{{ $commitment->commitment_number }}</td><td>{{ $commitment->supplier_name }}</td><td>{{ $commitment->procurement_process }}</td><td>R$ {{ number_format($commitment->committed_amount, 2, ',', '.') }}</td><td>R$ {{ number_format($commitment->payments->sum('amount'), 2, ',', '.') }}</td></tr>
                @empty
                    <tr><td colspan="5">Nenhum empenho cadastrado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="section">
        <h2>Checklist</h2>
        <table>
            <thead><tr><th>Item</th><th>Categoria</th><th>Situação</th><th>Documento</th><th>Observação</th></tr></thead>
            <tbody>
                @foreach ($process->requirements as $requirement)
                    <tr><td>{{ $requirement->title }}</td><td>{{ $requirement->categoryLabel() }}</td><td>{{ $requirement->statusLabel() }}</td><td>{{ $requirement->document?->original_name ?? 'Não vinculado' }}</td><td>{{ $requirement->notes }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="section">
        <h2>Diligências</h2>
        <table>
            <thead><tr><th>Diligência</th><th>Recebida</th><th>Prazo</th><th>Situação</th><th>Protocolo de resposta</th></tr></thead>
            <tbody>
                @forelse ($process->diligences as $diligence)
                    <tr><td>{{ $diligence->title }}</td><td>{{ $diligence->received_at->format('d/m/Y') }}</td><td>{{ $diligence->due_at->format('d/m/Y') }}</td><td>{{ $diligence->statusLabel() }}</td><td>{{ $diligence->response_protocol ?: 'Não informado' }}</td></tr>
                @empty
                    <tr><td colspan="5">Nenhuma diligência registrada.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="section">
        <h2>Documentos do dossiê</h2>
        <table>
            <thead><tr><th>Tipo</th><th>Arquivo</th><th>Versão</th><th>Etapa</th><th>Enviado em</th></tr></thead>
            <tbody>
                @forelse ($amendment->documents as $document)
                    <tr><td>{{ $document->documentType->name }}</td><td>{{ $document->original_name }}</td><td>{{ $document->version }}</td><td>{{ $document->executionStage?->title ?? 'Documento geral' }}</td><td>{{ $document->created_at->format('d/m/Y H:i') }}</td></tr>
                @empty
                    <tr><td colspan="5">Nenhum documento anexado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="section page-break">
        <h2>Trilha de auditoria</h2>
        <table>
            <thead><tr><th>Data e hora</th><th>Ação</th><th>Responsável</th><th>Origem</th></tr></thead>
            <tbody>
                @forelse ($amendment->auditLogs->take(100) as $auditLog)
                    <tr><td>{{ $auditLog->created_at->format('d/m/Y H:i') }}</td><td>{{ $auditLog->actionLabel() }}</td><td>{{ $auditLog->actor_name }}</td><td>{{ $auditLog->ip_address ?: 'Não registrada' }}</td></tr>
                @empty
                    <tr><td colspan="4">Nenhum evento de auditoria registrado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <p class="footer-note">Documento gerado pelo TrilhaGov a partir dos registros operacionais do município. A validação final deve observar as exigências do órgão concedente aplicáveis à emenda.</p>
</body>
</html>
