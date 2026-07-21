@extends('layouts.app')

@section('title', $plan->reference().' | TrilhaGov')

@section('content')
    @php
        $completed = $plan->items->where('status', 'completed')->count();
        $overdue = $plan->items->filter->isOverdue()->count();
        $coverage = $plan->items->count() ? round(($completed / $plan->items->count()) * 100) : 0;
        $activeItems = $plan->items->whereNotIn('status', ['completed', 'cancelled']);
        $defaultPlannedAt = today()->isBefore($plan->planned_start_at)
            ? $plan->planned_start_at
            : (today()->isAfter($plan->planned_end_at) ? $plan->planned_end_at : today());
    @endphp

    <div class="audit-plan-heading mb-4">
        <div><a class="back-link" href="{{ route('audit-plans.index') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Planos anuais</a><p class="page-kicker mt-3 mb-2">{{ $municipality->name }}/{{ $municipality->state }} · {{ $plan->coordination_unit }}</p><div class="d-flex flex-wrap align-items-center gap-2"><h1 class="h3 mb-0">{{ $plan->reference() }}</h1><span class="audit-plan-status status-{{ $plan->status }}">{{ $plan->statusLabel() }}</span></div><p class="text-secondary mb-0 mt-1">{{ $plan->title }}</p></div>
        <div class="audit-plan-heading-actions"><a class="btn btn-outline-primary" href="{{ route('audit-plans.pdf', $plan) }}"><i data-lucide="file-down" aria-hidden="true"></i>Baixar PDF</a></div>
    </div>

    <x-validation-summary />

    @if($plan->issued_at)
        <section class="audit-plan-seal mb-4"><i data-lucide="fingerprint" aria-hidden="true"></i><div><strong>Plano emitido em {{ $plan->issued_at->format('d/m/Y H:i') }}</strong><p>Conteúdo-base preservado para auditoria. Emitido por {{ $plan->issuer?->name }}.</p></div><code title="SHA-256">{{ $plan->snapshot_sha256 }}</code></section>
    @endif

    <div class="audit-plan-metrics mb-4">
        <article><span><i data-lucide="list-checks" aria-hidden="true"></i></span><div><small>Verificações</small><strong>{{ $plan->items->count() }}</strong><p>{{ $activeItems->count() }} em agenda</p></div></article>
        <article><span><i data-lucide="circle-check-big" aria-hidden="true"></i></span><div><small>Concluídas</small><strong>{{ $completed }}</strong><p>{{ $coverage }}% de cobertura</p></div></article>
        <article class="{{ $overdue ? 'has-risk' : '' }}"><span><i data-lucide="alarm-clock" aria-hidden="true"></i></span><div><small>Vencidas</small><strong>{{ $overdue }}</strong><p>{{ $overdue ? 'Requer atuação' : 'Agenda em dia' }}</p></div></article>
        <article><span><i data-lucide="users-round" aria-hidden="true"></i></span><div><small>Responsáveis</small><strong>{{ $plan->items->pluck('assigned_user_id')->unique()->count() }}</strong><p>{{ $plan->planned_start_at->format('d/m') }} a {{ $plan->planned_end_at->format('d/m/Y') }}</p></div></article>
    </div>

    @if($plan->isDraft() && $canManage)
        <section class="content-panel mb-4">
            <div class="content-panel-header audit-plan-panel-header"><div><p class="page-kicker mb-1">Configuração</p><h2 class="h5 mb-0">Diretrizes da minuta</h2></div><span class="small text-secondary">Editável até a emissão</span></div>
            <form class="audit-plan-form" method="POST" action="{{ route('audit-plans.update', $plan) }}">@csrf @method('PATCH')<input name="_submission_token" type="hidden" value="{{ $updateToken }}">
                <div class="span-2"><label class="form-label" for="title">Título <span class="required-mark">*</span></label><input class="form-control" id="title" name="title" value="{{ old('title', $plan->title) }}" required maxlength="220"></div>
                <div><label class="form-label" for="coordination_unit">Unidade coordenadora <span class="required-mark">*</span></label><input class="form-control" id="coordination_unit" name="coordination_unit" value="{{ old('coordination_unit', $plan->coordination_unit) }}" required maxlength="180"></div>
                <div class="span-3"><label class="form-label" for="objective">Objetivo <span class="required-mark">*</span></label><textarea class="form-control" id="objective" name="objective" rows="2" required>{{ old('objective', $plan->objective) }}</textarea></div>
                <div class="span-3"><label class="form-label" for="methodology">Metodologia <span class="required-mark">*</span></label><textarea class="form-control" id="methodology" name="methodology" rows="2" required>{{ old('methodology', $plan->methodology) }}</textarea></div>
                <div class="span-3"><label class="form-label" for="risk_criteria">Critérios de risco <span class="required-mark">*</span></label><textarea class="form-control" id="risk_criteria" name="risk_criteria" rows="2" required>{{ old('risk_criteria', $plan->risk_criteria) }}</textarea></div>
                <div class="span-2"><label class="form-label" for="normative_basis">Base normativa <span class="required-mark">*</span></label><textarea class="form-control" id="normative_basis" name="normative_basis" rows="2" required>{{ old('normative_basis', $plan->normative_basis) }}</textarea></div>
                <div><label class="form-label" for="management_notes">Observações</label><textarea class="form-control" id="management_notes" name="management_notes" rows="2">{{ old('management_notes', $plan->management_notes) }}</textarea></div>
                <div><label class="form-label" for="planned_start_at">Início <span class="required-mark">*</span></label><input class="form-control" id="planned_start_at" name="planned_start_at" type="date" value="{{ old('planned_start_at', $plan->planned_start_at->format('Y-m-d')) }}" required></div>
                <div><label class="form-label" for="planned_end_at">Encerramento <span class="required-mark">*</span></label><input class="form-control" id="planned_end_at" name="planned_end_at" type="date" value="{{ old('planned_end_at', $plan->planned_end_at->format('Y-m-d')) }}" required></div>
                <div class="audit-plan-form-action"><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar diretrizes</button></div>
            </form>
        </section>

        <section class="content-panel mb-4" id="incluir-item">
            <div class="content-panel-header audit-plan-panel-header"><div><p class="page-kicker mb-1">Seleção orientada</p><h2 class="h5 mb-0">Incluir verificação na agenda</h2></div><span class="small text-secondary">{{ $recommendations->count() }} emenda(s) disponível(is)</span></div>
            @if($recommendations->isEmpty())
                <div class="empty-state">Todas as emendas municipais disponíveis já estão nesta versão.</div>
            @else
                <div class="audit-risk-strip">
                    @foreach($recommendations->take(4) as $recommendation)
                        <div><span class="risk-score risk-{{ $recommendation['score'] >= 70 ? 'high' : ($recommendation['score'] >= 40 ? 'medium' : 'low') }}">{{ $recommendation['score'] }}</span><strong>{{ $recommendation['amendment']->reference }}</strong><small>{{ implode(' · ', $recommendation['reasons']) }}</small></div>
                    @endforeach
                </div>
                <form class="audit-plan-item-create" method="POST" action="{{ route('audit-plan-items.store', $plan) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $itemToken }}">
                    <div class="span-2"><label class="form-label" for="parliamentary_amendment_id">Emenda municipal <span class="required-mark">*</span></label><select class="form-select" id="parliamentary_amendment_id" name="parliamentary_amendment_id" required><option value="">Selecione pela prioridade calculada</option>@foreach($recommendations as $recommendation)<option value="{{ $recommendation['amendment']->id }}" @selected((string) old('parliamentary_amendment_id') === (string) $recommendation['amendment']->id)>Risco {{ $recommendation['score'] }} · {{ $recommendation['amendment']->reference }} · {{ Str::limit($recommendation['amendment']->object, 70) }}</option>@endforeach</select></div>
                    <div><label class="form-label" for="assigned_user_id">Responsável <span class="required-mark">*</span></label><select class="form-select" id="assigned_user_id" name="assigned_user_id" required>@foreach($auditors as $auditor)<option value="{{ $auditor->id }}" @selected((string) old('assigned_user_id', auth()->id()) === (string) $auditor->id)>{{ $auditor->name }}</option>@endforeach</select></div>
                    <div><label class="form-label" for="phase">Fase <span class="required-mark">*</span></label><select class="form-select" id="phase" name="phase" required>@foreach($phases as $value => $label)<option value="{{ $value }}" @selected(old('phase', 'concomitant') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div><label class="form-label" for="priority">Prioridade <span class="required-mark">*</span></label><select class="form-select" id="priority" name="priority" required>@foreach($priorities as $value => $label)<option value="{{ $value }}" @selected(old('priority', 'high') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div><label class="form-label" for="frequency">Frequência <span class="required-mark">*</span></label><select class="form-select" id="frequency" name="frequency" required>@foreach($frequencies as $value => $label)<option value="{{ $value }}" @selected(old('frequency', 'milestones') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div><label class="form-label" for="planned_at">Data planejada <span class="required-mark">*</span></label><input class="form-control" id="planned_at" name="planned_at" type="date" min="{{ $plan->planned_start_at->format('Y-m-d') }}" max="{{ $plan->planned_end_at->format('Y-m-d') }}" value="{{ old('planned_at', $defaultPlannedAt->format('Y-m-d')) }}" required></div>
                    <div class="span-2"><label class="form-label" for="scope_notes">Escopo da verificação <span class="required-mark">*</span></label><textarea class="form-control" id="scope_notes" name="scope_notes" rows="2" minlength="10" maxlength="3000" required>{{ old('scope_notes', 'Examinar plano de trabalho, compatibilidade orçamentária, regularidade da contratação, conflitos de interesse, execução e evidências aplicáveis à fase.') }}</textarea></div>
                    <div class="audit-plan-form-action"><button class="btn btn-primary" type="submit"><i data-lucide="calendar-plus" aria-hidden="true"></i>Incluir na agenda</button></div>
                </form>
            @endif
        </section>
    @endif

    <section class="content-panel mb-4" id="agenda">
        <div class="content-panel-header audit-plan-panel-header"><div><p class="page-kicker mb-1">Execução anual</p><h2 class="h5 mb-0">Agenda de verificações</h2></div><span class="small text-secondary">{{ $plan->items->count() }} item(ns)</span></div>
        @if($plan->items->isEmpty())
            <div class="empty-state">Inclua a primeira emenda para estruturar a agenda anual.</div>
        @else
            <div class="audit-plan-schedule">
                @foreach($plan->items as $item)
                    <article id="item-{{ $item->id }}" class="status-{{ $item->status }}">
                        <div class="audit-plan-date"><small>{{ $item->planned_at->translatedFormat('M') }}</small><strong>{{ $item->planned_at->format('d') }}</strong><span>{{ $item->planned_at->format('Y') }}</span></div>
                        <div class="audit-plan-item-main"><div class="audit-plan-item-tags"><span class="audit-plan-status status-{{ $item->status }}">{{ $item->statusLabel() }}</span><span class="priority-{{ $item->priority }}">{{ $item->priorityLabel() }}</span><span>{{ $item->phaseLabel() }}</span></div><h3>{{ $item->amendment->reference }} · {{ $item->amendment->object }}</h3><p>{{ $item->scope_notes }}</p><small><i data-lucide="user-round" aria-hidden="true"></i>{{ $item->assignedUser->name }} · {{ $item->frequencyLabel() }}</small>@if($item->status_notes)<div class="audit-plan-status-note">{{ $item->status_notes }}</div>@endif</div>
                        <div class="audit-plan-item-actions">
                            @if($item->program)
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('audit-programs.show', $item->program) }}" title="Abrir Programa de Auditoria"><i data-lucide="clipboard-list" aria-hidden="true"></i>Programa</a>
                            @elseif(!$plan->isDraft() && $canManage && isset($programCreateTokens[$item->id]))
                                <details class="audit-program-launch">
                                    <summary class="btn btn-sm btn-primary"><i data-lucide="play" aria-hidden="true"></i>Iniciar programa</summary>
                                    <form method="POST" action="{{ route('audit-programs.store', $item) }}">
                                        @csrf
                                        <input name="_submission_token" type="hidden" value="{{ $programCreateTokens[$item->id] }}">
                                        <label class="form-label">Supervisor independente <span class="required-mark">*</span>
                                            <select class="form-select" name="supervisor_id" required>
                                                <option value="">Selecione</option>
                                                @foreach($auditors as $auditor)
                                                    @if($auditor->id !== $item->assigned_user_id)<option value="{{ $auditor->id }}">{{ $auditor->name }}</option>@endif
                                                @endforeach
                                            </select>
                                        </label>
                                        <button class="btn btn-primary" type="submit"><i data-lucide="clipboard-plus" aria-hidden="true"></i>Criar programa</button>
                                    </form>
                                </details>
                            @endif
                            <a class="icon-button" href="{{ route('emendas.internal-control', $item->amendment) }}#emitir-parecer" title="Abrir Controle Interno" aria-label="Abrir Controle Interno"><i data-lucide="shield-check" aria-hidden="true"></i></a>
                            @if($plan->isDraft() && $canManage)
                                <details><summary class="icon-button" title="Editar item" aria-label="Editar item"><i data-lucide="pencil" aria-hidden="true"></i></summary><form method="POST" action="{{ route('audit-plan-items.update', $item) }}">@csrf @method('PATCH')<input name="_submission_token" type="hidden" value="{{ $itemUpdateTokens[$item->id] }}"><input name="parliamentary_amendment_id" type="hidden" value="{{ $item->parliamentary_amendment_id }}"><label class="form-label">Responsável<select class="form-select" name="assigned_user_id" required>@foreach($auditors as $auditor)<option value="{{ $auditor->id }}" @selected($item->assigned_user_id === $auditor->id)>{{ $auditor->name }}</option>@endforeach</select></label><label class="form-label">Fase<select class="form-select" name="phase" required>@foreach($phases as $value => $label)<option value="{{ $value }}" @selected($item->phase === $value)>{{ $label }}</option>@endforeach</select></label><label class="form-label">Prioridade<select class="form-select" name="priority" required>@foreach($priorities as $value => $label)<option value="{{ $value }}" @selected($item->priority === $value)>{{ $label }}</option>@endforeach</select></label><label class="form-label">Frequência<select class="form-select" name="frequency" required>@foreach($frequencies as $value => $label)<option value="{{ $value }}" @selected($item->frequency === $value)>{{ $label }}</option>@endforeach</select></label><label class="form-label">Data<input class="form-control" name="planned_at" type="date" value="{{ $item->planned_at->format('Y-m-d') }}" required></label><label class="form-label span-2">Escopo<textarea class="form-control" name="scope_notes" rows="2" required>{{ $item->scope_notes }}</textarea></label><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar</button></form></details>
                                <form method="POST" action="{{ route('audit-plan-items.destroy', $item) }}" onsubmit="return confirm('Remover este item da minuta?')">@csrf @method('DELETE')<input name="_submission_token" type="hidden" value="{{ $itemDeleteTokens[$item->id] }}"><button class="icon-button is-danger" type="submit" title="Remover item" aria-label="Remover item"><i data-lucide="trash-2" aria-hidden="true"></i></button></form>
                            @elseif(!$plan->isDraft() && $canManage && isset($progressTokens[$item->id]))
                                <details><summary class="icon-button" title="Atualizar andamento" aria-label="Atualizar andamento"><i data-lucide="refresh-cw" aria-hidden="true"></i></summary><form method="POST" action="{{ route('audit-plan-items.progress', $item) }}">@csrf @method('PATCH')<input name="_submission_token" type="hidden" value="{{ $progressTokens[$item->id] }}"><label class="form-label">Situação<select class="form-select" name="status" required><option value="in_progress">Em andamento</option><option value="rescheduled">Reprogramada</option><option value="cancelled">Cancelada</option></select></label><label class="form-label">Nova data<input class="form-control" name="planned_at" type="date" value="{{ $item->planned_at->format('Y-m-d') }}"></label><label class="form-label span-2">Justificativa<textarea class="form-control" name="status_notes" rows="2" minlength="5" required></textarea></label><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Registrar</button></form></details>
                            @endif
                        </div>
                        @if($item->events->isNotEmpty())<details class="audit-plan-history"><summary>{{ $item->events->count() }} evento(s)</summary><div>@foreach($item->events as $event)<p><strong>{{ $event->actor_name }}</strong><span>{{ $event->description }}</span><time>{{ $event->created_at->format('d/m/Y H:i') }}</time></p>@endforeach</div></details>@endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @if($plan->isDraft() && $canManage)
        <section class="audit-plan-issue mb-4">
            <div><span><i data-lucide="badge-check" aria-hidden="true"></i></span><div><p class="page-kicker mb-1">Fechamento formal</p><h2>Emitir {{ $plan->reference() }}</h2><p>A emissão preserva diretrizes e agenda em uma fotografia com hash SHA-256. A minuta deixa de ser editável.</p></div></div>
            @if($blockers)<ul>@foreach($blockers as $blocker)<li><i data-lucide="circle-alert" aria-hidden="true"></i>{{ $blocker }}</li>@endforeach</ul>@endif
            <form method="POST" action="{{ route('audit-plans.issue', $plan) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $issueToken }}"><label><input name="confirm_plan" type="checkbox" value="1" required>Confirmo que escopo, responsáveis e datas foram revisados.</label><button class="btn btn-primary" type="submit" @disabled($blockers)><i data-lucide="stamp" aria-hidden="true"></i>Emitir plano anual</button></form>
        </section>
    @endif
@endsection
