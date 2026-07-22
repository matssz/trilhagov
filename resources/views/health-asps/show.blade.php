@extends('layouts.app')

@section('title', 'Enquadramento ASPS · '.$amendment->reference.' | TrilhaGov')

@section('content')
    @php
        $model = $assessment;
        $criterionValues = old('criteria', $model?->criteria ?? []);
        $exclusionValues = old('exclusion_reasons', $model?->exclusion_reasons ?? []);
    @endphp
    <a class="back-link mb-3" href="{{ route('health-asps.index') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Saúde e LC 141</a>
    <div class="page-heading health-heading">
        <div><span class="page-kicker">{{ $amendment->reference }} · Exercício {{ $amendment->fiscal_year }}</span><h1>Enquadramento da despesa em ASPS</h1><p>{{ $amendment->object }}</p></div>
        <div class="health-heading-actions">@if($assessment)<span class="status-pill {{ $assessment->status === 'issued' ? ($assessment->conclusion === 'eligible' ? 'is-success' : 'is-danger') : 'is-warning' }}">{{ $assessment->status === 'issued' ? $assessment->conclusionLabel() : $assessment->statusLabel() }}</span>@endif @if($issuedAssessment)<a class="btn btn-primary" href="{{ route('health-asps.pdf', $issuedAssessment) }}"><i data-lucide="file-down" aria-hidden="true"></i>Parecer PDF</a>@endif</div>
    </div>

    <section class="health-amendment-band">
        <div><span>Autor</span><strong>{{ $amendment->author_name }}</strong></div><div><span>Órgão executor</span><strong>{{ $amendment->responsible_department }}</strong></div><div><span>Valor previsto</span><strong>R$ {{ number_format((float) $amendment->expected_amount, 2, ',', '.') }}</strong></div><div><span>Plano de trabalho</span><strong>{{ $amendment->municipalWorkPlan?->statusLabel() ?? 'Não iniciado' }}</strong></div>
    </section>

    @if ($errors->any())<div class="alert alert-danger app-alert" role="alert"><i data-lucide="circle-alert" aria-hidden="true"></i><span>{{ $errors->first() }}</span></div>@endif

    @if ($diagnostic)
        <section class="asps-diagnostic {{ $diagnostic['ready'] ? 'is-ready' : 'is-attention' }}">
            <div class="asps-diagnostic-title"><i data-lucide="{{ $diagnostic['ready'] ? 'circle-check-big' : 'triangle-alert' }}" aria-hidden="true"></i><span><strong>{{ $diagnostic['ready'] ? 'Critérios preparados para decisão' : count($diagnostic['blockers']).' bloqueio(s) no enquadramento' }}</strong><small>Diagnóstico automático · decisão final do responsável municipal</small></span></div>
            @if($diagnostic['blockers'])<ul>@foreach($diagnostic['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>@endif
            @if($diagnostic['warnings'])<div class="diagnostic-warnings">@foreach($diagnostic['warnings'] as $warning)<span><i data-lucide="info" aria-hidden="true"></i>{{ $warning }}</span>@endforeach</div>@endif
        </section>
    @endif

    @if ($canPrepare)
        <form method="POST" action="{{ route('health-asps.save', $amendment) }}" class="asps-form" data-prevent-double-submit>
            @csrf<input name="_submission_token" type="hidden" value="{{ $saveToken }}">
            <section class="content-panel">
                <div class="content-panel-header"><div><span class="step-index">1</span><h2 class="h5 mb-0">Classificação orçamentária</h2></div></div>
                <div class="content-panel-body asps-fields">
                    <label class="form-label span-2">Categoria ASPS<select class="form-select" name="asps_category"><option value="">Selecione</option>@foreach($categories as $value => $label)<option value="{{ $value }}" @selected(old('asps_category', $model?->asps_category) === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label class="form-label">Função<input class="form-control" name="budget_function" value="{{ old('budget_function', $model?->budget_function ?? $amendment->audespRegistration?->government_function ?? '10') }}" maxlength="2" inputmode="numeric"></label>
                    <label class="form-label">Subfunção<input class="form-control" name="budget_subfunction" value="{{ old('budget_subfunction', $model?->budget_subfunction ?? ($amendment->audespRegistration?->government_subfunctions[0] ?? '')) }}" maxlength="3" inputmode="numeric"></label>
                    <label class="form-label">Fonte de recursos<input class="form-control" name="funding_source_code" value="{{ old('funding_source_code', $model?->funding_source_code ?? $amendment->funding_source_code) }}" maxlength="100"></label>
                    <label class="form-label">Código de aplicação<input class="form-control" name="application_code" value="{{ old('application_code', $model?->application_code ?? $amendment->audespRegistration?->application_code ?? $amendment->application_code_fixed) }}" maxlength="100"></label>
                    <label class="form-label span-2">Referência do Fundo Municipal de Saúde<input class="form-control" name="health_fund_reference" value="{{ old('health_fund_reference', $model?->health_fund_reference) }}" maxlength="180"></label>
                    <label class="form-label span-2">Meta ou diretriz do Plano Municipal de Saúde<input class="form-control" name="health_plan_reference" value="{{ old('health_plan_reference', $model?->health_plan_reference) }}" maxlength="500"></label>
                </div>
            </section>

            <section class="content-panel">
                <div class="content-panel-header"><div><span class="step-index">2</span><h2 class="h5 mb-0">Critérios essenciais</h2></div><small>LC 141 · art. 2º</small></div>
                <div class="asps-check-grid">@foreach($criteria as $key => $label)<label class="asps-check"><input type="checkbox" name="criteria[{{ $key }}]" value="1" @checked((bool) ($criterionValues[$key] ?? false))><span><strong>{{ $label }}</strong><small>{{ $key === 'health_council_approval' ? 'Obrigatório nas hipóteses condicionadas ao Conselho' : 'Confirmação do responsável pelo enquadramento' }}</small></span></label>@endforeach</div>
            </section>

            <section class="content-panel">
                <div class="content-panel-header"><div><span class="step-index">3</span><h2 class="h5 mb-0">Hipóteses de exclusão</h2></div><small>LC 141 · art. 4º</small></div>
                <div class="asps-exclusion-grid">@foreach($exclusions as $key => $label)<label><input type="checkbox" name="exclusion_reasons[]" value="{{ $key }}" @checked(in_array($key, $exclusionValues, true))><span>{{ $label }}</span></label>@endforeach</div>
            </section>

            <section class="content-panel">
                <div class="content-panel-header"><div><span class="step-index">4</span><h2 class="h5 mb-0">Fundamentação e evidência</h2></div></div>
                <div class="content-panel-body asps-fields">
                    <label class="form-label span-2">Justificativa técnica<textarea class="form-control" name="technical_justification" rows="5" maxlength="5000">{{ old('technical_justification', $model?->technical_justification) }}</textarea></label>
                    <label class="form-label span-2">Documento principal<select class="form-select" name="evidence_document_id"><option value="">Sem documento vinculado</option>@foreach($documents as $document)<option value="{{ $document->id }}" @selected((int) old('evidence_document_id', $model?->evidence_document_id) === $document->id)>{{ $document->documentType->name }} · {{ $document->original_name }} · V{{ $document->version }}</option>@endforeach</select></label>
                </div>
            </section>
            <div class="asps-form-actions"><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar enquadramento</button></div>
        </form>
    @elseif ($assessment)
        <section class="content-panel asps-readonly">
            <div class="content-panel-header"><h2 class="h5 mb-0">Enquadramento registrado</h2><span>{{ $assessment->code() }}</span></div>
            <div class="content-panel-body"><dl><div><dt>Categoria</dt><dd>{{ $categories[$assessment->asps_category] ?? 'Não informada' }}</dd></div><div><dt>Classificação</dt><dd>Função {{ $assessment->budget_function ?: '—' }} · Subfunção {{ $assessment->budget_subfunction ?: '—' }}</dd></div><div><dt>Fonte</dt><dd>{{ $assessment->funding_source_code ?: 'Não informada' }}</dd></div><div><dt>Fundo de Saúde</dt><dd>{{ $assessment->health_fund_reference ?: 'Não informado' }}</dd></div><div class="span-2"><dt>Plano Municipal de Saúde</dt><dd>{{ $assessment->health_plan_reference ?: 'Não informado' }}</dd></div><div class="span-2"><dt>Justificativa</dt><dd>{{ $assessment->technical_justification ?: 'Não informada' }}</dd></div></dl></div>
        </section>
    @endif

    @if ($canSubmit)
        <section class="asps-submit-band"><div><i data-lucide="send" aria-hidden="true"></i><span><strong>Enviar para revisão</strong><small>Após o envio, os dados ficam bloqueados até a decisão.</small></span></div><form method="POST" action="{{ route('health-asps.submit', $assessment) }}" data-prevent-double-submit>@csrf<input name="_submission_token" type="hidden" value="{{ $submitToken }}"><button class="btn btn-primary" type="submit">Enviar parecer</button></form></section>
    @endif

    @if ($canReview)
        <section class="asps-review-panel" id="decisao"><div><span class="page-kicker">Controle Interno</span><h2>Decisão do enquadramento</h2><p>Revise critérios, classificação contábil, exclusões e evidência antes de emitir.</p></div><form method="POST" action="{{ route('health-asps.decision', $assessment) }}" data-prevent-double-submit>@csrf<input name="_submission_token" type="hidden" value="{{ $decisionToken }}"><label class="form-label">Fundamentação da decisão<textarea class="form-control" name="reviewer_notes" rows="4" required minlength="20" maxlength="4000"></textarea></label><div class="asps-decision-actions"><button class="btn btn-outline-secondary" name="action" value="return" type="submit"><i data-lucide="undo-2" aria-hidden="true"></i>Solicitar ajustes</button><button class="btn btn-outline-danger" name="action" value="ineligible" type="submit"><i data-lucide="circle-x" aria-hidden="true"></i>Não computável</button><button class="btn btn-primary" name="action" value="eligible" type="submit"><i data-lucide="badge-check" aria-hidden="true"></i>Computável como ASPS</button></div></form></section>
    @endif

    @if ($canRevise)
        <section class="asps-submit-band"><div><i data-lucide="copy-plus" aria-hidden="true"></i><span><strong>Nova análise necessária</strong><small>Abra outra versão sem modificar o parecer vigente.</small></span></div><form method="POST" action="{{ route('health-asps.revise', $assessment) }}" data-prevent-double-submit>@csrf<input name="_submission_token" type="hidden" value="{{ $reviseToken }}"><button class="btn btn-outline-primary" type="submit">Abrir nova versão</button></form></section>
    @endif

    @if ($history->isNotEmpty())
        <section class="content-panel mt-4"><div class="content-panel-header"><h2 class="h5 mb-0">Histórico de versões</h2><span class="record-count">{{ $history->count() }}</span></div><div class="table-responsive"><table class="table app-table align-middle mb-0"><thead><tr><th>Documento</th><th>Situação</th><th>Conclusão</th><th>Responsável</th><th>Data</th></tr></thead><tbody>@foreach($history as $item)<tr><td><strong>{{ $item->code() }}</strong></td><td>{{ $item->statusLabel() }}</td><td>{{ $item->conclusionLabel() }}</td><td>{{ $item->reviewer?->name ?? $item->creator->name }}</td><td>{{ ($item->reviewed_at ?? $item->updated_at)->format('d/m/Y H:i') }}</td></tr>@endforeach</tbody></table></div></section>
    @endif

    <div class="report-notice"><i data-lucide="info" aria-hidden="true"></i><div><strong>Responsabilidade municipal</strong><p>O parecer apoia o enquadramento da emenda e não substitui a escrituração contábil, o RREO, o SIOPS ou a manifestação dos órgãos competentes.</p></div></div>
@endsection
