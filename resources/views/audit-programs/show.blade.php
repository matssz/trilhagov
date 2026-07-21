@extends('layouts.app')

@section('title', $program->reference().' · Programa de Auditoria · TrilhaGov')

@section('content')
    @php
        $executedCount = $program->procedures->where('status', '!=', 'planned')->count();
        $evidenceCount = $program->procedures->sum(fn ($procedure) => $procedure->evidences->count());
        $samplingLabel = $samplingMethods[$program->sampling_method] ?? $program->sampling_method;
    @endphp

    <div class="audit-program-heading mb-4">
        <div>
            <a class="back-link" href="{{ route('audit-plans.show', $program->planItem->plan) }}#item-{{ $program->planItem->id }}"><i data-lucide="arrow-left" aria-hidden="true"></i>{{ $program->planItem->plan->reference() }}</a>
            <p class="page-kicker mt-3 mb-1">{{ $program->reference() }} · {{ $program->planItem->formalReference() }}</p>
            <h1>{{ $program->title }}</h1>
            <p>{{ $program->planItem->amendment->reference }} · {{ $program->planItem->amendment->object }}</p>
        </div>
        <div class="audit-program-heading-actions">
            <span class="audit-program-status status-{{ $program->status }}">{{ $program->statusLabel() }}</span>
            <a class="btn btn-outline-primary" href="{{ route('audit-programs.pdf', $program) }}"><i data-lucide="file-down" aria-hidden="true"></i>PDF</a>
        </div>
    </div>

    @if($errors->any())
        <div class="alert alert-danger app-alert" role="alert"><i data-lucide="circle-alert" aria-hidden="true"></i><div><strong>Revise os campos informados.</strong><ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
    @endif

    <section class="audit-program-flow mb-4" aria-label="Andamento do programa">
        <div class="is-complete"><span><i data-lucide="crosshair" aria-hidden="true"></i></span><small>Estratégia</small></div>
        <div class="{{ $executedCount === $program->procedures->count() && $executedCount > 0 ? 'is-complete' : 'is-current' }}"><span><i data-lucide="list-checks" aria-hidden="true"></i></span><small>Testes</small></div>
        <div class="{{ in_array($program->status, ['approved', 'concluded']) ? 'is-complete' : ($program->status === 'under_review' ? 'is-current' : '') }}"><span><i data-lucide="user-check" aria-hidden="true"></i></span><small>Revisão</small></div>
        <div class="{{ $program->status === 'concluded' ? 'is-complete' : '' }}"><span><i data-lucide="badge-check" aria-hidden="true"></i></span><small>Conclusão</small></div>
    </section>

    <div class="audit-program-metrics mb-4">
        <article><span><i data-lucide="database" aria-hidden="true"></i></span><div><small>População / amostra</small><strong>{{ $program->population_size ?? '—' }} / {{ $program->sample_size ?? '—' }}</strong><p>{{ $samplingLabel }}</p></div></article>
        <article><span><i data-lucide="clipboard-check" aria-hidden="true"></i></span><div><small>Procedimentos</small><strong>{{ $executedCount }} de {{ $program->procedures->count() }}</strong><p>testes executados</p></div></article>
        <article><span><i data-lucide="paperclip" aria-hidden="true"></i></span><div><small>Evidências</small><strong>{{ $evidenceCount }}</strong><p>arquivos preservados</p></div></article>
        <article class="{{ $program->findings->isNotEmpty() ? 'has-attention' : '' }}"><span><i data-lucide="search-check" aria-hidden="true"></i></span><div><small>Achados</small><strong>{{ $program->findings->count() }}</strong><p>recomendações emitidas</p></div></article>
    </div>

    <section class="content-panel mb-4" id="estrategia">
        <div class="content-panel-header audit-program-panel-header">
            <div><p class="page-kicker mb-1">Planejamento do trabalho</p><h2 class="h5 mb-0">Estratégia, equipe e amostra</h2></div>
            <span>{{ $program->start_at->format('d/m/Y') }} a {{ $program->due_at->format('d/m/Y') }}</span>
        </div>
        @if($canEdit)
            <form class="audit-program-strategy" method="POST" action="{{ route('audit-programs.update', $program) }}">
                @csrf @method('PATCH')
                <input name="_submission_token" type="hidden" value="{{ $updateToken }}">
                <label class="span-2"><span class="form-label">Título <span class="required-mark">*</span></span><input class="form-control" name="title" value="{{ old('title', $program->title) }}" required maxlength="220"></label>
                <label><span class="form-label">Auditor líder <span class="required-mark">*</span></span><select class="form-select" name="lead_auditor_id" required>@foreach($auditors as $auditor)<option value="{{ $auditor->id }}" @selected((int) old('lead_auditor_id', $program->lead_auditor_id) === $auditor->id)>{{ $auditor->name }}</option>@endforeach</select></label>
                <label><span class="form-label">Supervisor <span class="required-mark">*</span></span><select class="form-select" name="supervisor_id" required>@foreach($auditors as $auditor)<option value="{{ $auditor->id }}" @selected((int) old('supervisor_id', $program->supervisor_id) === $auditor->id)>{{ $auditor->name }}</option>@endforeach</select></label>
                <label class="span-2"><span class="form-label">Objetivo <span class="required-mark">*</span></span><textarea class="form-control" name="objective" rows="3" required>{{ old('objective', $program->objective) }}</textarea></label>
                <label class="span-2"><span class="form-label">Escopo <span class="required-mark">*</span></span><textarea class="form-control" name="scope" rows="3" required>{{ old('scope', $program->scope) }}</textarea></label>
                <label><span class="form-label">Método de amostragem <span class="required-mark">*</span></span><select class="form-select" name="sampling_method" required>@foreach($samplingMethods as $value => $label)<option value="{{ $value }}" @selected(old('sampling_method', $program->sampling_method) === $value)>{{ $label }}</option>@endforeach</select></label>
                <label><span class="form-label">População <span class="required-mark">*</span></span><input class="form-control" name="population_size" type="number" min="1" value="{{ old('population_size', $program->population_size) }}" required></label>
                <label><span class="form-label">Amostra <span class="required-mark">*</span></span><input class="form-control" name="sample_size" type="number" min="1" value="{{ old('sample_size', $program->sample_size) }}" required></label>
                <label><span class="form-label">Início <span class="required-mark">*</span></span><input class="form-control" name="start_at" type="date" value="{{ old('start_at', $program->start_at->format('Y-m-d')) }}" required></label>
                <label><span class="form-label">Prazo <span class="required-mark">*</span></span><input class="form-control" name="due_at" type="date" value="{{ old('due_at', $program->due_at->format('Y-m-d')) }}" required></label>
                <label class="span-2"><span class="form-label">Descrição da população <span class="required-mark">*</span></span><textarea class="form-control" name="population_description" rows="2" required>{{ old('population_description', $program->population_description) }}</textarea></label>
                <label class="span-2"><span class="form-label">Materialidade e seleção <span class="required-mark">*</span></span><textarea class="form-control" name="materiality_criteria" rows="2" required>{{ old('materiality_criteria', $program->materiality_criteria) }}</textarea></label>
                <div class="span-full"><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar estratégia</button></div>
            </form>
        @else
            <dl class="audit-program-summary">
                <div><dt>Auditor líder</dt><dd>{{ $program->leadAuditor->name }}</dd></div><div><dt>Supervisor</dt><dd>{{ $program->supervisor->name }}</dd></div>
                <div><dt>Objetivo</dt><dd>{{ $program->objective }}</dd></div><div><dt>Escopo</dt><dd>{{ $program->scope }}</dd></div>
                <div><dt>População</dt><dd>{{ $program->population_description }}</dd></div><div><dt>Materialidade</dt><dd>{{ $program->materiality_criteria }}</dd></div>
            </dl>
        @endif
    </section>

    <section class="content-panel mb-4" id="procedimentos">
        <div class="content-panel-header audit-program-panel-header"><div><p class="page-kicker mb-1">Papéis de trabalho</p><h2 class="h5 mb-0">Procedimentos e testes</h2></div><span>{{ $executedCount }}/{{ $program->procedures->count() }} executados</span></div>
        @if($canEdit)
            <form class="audit-procedure-create" method="POST" action="{{ route('audit-procedures.store', $program) }}">
                @csrf <input name="_submission_token" type="hidden" value="{{ $procedureToken }}">
                <label><span class="form-label">Procedimento <span class="required-mark">*</span></span><input class="form-control" name="title" required maxlength="220"></label>
                <label><span class="form-label">Objetivo do teste <span class="required-mark">*</span></span><textarea class="form-control" name="objective" rows="2" required></textarea></label>
                <label><span class="form-label">Método e passos <span class="required-mark">*</span></span><textarea class="form-control" name="test_method" rows="2" required></textarea></label>
                <label><span class="form-label">Itens da amostra <span class="required-mark">*</span></span><textarea class="form-control" name="sample_description" rows="2" required></textarea></label>
                <label><span class="form-label">Evidência esperada <span class="required-mark">*</span></span><textarea class="form-control" name="expected_evidence" rows="2" required></textarea></label>
                <button class="btn btn-primary" type="submit"><i data-lucide="list-plus" aria-hidden="true"></i>Adicionar procedimento</button>
            </form>
        @endif
        @if($program->procedures->isEmpty())
            <div class="empty-state">Nenhum procedimento registrado.</div>
        @else
            <div class="audit-procedure-list">
                @foreach($program->procedures as $procedure)
                    <article class="status-{{ $procedure->status }}">
                        <div class="audit-procedure-code">P{{ str_pad($procedure->sequence, 2, '0', STR_PAD_LEFT) }}</div>
                        <div class="audit-procedure-copy">
                            <div><span class="audit-procedure-status status-{{ $procedure->status }}">{{ $procedure->statusLabel() }}</span><h3>{{ $procedure->title }}</h3></div>
                            <p><strong>Objetivo:</strong> {{ $procedure->objective }}</p>
                            <dl><div><dt>Método</dt><dd>{{ $procedure->test_method }}</dd></div><div><dt>Amostra testada</dt><dd>{{ $procedure->sample_description }}</dd></div><div><dt>Evidência esperada</dt><dd>{{ $procedure->expected_evidence }}</dd></div>@if($procedure->result)<div class="span-full"><dt>Resultado</dt><dd>{{ $procedure->result }}</dd></div>@endif</dl>
                            @if($procedure->executed_at)<small>Executado por {{ $procedure->executor?->name }} em {{ $procedure->executed_at->format('d/m/Y H:i') }}</small>@endif
                            @if($procedure->evidences->isNotEmpty())
                                <div class="audit-evidence-list">@foreach($procedure->evidences as $evidence)<a href="{{ route('audit-program-evidences.download', $evidence) }}"><i data-lucide="paperclip" aria-hidden="true"></i><span>{{ $evidence->description }}<small>{{ $evidence->original_name }} · {{ Str::limit($evidence->sha256, 14) }}</small></span></a>@endforeach</div>
                            @endif
                            @if($canEdit)
                                <div class="audit-procedure-forms">
                                    <details><summary><i data-lucide="clipboard-pen" aria-hidden="true"></i>Registrar teste</summary><form method="POST" action="{{ route('audit-procedures.update', $procedure) }}">@csrf @method('PATCH')<input name="_submission_token" type="hidden" value="{{ $procedureUpdateTokens[$procedure->id] }}"><input name="title" type="hidden" value="{{ $procedure->title }}"><input name="objective" type="hidden" value="{{ $procedure->objective }}"><input name="test_method" type="hidden" value="{{ $procedure->test_method }}"><input name="sample_description" type="hidden" value="{{ $procedure->sample_description }}"><input name="expected_evidence" type="hidden" value="{{ $procedure->expected_evidence }}"><label><span class="form-label">Resultado <span class="required-mark">*</span></span><select class="form-select" name="status" required>@foreach($procedureStatuses as $value => $label)<option value="{{ $value }}" @selected($procedure->status === $value)>{{ $label }}</option>@endforeach</select></label><label class="span-2"><span class="form-label">Teste realizado e conclusão <span class="required-mark">*</span></span><textarea class="form-control" name="result" rows="3">{{ $procedure->result }}</textarea></label><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar teste</button></form></details>
                                    <details><summary><i data-lucide="paperclip" aria-hidden="true"></i>Anexar evidência</summary><form method="POST" action="{{ route('audit-program-evidences.store', $procedure) }}" enctype="multipart/form-data">@csrf<input name="_submission_token" type="hidden" value="{{ $evidenceTokens[$procedure->id] }}"><label><span class="form-label">Identificação <span class="required-mark">*</span></span><input class="form-control" name="description" required maxlength="500"></label><label><span class="form-label">Arquivo <span class="required-mark">*</span></span><input class="form-control" name="evidence" type="file" accept=".pdf,.jpg,.jpeg,.png,.csv,.xlsx" required></label><button class="btn btn-primary" type="submit"><i data-lucide="upload" aria-hidden="true"></i>Preservar evidência</button></form></details>
                                    @if($procedure->status === 'planned' && $procedure->evidences->isEmpty() && $procedure->findings->isEmpty())<form method="POST" action="{{ route('audit-procedures.destroy', $procedure) }}" onsubmit="return confirm('Remover este procedimento não executado?')">@csrf @method('DELETE')<input name="_submission_token" type="hidden" value="{{ $procedureDeleteTokens[$procedure->id] }}"><button class="btn btn-sm btn-outline-danger" type="submit"><i data-lucide="trash-2" aria-hidden="true"></i>Remover</button></form>@endif
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="content-panel mb-4" id="achados">
        <div class="content-panel-header audit-program-panel-header"><div><p class="page-kicker mb-1">Resultado da auditoria</p><h2 class="h5 mb-0">Achados e recomendações</h2></div><span>{{ $program->findings->count() }} registro(s)</span></div>
        @if($canEdit)
            <form class="audit-finding-create" method="POST" action="{{ route('audit-findings.store', $program) }}">
                @csrf <input name="_submission_token" type="hidden" value="{{ $findingToken }}">
                <label><span class="form-label">Procedimento relacionado</span><select class="form-select" name="municipal_audit_procedure_id"><option value="">Achado geral</option>@foreach($program->procedures as $procedure)<option value="{{ $procedure->id }}">P{{ str_pad($procedure->sequence, 2, '0', STR_PAD_LEFT) }} · {{ $procedure->title }}</option>@endforeach</select></label>
                <label><span class="form-label">Gravidade <span class="required-mark">*</span></span><select class="form-select" name="severity" required>@foreach($severities as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <label class="span-2"><span class="form-label">Título <span class="required-mark">*</span></span><input class="form-control" name="title" required maxlength="220"></label>
                <label><span class="form-label">Critério <span class="required-mark">*</span></span><textarea class="form-control" name="criteria" rows="2" required></textarea></label>
                <label><span class="form-label">Condição encontrada <span class="required-mark">*</span></span><textarea class="form-control" name="condition" rows="2" required></textarea></label>
                <label><span class="form-label">Causa</span><textarea class="form-control" name="cause" rows="2"></textarea></label>
                <label><span class="form-label">Efeito ou risco</span><textarea class="form-control" name="effect" rows="2"></textarea></label>
                <label class="span-2"><span class="form-label">Recomendação <span class="required-mark">*</span></span><textarea class="form-control" name="recommendation" rows="2" required></textarea></label>
                <label><span class="form-label">Prazo recomendado</span><input class="form-control" name="recommended_due_at" type="date"></label>
                <button class="btn btn-primary" type="submit"><i data-lucide="search-plus" aria-hidden="true"></i>Registrar achado</button>
            </form>
        @endif
        @if($program->findings->isEmpty())
            <div class="empty-state">Nenhum achado registrado.</div>
        @else
            <div class="audit-finding-list">
                @foreach($program->findings as $finding)
                    <article class="severity-{{ $finding->severity }}"><span class="audit-finding-severity">{{ $finding->severityLabel() }}</span><div><small>{{ $finding->procedure ? 'P'.str_pad($finding->procedure->sequence, 2, '0', STR_PAD_LEFT) : 'Achado geral' }}</small><h3>{{ $finding->title }}</h3><dl><div><dt>Critério</dt><dd>{{ $finding->criteria }}</dd></div><div><dt>Condição</dt><dd>{{ $finding->condition }}</dd></div>@if($finding->cause)<div><dt>Causa</dt><dd>{{ $finding->cause }}</dd></div>@endif @if($finding->effect)<div><dt>Efeito</dt><dd>{{ $finding->effect }}</dd></div>@endif<div class="span-full"><dt>Recomendação</dt><dd>{{ $finding->recommendation }}</dd></div></dl>@if($finding->recommended_due_at)<small>Prazo recomendado: {{ $finding->recommended_due_at->format('d/m/Y') }}</small>@endif</div>@if($canEdit)<form method="POST" action="{{ route('audit-findings.destroy', $finding) }}" onsubmit="return confirm('Remover este achado antes da revisão?')">@csrf @method('DELETE')<input name="_submission_token" type="hidden" value="{{ $findingDeleteTokens[$finding->id] }}"><button class="icon-button is-danger" type="submit" title="Remover achado" aria-label="Remover achado"><i data-lucide="trash-2" aria-hidden="true"></i></button></form>@endif</article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="audit-program-review mb-4 status-{{ $program->status }}">
        <div class="audit-program-review-copy"><span><i data-lucide="shield-check" aria-hidden="true"></i></span><div><p class="page-kicker mb-1">Supervisão e encerramento</p><h2>Revisão independente</h2><p><strong>{{ $program->supervisor->name }}</strong> · supervisor designado</p></div></div>
        @if($program->supervisor_notes)<div class="audit-supervisor-note"><strong>Revisão do supervisor</strong><p>{{ $program->supervisor_notes }}</p></div>@endif
        @if($canEdit)
            @if($blockers)<ul class="audit-program-blockers">@foreach($blockers as $blocker)<li><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $blocker }}</li>@endforeach</ul>@endif
            <form class="audit-program-submit" method="POST" action="{{ route('audit-programs.submit', $program) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $submitToken }}"><label><input name="confirm_workpapers" type="checkbox" value="1" required>Confirmo a vinculação dos testes, evidências e achados.</label><button class="btn btn-primary" type="submit" @disabled($blockers)><i data-lucide="send" aria-hidden="true"></i>Enviar para revisão</button></form>
        @elseif($program->status === 'under_review' && $isSupervisor)
            <form class="audit-program-decision" method="POST" action="{{ route('audit-programs.review', $program) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $reviewToken }}"><label><span class="form-label">Decisão <span class="required-mark">*</span></span><select class="form-select" name="decision" required><option value="approved">Aprovar papéis de trabalho</option><option value="returned">Devolver para ajustes</option></select></label><label class="span-2"><span class="form-label">Nota de revisão <span class="required-mark">*</span></span><textarea class="form-control" name="supervisor_notes" rows="3" required minlength="10"></textarea></label><button class="btn btn-primary" type="submit"><i data-lucide="stamp" aria-hidden="true"></i>Registrar decisão</button></form>
        @elseif($program->status === 'approved' && $isSupervisor)
            <form class="audit-program-conclusion" method="POST" action="{{ route('audit-programs.conclude', $program) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $concludeToken }}"><label><span class="form-label">Conclusão formal <span class="required-mark">*</span></span><textarea class="form-control" name="conclusion" rows="4" required minlength="20"></textarea></label><label><input name="confirm_conclusion" type="checkbox" value="1" required>Confirmo o encerramento e a preservação dos papéis de trabalho.</label><button class="btn btn-primary" type="submit"><i data-lucide="badge-check" aria-hidden="true"></i>Concluir programa</button></form>
        @elseif($program->status === 'concluded')
            <div class="audit-program-final"><strong>Conclusão formal</strong><p>{{ $program->conclusion }}</p><code>SHA-256 {{ $program->snapshot_sha256 }}</code><small>Encerrado por {{ $program->concludedBy?->name }} em {{ $program->concluded_at?->format('d/m/Y H:i') }}</small></div>
        @endif
    </section>

    @if($program->events->isNotEmpty())
        <details class="audit-program-history"><summary><i data-lucide="history" aria-hidden="true"></i>Histórico do programa ({{ $program->events->count() }})</summary><div>@foreach($program->events as $event)<p><strong>{{ $event->actor_name }}</strong><span>{{ $event->description }}</span><time>{{ $event->created_at->format('d/m/Y H:i') }}</time></p>@endforeach</div></details>
    @endif
@endsection
