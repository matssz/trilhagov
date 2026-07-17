<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Plano de trabalho {{ $amendment->reference }}</title>
    <style>
        @page { margin: 28px 34px; }
        body { color: #172133; font-family: DejaVu Sans, sans-serif; font-size: 10px; line-height: 1.38; }
        h1 { margin: 0; color: #0a2f5a; font-size: 19px; }
        h2 { margin: 18px 0 7px; padding-bottom: 4px; border-bottom: 1px solid #dce3ec; color: #0a2f5a; font-size: 13px; }
        h3 { margin: 11px 0 4px; font-size: 11px; }
        p { margin: 3px 0; white-space: pre-line; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px; border: 1px solid #dce3ec; vertical-align: top; text-align: left; }
        th { background: #edf2f7; color: #0a2f5a; font-size: 9px; }
        .header { margin-bottom: 16px; padding-bottom: 12px; border-bottom: 3px solid #d2a62b; }
        .brand { color: #0a2f5a; font-size: 15px; font-weight: bold; }
        .brand span { color: #b98910; }
        .muted { color: #647085; }
        .status { display: inline-block; margin-top: 7px; padding: 3px 6px; background: #e8eff7; color: #0a2f5a; font-weight: bold; }
        .grid td { width: 50%; }
        .label { display: block; margin-bottom: 2px; color: #647085; font-size: 8px; text-transform: uppercase; }
        .page-break { page-break-before: always; }
        .notice { margin-top: 18px; padding: 8px; border-left: 3px solid #d2a62b; background: #fffaf0; color: #647085; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">Trilha<span>Gov</span></div>
        <h1>Plano de Trabalho Municipal</h1>
        <p class="muted">Emenda {{ $amendment->reference }} · exercício {{ $amendment->fiscal_year }} · {{ $amendment->municipality->name }}/SP</p>
        <span class="status">{{ $plan->statusLabel() }} · {{ $plan->revision_number > 0 ? 'Revisão '.$plan->revision_number : 'Rascunho' }}</span>
    </div>

    <h2>Identificação</h2>
    <table class="grid">
        <tr><td><span class="label">Autor</span>{{ $amendment->author_name }}{{ $amendment->author_party ? ' / '.$amendment->author_party : '' }}</td><td><span class="label">Valor previsto</span>R$ {{ number_format($amendment->expected_amount, 2, ',', '.') }}</td></tr>
        <tr><td><span class="label">Beneficiário</span>{{ $plan->beneficiary_name }} · {{ $beneficiaryTypes[$plan->beneficiary_type] ?? $plan->beneficiary_type }}</td><td><span class="label">CNPJ / contato</span>{{ $plan->beneficiary_cnpj ?: 'Não aplicável' }} · {{ $plan->beneficiary_contact }}</td></tr>
        <tr><td><span class="label">Programa</span>{{ $plan->budget_program }}</td><td><span class="label">Ação orçamentária</span>{{ $plan->budget_action }}</td></tr>
        <tr><td><span class="label">Período</span>{{ $plan->planned_start_at?->format('d/m/Y') }} a {{ $plan->planned_end_at?->format('d/m/Y') }}</td><td><span class="label">PCA</span>{{ $pcaStatuses[$plan->pca_status] ?? $plan->pca_status }}</td></tr>
    </table>

    <h2>Objeto e necessidade pública</h2>
    <h3>Objeto detalhado</h3><p>{{ $plan->object_description }}</p>
    <h3>Justificativa</h3><p>{{ $plan->public_need }}</p>

    <h2>Metas e aplicação</h2>
    <table class="grid">
        <tr><td><span class="label">Meta física</span>{{ $plan->physical_target }}</td><td><span class="label">Meta finalística</span>{{ $plan->finalistic_target }}</td></tr>
        <tr><td><span class="label">Plano de aplicação</span>{{ $plan->application_plan }}</td><td><span class="label">Memória de cálculo</span>{{ $plan->cost_memory }}</td></tr>
        <tr><td colspan="2"><span class="label">Operação e manutenção</span>{{ $plan->maintenance_plan }}</td></tr>
    </table>

    <h2>Condições técnicas</h2>
    <table class="grid">
        <tr><td><span class="label">Saúde</span>{{ $plan->health_related ? 'Aplicável' : 'Não aplicável' }}{{ $plan->health_related ? ($plan->health_reserve_verified ? ' · reserva verificada' : ' · reserva não confirmada') : '' }}</td><td><span class="label">Engenharia</span>{{ $plan->includes_engineering ? 'Aplicável' : 'Não aplicável' }}</td></tr>
        <tr><td><span class="label">Projeto</span>{{ $engineeringStatuses[$plan->engineering_project_status] ?? $plan->engineering_project_status }}</td><td><span class="label">Licença ambiental</span>{{ $engineeringStatuses[$plan->environmental_license_status] ?? $plan->environmental_license_status }}</td></tr>
    </table>

    <h2>Cronograma físico-financeiro</h2>
    <table>
        <thead><tr><th>#</th><th>Etapa</th><th>Entrega física</th><th>Período</th><th>Valor</th></tr></thead>
        <tbody>
            @foreach ($plan->stages as $stage)
                <tr><td>{{ $loop->iteration }}</td><td>{{ $stage->title }}</td><td>{{ $stage->physical_delivery }}</td><td>{{ $stage->planned_start_at->format('d/m/Y') }} a {{ $stage->planned_end_at->format('d/m/Y') }}</td><td>R$ {{ number_format($stage->planned_amount, 2, ',', '.') }}</td></tr>
            @endforeach
            <tr><td colspan="4"><strong>Total planejado</strong></td><td><strong>R$ {{ number_format($readiness['planned_amount'], 2, ',', '.') }}</strong></td></tr>
        </tbody>
    </table>

    @if ($plan->reviews->isNotEmpty())
        <div class="page-break"></div>
        <h1>Histórico de admissibilidade</h1>
        @foreach ($plan->reviews as $review)
            <h2>Revisão {{ $review->plan_revision }} · {{ $review->conclusionLabel() }}</h2>
            <p class="muted">{{ $review->reviewer->name }} · {{ $review->created_at->format('d/m/Y H:i') }}</p>
            <h3>Fundamentação</h3><p>{{ $review->rationale }}</p>
            @if ($review->corrections_requested)<h3>Ajustes solicitados</h3><p>{{ $review->corrections_requested }}</p>@endif
            <table>
                @foreach ($criteria as $code => $criterion)
                    <tr><td>{{ $criterion['label'] }}</td><td>{{ $criterionStatuses[$review->criteria[$code]] ?? $review->criteria[$code] }}</td></tr>
                @endforeach
            </table>
        @endforeach
    @endif

    <div class="notice">Documento operacional gerado pelo TrilhaGov. Não substitui assinatura, parecer jurídico, análise técnica formal ou validação do TCESP.</div>
</body>
</html>
