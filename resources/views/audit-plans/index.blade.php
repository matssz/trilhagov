@extends('layouts.app')

@section('title', 'Plano Anual de Auditoria | TrilhaGov')

@section('content')
    @php($currentYear = now()->year)
    <div class="audit-plan-heading mb-4">
        <div>
            <p class="page-kicker mb-2">Controle Interno municipal · planejamento baseado em risco</p>
            <h1 class="h3 mb-1">Plano Anual de Auditoria</h1>
            <p class="text-secondary mb-0">Organize as verificações das emendas, distribua responsabilidades e preserve a execução anual.</p>
        </div>
        <a class="btn btn-outline-primary" href="https://tce.sp.gov.br/legislacao/comunicado/emendas-parlamentares-locais-providencias-para-aprimoramento-governanca" target="_blank" rel="noopener noreferrer"><i data-lucide="external-link" aria-hidden="true"></i>Comunicado GP 15/2026</a>
    </div>

    <x-validation-summary />

    <section class="audit-plan-basis mb-4" role="note">
        <span><i data-lucide="calendar-check-2" aria-hidden="true"></i></span>
        <div><strong>Planejamento anual exigível e verificável</strong><p>O Controle Interno deve prever no plano anual a análise do plano de trabalho, compatibilidade orçamentária, contratações e conflitos de interesse.</p></div>
        <small>GP 15/2026 · inciso XVI</small>
    </section>

    @if($canManage)
        <section class="content-panel mb-4" id="novo-plano">
            <div class="content-panel-header audit-plan-panel-header"><div><p class="page-kicker mb-1">Novo ciclo</p><h2 class="h5 mb-0">Criar minuta anual</h2></div><span class="small text-secondary">A versão só se torna imutável após a emissão</span></div>
            <form class="audit-plan-form" method="POST" action="{{ route('audit-plans.store') }}">
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $createToken }}">
                <div><label class="form-label" for="fiscal_year">Exercício <span class="required-mark">*</span></label><input class="form-control @error('fiscal_year') is-invalid @enderror" id="fiscal_year" name="fiscal_year" type="number" min="{{ $currentYear - 1 }}" max="{{ $currentYear + 2 }}" value="{{ old('fiscal_year', $currentYear) }}" required>@error('fiscal_year')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-2"><label class="form-label" for="title">Título <span class="required-mark">*</span></label><input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', 'Plano Anual de Auditoria das Emendas Parlamentares Municipais') }}" minlength="5" maxlength="220" required>@error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-3"><label class="form-label" for="objective">Objetivo <span class="required-mark">*</span></label><textarea class="form-control @error('objective') is-invalid @enderror" id="objective" name="objective" rows="3" minlength="20" maxlength="5000" required>{{ old('objective', 'Avaliar preventivamente e de forma concomitante a regularidade, a transparência e os resultados das emendas parlamentares executadas pelo Município.') }}</textarea>@error('objective')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-3"><label class="form-label" for="methodology">Metodologia <span class="required-mark">*</span></label><textarea class="form-control @error('methodology') is-invalid @enderror" id="methodology" name="methodology" rows="3" minlength="20" maxlength="5000" required>{{ old('methodology', 'Verificações prévias, concomitantes e finais, com seleção por risco, materialidade e criticidade; registro de evidências, achados, responsáveis e providências.') }}</textarea>@error('methodology')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-3"><label class="form-label" for="risk_criteria">Critérios de risco e seleção <span class="required-mark">*</span></label><textarea class="form-control @error('risk_criteria') is-invalid @enderror" id="risk_criteria" name="risk_criteria" rows="3" minlength="20" maxlength="5000" required>{{ old('risk_criteria', 'Priorizar emendas sem parecer, com alertas ativos, maior materialidade, risco cadastral elevado ou execução financeira iniciada.') }}</textarea>@error('risk_criteria')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-2"><label class="form-label" for="normative_basis">Base normativa <span class="required-mark">*</span></label><textarea class="form-control @error('normative_basis') is-invalid @enderror" id="normative_basis" name="normative_basis" rows="2" minlength="10" maxlength="3000" required>{{ old('normative_basis', 'Comunicado GP nº 15/2026, inciso XVI; Manual de Emendas Parlamentares Impositivas Municipais do TCESP, item 7.3; legislação e normas locais aplicáveis.') }}</textarea>@error('normative_basis')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="coordination_unit">Unidade coordenadora <span class="required-mark">*</span></label><input class="form-control @error('coordination_unit') is-invalid @enderror" id="coordination_unit" name="coordination_unit" value="{{ old('coordination_unit', 'Unidade Central de Controle Interno') }}" minlength="3" maxlength="180" required>@error('coordination_unit')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="planned_start_at">Início <span class="required-mark">*</span></label><input class="form-control @error('planned_start_at') is-invalid @enderror" id="planned_start_at" name="planned_start_at" type="date" value="{{ old('planned_start_at', $currentYear.'-01-01') }}" required>@error('planned_start_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="planned_end_at">Encerramento <span class="required-mark">*</span></label><input class="form-control @error('planned_end_at') is-invalid @enderror" id="planned_end_at" name="planned_end_at" type="date" value="{{ old('planned_end_at', $currentYear.'-12-31') }}" required>@error('planned_end_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="management_notes">Observação gerencial</label><input class="form-control @error('management_notes') is-invalid @enderror" id="management_notes" name="management_notes" value="{{ old('management_notes') }}" maxlength="5000">@error('management_notes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-3 audit-plan-submit"><div><i data-lucide="shield-check" aria-hidden="true"></i><span><strong>Minuta controlada</strong><small>Depois você selecionará as emendas e os responsáveis.</small></span></div><button class="btn btn-primary" type="submit"><i data-lucide="file-plus-2" aria-hidden="true"></i>Criar minuta</button></div>
            </form>
        </section>
    @endif

    <section class="content-panel">
        <div class="content-panel-header audit-plan-panel-header"><div><p class="page-kicker mb-1">Histórico municipal</p><h2 class="h5 mb-0">Planos e versões</h2></div><span class="small text-secondary">{{ $plans->count() }} registro(s)</span></div>
        @if($plans->isEmpty())
            <div class="empty-state">Nenhum Plano Anual de Auditoria foi criado para este Município.</div>
        @else
            <div class="audit-plan-list">
                @foreach($plans as $plan)
                    @php($coverage = $plan->items_count ? round(($plan->completed_items_count / $plan->items_count) * 100) : 0)
                    <article>
                        <div class="audit-plan-year"><small>Exercício</small><strong>{{ $plan->fiscal_year }}</strong><span>Versão {{ $plan->version }}</span></div>
                        <div class="audit-plan-list-main"><div><strong>{{ $plan->reference() }}</strong><span class="audit-plan-status status-{{ $plan->status }}">{{ $plan->statusLabel() }}</span></div><h3>{{ $plan->title }}</h3><small>{{ $plan->coordination_unit }} · {{ $plan->planned_start_at->format('d/m/Y') }} a {{ $plan->planned_end_at->format('d/m/Y') }}</small></div>
                        <div class="audit-plan-list-progress"><div><span style="width: {{ $coverage }}%"></span></div><strong>{{ $coverage }}%</strong><small>{{ $plan->completed_items_count }}/{{ $plan->items_count }} concluídos @if($plan->overdue_items_count)· {{ $plan->overdue_items_count }} vencido(s)@endif</small></div>
                        <a class="icon-button" href="{{ route('audit-plans.show', $plan) }}" title="Abrir plano" aria-label="Abrir plano"><i data-lucide="arrow-right" aria-hidden="true"></i></a>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
