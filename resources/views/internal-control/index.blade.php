@extends('layouts.app')

@section('title', 'Controle Interno · '.$amendment->reference.' | TrilhaGov')

@section('content')
    @php
        $allActions = $reviews->flatMap->actions;
        $openActions = $allActions->whereNotIn('status', [\App\Models\MunicipalInternalControlAction::STATUS_RESOLVED]);
        $latest = $reviews->first();
    @endphp

    <div class="internal-control-heading mb-4">
        <div>
            <a class="back-link" href="{{ route('emendas.show', $amendment) }}"><i data-lucide="arrow-left" aria-hidden="true"></i>{{ $amendment->reference }}</a>
            <p class="page-kicker mt-3 mb-2">{{ $municipality->name }}/{{ $municipality->state }} · primeira linha de defesa municipal</p>
            <h1 class="h3 mb-1">Parecer do Controle Interno</h1>
            <p class="text-secondary mb-0">Verificação padronizada, providências e evidências da aplicação dos recursos.</p>
        </div>
        @if($latest)
            <a class="btn btn-primary" href="{{ route('internal-control-reviews.pdf', $latest) }}"><i data-lucide="file-down" aria-hidden="true"></i>Último parecer</a>
        @endif
    </div>

    <nav class="amendment-tabs mb-4" aria-label="Seções da emenda">
        <a href="{{ route('emendas.show', $amendment) }}">Visão geral</a>
        <a href="{{ route('emendas.work-plan', $amendment) }}">Plano de trabalho</a>
        <a href="{{ route('emendas.impediments', $amendment) }}">Impedimentos</a>
        <a href="{{ route('emendas.execution', $amendment) }}">Execução</a>
        <a href="{{ route('emendas.audesp', $amendment) }}">Audesp</a>
        <a href="{{ route('emendas.compliance', $amendment) }}">Conformidade TCESP</a>
        <a class="active" href="{{ route('emendas.internal-control', $amendment) }}" aria-current="page">Controle Interno</a>
        <a href="{{ route('emendas.accountability', $amendment) }}">Prestação de contas</a>
    </nav>

    <x-validation-summary />

    <section class="internal-control-banner mb-4">
        <div class="internal-control-banner-mark"><i data-lucide="shield-check" aria-hidden="true"></i></div>
        <div>
            <p class="page-kicker mb-1">Comunicado GP 15/2026 · Manual TCESP, item 7.3</p>
            <h2 class="h5 mb-1">Controle preventivo e concomitante</h2>
            <p>O parecer registra o que foi verificado. Havendo ressalva, o sistema abre uma providência com responsável, prazo e validação independente.</p>
        </div>
        <a href="{{ \App\Services\MunicipalInternalControlService::SOURCE_URL }}" target="_blank" rel="noopener noreferrer">Consultar manual<i data-lucide="external-link" aria-hidden="true"></i></a>
    </section>

    <div class="internal-control-metrics mb-4">
        <article><small>Pareceres emitidos</small><strong>{{ $reviews->count() }}</strong><span>{{ $reviews->groupBy('phase')->count() }} fase(s) analisada(s)</span></article>
        <article><small>Providências ativas</small><strong>{{ $openActions->count() }}</strong><span>{{ $openActions->where('status', 'responded')->count() }} aguardando validação</span></article>
        <article class="{{ $openActions->filter->isOverdue()->isNotEmpty() ? 'has-risk' : '' }}"><small>Prazos vencidos</small><strong>{{ $openActions->filter->isOverdue()->count() }}</strong><span>{{ $allActions->where('status', 'resolved')->count() }} saneada(s)</span></article>
        <article><small>Última conclusão</small><strong class="metric-text">{{ $latest?->conclusionLabel() ?? 'Sem parecer' }}</strong><span>{{ $latest?->issued_at?->format('d/m/Y H:i') ?? 'Aguardando análise' }}</span></article>
    </div>

    @if($canIssue)
        <section class="content-panel mb-4" id="emitir-parecer">
            <div class="content-panel-header internal-control-panel-header">
                <div><p class="page-kicker mb-1">Nova verificação formal</p><h2 class="h5 mb-0">Emitir parecer imutável</h2></div>
                <span class="small text-secondary">Todos os itens devem ser avaliados</span>
            </div>
            <form class="internal-control-form" method="POST" action="{{ route('internal-control-reviews.store', $amendment) }}" enctype="multipart/form-data">
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $issueToken }}">

                <div class="internal-control-form-grid">
                    <div><label class="form-label" for="phase">Fase da análise <span class="required-mark">*</span></label><select class="form-select @error('phase') is-invalid @enderror" id="phase" name="phase" required>@foreach(\App\Models\MunicipalInternalControlReview::phases() as $value => $label)<option value="{{ $value }}" @selected(old('phase', 'concomitant') === $value)>{{ $label }}</option>@endforeach</select>@error('phase')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="annual_audit_plan_reference">Plano Anual de Auditoria <span class="required-mark">*</span></label><input class="form-control @error('annual_audit_plan_reference') is-invalid @enderror" id="annual_audit_plan_reference" name="annual_audit_plan_reference" value="{{ old('annual_audit_plan_reference') }}" placeholder="Ex.: PAA 2026, item 4.2" maxlength="255" required>@error('annual_audit_plan_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="municipal_governance_report_id">Relatório mensal relacionado</label><select class="form-select @error('municipal_governance_report_id') is-invalid @enderror" id="municipal_governance_report_id" name="municipal_governance_report_id"><option value="">Sem vínculo mensal</option>@foreach($governanceReports as $report)<option value="{{ $report->id }}" @selected((string) old('municipal_governance_report_id') === (string) $report->id)>{{ $report->code() }} · {{ $report->periodLabel() }}</option>@endforeach</select>@error('municipal_governance_report_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="evidence">Parecer assinado externamente</label><input class="form-control @error('evidence') is-invalid @enderror" id="evidence" name="evidence" type="file" accept=".pdf"><small class="form-hint">PDF opcional, até 10 MB. O TrilhaGov validará o hash do arquivo.</small>@error('evidence')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="span-2"><label class="form-label" for="legal_basis">Fundamentação normativa <span class="required-mark">*</span></label><textarea class="form-control @error('legal_basis') is-invalid @enderror" id="legal_basis" name="legal_basis" rows="2" maxlength="2000" required>{{ old('legal_basis', 'Comunicado GP nº 15/2026, incisos XIV a XVI; Manual de Emendas Parlamentares Impositivas Municipais do TCESP, item 7.3; normas locais aplicáveis.') }}</textarea>@error('legal_basis')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                </div>

                <div class="internal-control-checklist">
                    <div class="internal-control-checklist-heading"><div><p class="page-kicker mb-1">Matriz padronizada</p><h3 class="h6 mb-0">Verificações mínimas do Controle Interno</h3></div><span>8 itens</span></div>
                    @foreach($criteria as $code => $criterion)
                        <fieldset class="internal-control-criterion">
                            <legend><span>{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><strong>{{ $criterion['label'] }}</strong></legend>
                            <p>{{ $criterion['guidance'] }}</p>
                            <small>{{ $criterion['source'] }}</small>
                            <div class="internal-control-criterion-fields">
                                <div><label class="form-label" for="criteria_{{ $code }}_status">Resultado <span class="required-mark">*</span></label><select class="form-select @error('criteria.'.$code.'.status') is-invalid @enderror" id="criteria_{{ $code }}_status" name="criteria[{{ $code }}][status]" required>@foreach($criterionStatuses as $value => $label)<option value="{{ $value }}" @selected(old("criteria.{$code}.status", 'compliant') === $value)>{{ $label }}</option>@endforeach</select>@error('criteria.'.$code.'.status')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                                <div><label class="form-label" for="criteria_{{ $code }}_notes">Evidência ou constatação</label><input class="form-control @error('criteria.'.$code.'.notes') is-invalid @enderror" id="criteria_{{ $code }}_notes" name="criteria[{{ $code }}][notes]" value="{{ old("criteria.{$code}.notes") }}" maxlength="1000" placeholder="Obrigatório para atenção ou não conformidade">@error('criteria.'.$code.'.notes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            </div>
                        </fieldset>
                    @endforeach
                </div>

                <div class="internal-control-form-grid conclusion-grid">
                    <div><label class="form-label" for="conclusion">Conclusão <span class="required-mark">*</span></label><select class="form-select @error('conclusion') is-invalid @enderror" id="conclusion" name="conclusion" required>@foreach(\App\Models\MunicipalInternalControlReview::conclusions() as $value => $label)<option value="{{ $value }}" @selected(old('conclusion', 'regular') === $value)>{{ $label }}</option>@endforeach</select>@error('conclusion')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="span-2"><label class="form-label" for="summary">Síntese do parecer <span class="required-mark">*</span></label><textarea class="form-control @error('summary') is-invalid @enderror" id="summary" name="summary" rows="3" minlength="20" maxlength="5000" required>{{ old('summary') }}</textarea>@error('summary')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="findings">Achados</label><textarea class="form-control @error('findings') is-invalid @enderror" id="findings" name="findings" rows="3" maxlength="5000">{{ old('findings') }}</textarea>@error('findings')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="recommendations">Recomendações e medidas saneadoras</label><textarea class="form-control @error('recommendations') is-invalid @enderror" id="recommendations" name="recommendations" rows="3" maxlength="5000">{{ old('recommendations') }}</textarea>@error('recommendations')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="responsible_user_id">Responsável pela providência</label><select class="form-select @error('responsible_user_id') is-invalid @enderror" id="responsible_user_id" name="responsible_user_id"><option value="">Sem providência</option>@foreach($operationalUsers as $user)<option value="{{ $user->id }}" @selected((string) old('responsible_user_id') === (string) $user->id)>{{ $user->name }}</option>@endforeach</select>@error('responsible_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="corrective_due_at">Prazo para saneamento</label><input class="form-control @error('corrective_due_at') is-invalid @enderror" id="corrective_due_at" name="corrective_due_at" type="date" value="{{ old('corrective_due_at', today()->addDays(15)->format('Y-m-d')) }}" min="{{ today()->format('Y-m-d') }}">@error('corrective_due_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                </div>

                <div class="internal-control-issue-bar"><div><i data-lucide="fingerprint" aria-hidden="true"></i><span><strong>Emissão com integridade verificável</strong><small>O parecer, o checklist e a fotografia dos dados não poderão ser editados após a confirmação.</small></span></div><button class="btn btn-primary" type="submit"><i data-lucide="badge-check" aria-hidden="true"></i>Emitir parecer</button></div>
            </form>
        </section>
    @endif

    <section class="content-panel mb-4" id="providencias">
        <div class="content-panel-header internal-control-panel-header"><div><p class="page-kicker mb-1">Acompanhamento concomitante</p><h2 class="h5 mb-0">Providências e saneamento</h2></div><span class="small text-secondary">{{ $openActions->count() }} ativa(s)</span></div>
        @if($allActions->isEmpty())
            <div class="empty-state">Nenhuma providência foi aberta pelos pareceres emitidos.</div>
        @else
            <div class="internal-control-actions">
                @foreach($allActions->sortByDesc('id') as $action)
                    @php($canCurrentUserRespond = $canRespond && ($role === 'manager' || $action->responsible_user_id === auth()->id()))
                    <article class="internal-control-action status-{{ $action->status }}" id="providencia-{{ $action->id }}">
                        <div class="internal-control-action-top">
                            <span class="internal-control-action-icon"><i data-lucide="{{ $action->status === 'resolved' ? 'circle-check' : ($action->isOverdue() ? 'alarm-clock' : 'clipboard-check') }}" aria-hidden="true"></i></span>
                            <div><div class="d-flex flex-wrap align-items-center gap-2"><h3 class="h6 mb-0">{{ $action->title }}</h3><span class="control-action-status status-{{ $action->status }}">{{ $action->statusLabel() }}</span></div><p>{{ $action->instructions }}</p></div>
                            <dl><div><dt>Responsável</dt><dd>{{ $action->responsibleUser->name }}</dd></div><div><dt>Prazo</dt><dd class="{{ $action->isOverdue() ? 'text-danger' : '' }}">{{ $action->due_at->format('d/m/Y') }}</dd></div></dl>
                        </div>

                        @if($canCurrentUserRespond && in_array($action->status, ['open', 'returned'], true))
                            <form class="internal-control-action-form" method="POST" action="{{ route('internal-control-actions.respond', $action) }}" enctype="multipart/form-data">@csrf<input name="_submission_token" type="hidden" value="{{ $responseTokens[$action->id] ?? '' }}"><div><label class="form-label" for="response_summary_{{ $action->id }}">Providência adotada <span class="required-mark">*</span></label><textarea class="form-control" id="response_summary_{{ $action->id }}" name="response_summary" rows="2" minlength="10" maxlength="5000" required></textarea></div><div><label class="form-label" for="action_evidence_{{ $action->id }}">Evidência <span class="required-mark">*</span></label><input class="form-control" id="action_evidence_{{ $action->id }}" name="evidence" type="file" accept=".pdf,.jpg,.jpeg,.png" required></div><button class="btn btn-primary" type="submit"><i data-lucide="send" aria-hidden="true"></i>Enviar para validação</button></form>
                        @endif

                        @if($canIssue && $action->status === 'responded')
                            <form class="internal-control-decision-form" method="POST" action="{{ route('internal-control-actions.decide', $action) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $decisionTokens[$action->id] ?? '' }}"><div><label class="form-label" for="decision_{{ $action->id }}">Decisão <span class="required-mark">*</span></label><select class="form-select" id="decision_{{ $action->id }}" name="decision" required><option value="resolved">Saneamento aceito</option><option value="returned">Devolver para correção</option></select></div><div><label class="form-label" for="resolution_notes_{{ $action->id }}">Fundamentação <span class="required-mark">*</span></label><textarea class="form-control" id="resolution_notes_{{ $action->id }}" name="resolution_notes" rows="2" minlength="10" maxlength="5000" required></textarea></div><div><label class="form-label" for="new_due_at_{{ $action->id }}">Novo prazo, se devolvida</label><input class="form-control" id="new_due_at_{{ $action->id }}" name="new_due_at" type="date" min="{{ today()->format('Y-m-d') }}"></div><button class="btn btn-primary" type="submit"><i data-lucide="stamp" aria-hidden="true"></i>Registrar decisão</button></form>
                        @endif

                        <details class="internal-control-action-history"><summary>{{ $action->events->count() }} evento(s) no histórico</summary><div>@foreach($action->events->sortBy('created_at') as $event)<article><span></span><div><strong>{{ match($event->event_type) {'created' => 'Providência aberta', 'response' => 'Resposta apresentada', 'resolved' => 'Saneamento aceito', 'returned' => 'Providência devolvida', default => 'Evento registrado'} }}</strong><time>{{ $event->created_at->format('d/m/Y H:i') }}</time><p>{{ $event->description }}</p><small>{{ $event->actor_name }}</small>@if($event->evidence_path)<a href="{{ route('internal-control-actions.evidence', $event) }}"><i data-lucide="paperclip" aria-hidden="true"></i>{{ $event->evidence_original_name }}</a>@endif</div></article>@endforeach</div></details>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="content-panel">
        <div class="content-panel-header internal-control-panel-header"><div><p class="page-kicker mb-1">Registro formal</p><h2 class="h5 mb-0">Histórico de pareceres</h2></div><span class="small text-secondary">Conteúdo imutável</span></div>
        @if($reviews->isEmpty())
            <div class="empty-state">O primeiro parecer emitido aparecerá aqui com seu hash de integridade.</div>
        @else
            <div class="internal-control-review-list">
                @foreach($reviews as $review)
                    <article>
                        <div class="internal-control-review-summary"><span class="review-sequence">{{ str_pad($review->sequence, 2, '0', STR_PAD_LEFT) }}</span><div><div class="d-flex flex-wrap align-items-center gap-2"><h3 class="h6 mb-0">{{ $review->reference }}</h3><span class="control-review-conclusion conclusion-{{ $review->conclusion }}">{{ $review->conclusionLabel() }}</span></div><p>{{ $review->summary }}</p><small>{{ $review->phaseLabel() }} · {{ $review->reviewer->name }} · {{ $review->issued_at->format('d/m/Y H:i') }}</small></div><div class="internal-control-review-actions"><a class="icon-button" href="{{ route('internal-control-reviews.pdf', $review) }}" title="Baixar parecer PDF" aria-label="Baixar parecer PDF"><i data-lucide="file-down" aria-hidden="true"></i></a>@if($review->evidence_path)<a class="icon-button" href="{{ route('internal-control-reviews.evidence', $review) }}" title="Baixar parecer assinado" aria-label="Baixar parecer assinado"><i data-lucide="paperclip" aria-hidden="true"></i></a>@endif</div></div>
                        <details><summary>Ver checklist e integridade</summary><div class="internal-control-review-details"><dl><div><dt>Plano Anual de Auditoria</dt><dd>{{ $review->annual_audit_plan_reference }}</dd></div>@if($review->governanceReport)<div><dt>Relatório relacionado</dt><dd>{{ $review->governanceReport->code() }}</dd></div>@endif<div><dt>Hash da fotografia</dt><dd><code>{{ $review->snapshot_sha256 }}</code></dd></div></dl><div class="internal-control-review-criteria">@foreach($criteria as $code => $definition)@php($item = $review->criteria[$code])<div><span class="criterion-dot status-{{ $item['status'] }}"></span><strong>{{ $definition['label'] }}</strong><small>{{ $criterionStatuses[$item['status']] }}{{ !empty($item['notes']) ? ' · '.$item['notes'] : '' }}</small></div>@endforeach</div>@if($review->findings)<div class="review-text"><strong>Achados</strong><p>{{ $review->findings }}</p></div>@endif @if($review->recommendations)<div class="review-text"><strong>Recomendações</strong><p>{{ $review->recommendations }}</p></div>@endif</div></details>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
