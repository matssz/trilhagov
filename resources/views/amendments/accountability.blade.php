@extends('layouts.app')

@section('title', 'Prestação de contas ' . $amendment->reference . ' | TrilhaGov')

@section('content')
    <a class="d-inline-flex align-items-center gap-2 mb-3" href="{{ route('emendas.index') }}">
        <i data-lucide="arrow-left" aria-hidden="true"></i>Voltar para emendas
    </a>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-3">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <p class="page-kicker mb-0">Prestação de contas</p>
                <x-amendment-status-badge :status="$amendment->status" :label="$amendment->statusLabel()" />
                @if ($process)<span class="accountability-status accountability-status-{{ $process->status }}">{{ $process->statusLabel() }}</span>@endif
            </div>
            <h1 class="h3 mb-1">{{ $amendment->reference }}</h1>
            <p class="text-secondary mb-0">{{ $amendment->object }}</p>
        </div>
        @if ($process)
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-primary" href="{{ route('emendas.accountability.dossier.pdf', $amendment) }}"><i data-lucide="file-down" aria-hidden="true"></i>Baixar PDF</a>
                <a class="btn btn-primary" href="{{ route('emendas.accountability.dossier.package', $amendment) }}"><i data-lucide="package" aria-hidden="true"></i>Baixar pacote</a>
            </div>
        @endif
    </div>

    <nav class="amendment-tabs mb-4" aria-label="Seções da emenda">
        <a href="{{ route('emendas.show', $amendment) }}">Visão geral</a>
        @if ($amendment->supportsTcespCompliance())
            <a href="{{ route('emendas.work-plan', $amendment) }}">Plano de trabalho</a>
        @endif
        <a href="{{ route('emendas.execution', $amendment) }}">Execução</a>
        @if ($amendment->supportsTcespCompliance())
            <a href="{{ route('emendas.compliance', $amendment) }}">Conformidade TCESP</a>
        @endif
        <a class="active" href="{{ route('emendas.accountability', $amendment) }}" aria-current="page">Prestação de contas</a>
    </nav>

    <x-validation-summary />

    @if (! $process)
        <section class="content-panel accountability-start">
            <div class="accountability-start-icon"><i data-lucide="clipboard-list" aria-hidden="true"></i></div>
            <div>
                <h2 class="h5">Iniciar prestação de contas</h2>
                <dl class="data-list mb-0">
                    <dt>Prazo cadastrado</dt><dd>{{ $amendment->accountability_deadline?->format('d/m/Y') ?? 'Não definido' }}</dd>
                    <dt>Responsável inicial</dt><dd>{{ $amendment->responsibleUser?->name ?? 'Não definido' }}</dd>
                    <dt>Execução física</dt><dd>{{ $amendment->physicalExecutionPercentage() }}%</dd>
                    <dt>Documentos disponíveis</dt><dd>{{ $amendment->documents->count() }}</dd>
                </dl>
            </div>
            @if ($canEdit)
                <form method="POST" action="{{ route('emendas.accountability.store', $amendment) }}">
                    @csrf
                    <input name="_submission_token" type="hidden" value="{{ $processCreateToken }}">
                    <button class="btn btn-primary" type="submit"><i data-lucide="plus" aria-hidden="true"></i>Iniciar processo</button>
                </form>
            @endif
        </section>
    @else
        @php($openDiligences = $process->diligences->where('status', 'open'))
        <div class="metric-grid accountability-metrics mb-4">
            <article class="metric-card {{ $readiness['ready'] ? '' : 'metric-danger' }}">
                <span class="metric-icon"><i data-lucide="gauge" aria-hidden="true"></i></span>
                <div class="metric-label">Prontidão</div>
                <div class="metric-value">{{ $readiness['score'] }}%</div>
            </article>
            <article class="metric-card">
                <span class="metric-icon"><i data-lucide="list-checks" aria-hidden="true"></i></span>
                <div class="metric-label">Checklist obrigatório</div>
                <div class="metric-value">{{ $readiness['required_resolved'] }}/{{ $readiness['required_total'] }}</div>
            </article>
            <article class="metric-card {{ abs($readiness['financial']['unreconciled']) > 0.01 ? 'metric-danger' : '' }}">
                <span class="metric-icon"><i data-lucide="scale" aria-hidden="true"></i></span>
                <div class="metric-label">Saldo sem conciliação</div>
                <div class="metric-value">R$ {{ number_format($readiness['financial']['unreconciled'], 2, ',', '.') }}</div>
            </article>
            <article class="metric-card {{ $openDiligences->filter->isOverdue()->isNotEmpty() ? 'metric-danger' : '' }}">
                <span class="metric-icon"><i data-lucide="shield-alert" aria-hidden="true"></i></span>
                <div class="metric-label">Diligências abertas</div>
                <div class="metric-value">{{ $openDiligences->count() }}</div>
            </article>
        </div>

        <section class="content-panel mb-4 readiness-panel {{ $readiness['ready'] ? 'is-ready' : 'has-blockers' }}">
            <div class="content-panel-header d-flex align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <i data-lucide="{{ $readiness['ready'] ? 'badge-check' : 'triangle-alert' }}" aria-hidden="true"></i>
                    <h2 class="h5 mb-0">{{ $readiness['ready'] ? 'Pronta para envio' : 'Pendências para envio' }}</h2>
                </div>
                <strong>{{ $readiness['score'] }}%</strong>
            </div>
            <div class="readiness-meter" role="progressbar" aria-label="Prontidão da prestação" aria-valuenow="{{ $readiness['score'] }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $readiness['score'] }}%"></span></div>
            @if ($readiness['blockers']->isNotEmpty() || $readiness['warnings']->isNotEmpty())
                <div class="readiness-list">
                    @foreach ($readiness['blockers'] as $blocker)<div><i data-lucide="circle-x" aria-hidden="true"></i><span>{{ $blocker }}</span></div>@endforeach
                    @foreach ($readiness['warnings'] as $warning)<div class="warning"><i data-lucide="triangle-alert" aria-hidden="true"></i><span>{{ $warning }}</span></div>@endforeach
                </div>
            @endif
        </section>

        <section class="content-panel mb-4" id="process">
            <div class="content-panel-header d-flex align-items-center gap-2"><i data-lucide="clipboard-list" aria-hidden="true"></i><h2 class="h5 mb-0">Processo e protocolo</h2></div>
            @if ($canEdit)
                <form class="accountability-process-form" method="POST" action="{{ route('emendas.accountability.update', $amendment) }}" novalidate>
                    @csrf
                    @method('PATCH')
                    <input name="_submission_token" type="hidden" value="{{ $processUpdateToken }}">
                    <div class="accountability-process-grid">
                        <div>
                            <label class="form-label" for="accountability_status">Situação <span class="required-mark">*</span></label>
                            <select class="form-select @error('status') is-invalid @enderror" id="accountability_status" name="status" required>
                                @foreach ($processStatuses as $value => $label)<option value="{{ $value }}" @selected(old('status', $process->status) === $value)>{{ $label }}</option>@endforeach
                            </select>
                            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="accountability_responsible">Responsável</label>
                            <select class="form-select @error('responsible_user_id') is-invalid @enderror" id="accountability_responsible" name="responsible_user_id">
                                <option value="">Não definido</option>
                                @foreach ($responsibleUsers as $user)<option value="{{ $user->id }}" @selected((int) old('responsible_user_id', $process->responsible_user_id) === $user->id)>{{ $user->name }}</option>@endforeach
                            </select>
                            @error('responsible_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div><label class="form-label" for="accountability_due_at">Prazo</label><input class="form-control @error('due_at') is-invalid @enderror" id="accountability_due_at" name="due_at" type="date" value="{{ old('due_at', $process->due_at?->toDateString()) }}">@error('due_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div><label class="form-label" for="submitted_at">Data de envio</label><input class="form-control @error('submitted_at') is-invalid @enderror" id="submitted_at" name="submitted_at" type="date" value="{{ old('submitted_at', $process->submitted_at?->toDateString()) }}">@error('submitted_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="span-2"><label class="form-label" for="protocol_number">Protocolo de envio</label><input class="form-control @error('protocol_number') is-invalid @enderror" id="protocol_number" name="protocol_number" value="{{ old('protocol_number', $process->protocol_number) }}" maxlength="100">@error('protocol_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div><label class="form-label" for="approved_at">Data de aprovação</label><input class="form-control @error('approved_at') is-invalid @enderror" id="approved_at" name="approved_at" type="date" value="{{ old('approved_at', $process->approved_at?->toDateString()) }}">@error('approved_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div><label class="form-label" for="returned_amount">Valor devolvido <span class="required-mark">*</span></label><input class="form-control @error('returned_amount') is-invalid @enderror" id="returned_amount" name="returned_amount" type="number" value="{{ old('returned_amount', $process->returned_amount) }}" min="0" step="0.01" required>@error('returned_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div><label class="form-label" for="returned_at">Data da devolução</label><input class="form-control @error('returned_at') is-invalid @enderror" id="returned_at" name="returned_at" type="date" value="{{ old('returned_at', $process->returned_at?->toDateString()) }}">@error('returned_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div><label class="form-label" for="return_reference">Referência da devolução</label><input class="form-control @error('return_reference') is-invalid @enderror" id="return_reference" name="return_reference" value="{{ old('return_reference', $process->return_reference) }}" maxlength="120">@error('return_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="span-2"><label class="form-label" for="reconciliation_notes">Observações da conciliação</label><textarea class="form-control @error('reconciliation_notes') is-invalid @enderror" id="reconciliation_notes" name="reconciliation_notes" rows="2" maxlength="3000">{{ old('reconciliation_notes', $process->reconciliation_notes) }}</textarea>@error('reconciliation_notes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="span-2"><label class="form-label" for="submission_notes">Observações do envio</label><textarea class="form-control @error('submission_notes') is-invalid @enderror" id="submission_notes" name="submission_notes" rows="2" maxlength="3000">{{ old('submission_notes', $process->submission_notes) }}</textarea>@error('submission_notes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    </div>
                    <div class="text-end mt-3"><button class="btn btn-primary" type="submit"><i data-lucide="check" aria-hidden="true"></i>Salvar processo</button></div>
                </form>
            @else
                <div class="content-panel-body"><dl class="data-list mb-0"><dt>Situação</dt><dd>{{ $process->statusLabel() }}</dd><dt>Responsável</dt><dd>{{ $process->responsibleUser?->name ?? 'Não definido' }}</dd><dt>Prazo</dt><dd>{{ $process->due_at?->format('d/m/Y') ?? 'Não definido' }}</dd><dt>Protocolo</dt><dd>{{ $process->protocol_number ?? 'Não informado' }}</dd></dl></div>
            @endif
        </section>

        <section class="content-panel mb-4" id="reconciliation">
            <div class="content-panel-header d-flex align-items-center gap-2"><i data-lucide="scale" aria-hidden="true"></i><h2 class="h5 mb-0">Conciliação financeira</h2></div>
            <div class="reconciliation-grid">
                @foreach ([
                    ['label' => 'Recebido', 'value' => $readiness['financial']['received']],
                    ['label' => 'Pago', 'value' => $readiness['financial']['paid']],
                    ['label' => 'Devolvido', 'value' => $readiness['financial']['returned']],
                    ['label' => 'Sem conciliação', 'value' => $readiness['financial']['unreconciled']],
                ] as $item)
                    <div class="{{ $item['label'] === 'Sem conciliação' && abs($item['value']) > 0.01 ? 'has-difference' : '' }}"><span>{{ $item['label'] }}</span><strong>R$ {{ number_format($item['value'], 2, ',', '.') }}</strong></div>
                @endforeach
            </div>
        </section>

        <section class="content-panel mb-4" id="requirements">
            <div class="content-panel-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div class="d-flex align-items-center gap-2"><i data-lucide="list-checks" aria-hidden="true"></i><h2 class="h5 mb-0">Checklist da prestação</h2></div>
                @if ($canEdit)<button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newRequirementForm"><i data-lucide="plus" aria-hidden="true"></i>Novo item</button>@endif
            </div>
            <div class="checklist-progress" role="progressbar" aria-label="Progresso do checklist" aria-valuenow="{{ $readiness['checklist_percentage'] }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $readiness['checklist_percentage'] }}%"></span></div>

            @if ($canEdit)
                <div class="collapse" id="newRequirementForm">
                    <form class="accountability-form-band requirement-create-grid" method="POST" action="{{ route('emendas.accountability.requirements.store', $amendment) }}">
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $requirementCreateToken }}">
                        <div class="span-2"><label class="form-label" for="requirement_title">Item <span class="required-mark">*</span></label><input class="form-control" id="requirement_title" name="title" maxlength="180" required></div>
                        <div><label class="form-label" for="requirement_category">Categoria <span class="required-mark">*</span></label><select class="form-select" id="requirement_category" name="category" required>@foreach ($requirementCategories as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                        <div><label class="form-label" for="requirement_order">Ordem <span class="required-mark">*</span></label><input class="form-control" id="requirement_order" name="sort_order" type="number" value="60" min="0" max="65000" required></div>
                        <div class="span-2"><label class="form-label" for="requirement_description">Descrição</label><input class="form-control" id="requirement_description" name="description" maxlength="2000"></div>
                        <div><label class="form-label" for="requirement_required">Obrigatoriedade</label><select class="form-select" id="requirement_required" name="is_required"><option value="1">Obrigatório</option><option value="0">Opcional</option></select></div>
                        <button class="btn btn-primary accountability-inline-submit" type="submit"><i data-lucide="check" aria-hidden="true"></i>Adicionar</button>
                    </form>
                </div>
            @endif

            <div class="accountability-requirement-list">
                @foreach ($process->requirements as $requirement)
                    <article class="accountability-requirement-row status-{{ $requirement->status }}" id="requisito-{{ $requirement->id }}">
                        <span class="requirement-state"><i data-lucide="{{ $requirement->status === 'completed' ? 'circle-check' : ($requirement->status === 'not_applicable' ? 'circle-minus' : 'circle-dot') }}" aria-hidden="true"></i></span>
                        <div class="requirement-copy">
                            <div class="d-flex flex-wrap align-items-center gap-2"><h3 class="h6 mb-0">{{ $requirement->title }}</h3><span class="badge text-bg-light">{{ $requirement->categoryLabel() }}</span>@if ($requirement->is_required)<span class="badge text-bg-warning">Obrigatório</span>@endif</div>
                            @if ($requirement->description)<p>{{ $requirement->description }}</p>@endif
                            @if ($requirement->document)<a href="{{ route('emendas.documents.download', [$amendment, $requirement->document]) }}"><i data-lucide="paperclip" aria-hidden="true"></i>{{ $requirement->document->original_name }}</a>@endif
                            @if ($requirement->notes)<small>{{ $requirement->notes }}</small>@endif
                        </div>
                        <span class="requirement-status">{{ $requirement->statusLabel() }}</span>
                        @if ($canEdit)
                            <details class="requirement-editor">
                                <summary title="Atualizar item" aria-label="Atualizar item"><i data-lucide="pencil" aria-hidden="true"></i></summary>
                                <form method="POST" action="{{ route('emendas.accountability.requirements.update', [$amendment, $requirement]) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input name="_submission_token" type="hidden" value="{{ $requirementUpdateTokens->get($requirement->id) }}">
                                    <div><label class="form-label" for="requirement_status_{{ $requirement->id }}">Situação</label><select class="form-select" id="requirement_status_{{ $requirement->id }}" name="status">@foreach ($requirementStatuses as $value => $label)<option value="{{ $value }}" @selected($requirement->status === $value)>{{ $label }}</option>@endforeach</select></div>
                                    <div><label class="form-label" for="requirement_document_{{ $requirement->id }}">Documento</label><select class="form-select" id="requirement_document_{{ $requirement->id }}" name="amendment_document_id"><option value="">Sem vínculo</option>@foreach ($amendment->documents as $document)<option value="{{ $document->id }}" @selected($requirement->amendment_document_id === $document->id)>{{ $document->documentType->name }} · {{ $document->original_name }}</option>@endforeach</select></div>
                                    <div><label class="form-label" for="requirement_notes_{{ $requirement->id }}">Observação</label><input class="form-control" id="requirement_notes_{{ $requirement->id }}" name="notes" value="{{ $requirement->notes }}" maxlength="2000"></div>
                                    <button class="btn btn-primary accountability-inline-submit" type="submit"><i data-lucide="check" aria-hidden="true"></i>Salvar</button>
                                </form>
                            </details>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        <section class="content-panel mb-4" id="diligences">
            <div class="content-panel-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div class="d-flex align-items-center gap-2"><i data-lucide="shield-alert" aria-hidden="true"></i><h2 class="h5 mb-0">Diligências</h2></div>
                @if ($canEdit)<button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newDiligenceForm"><i data-lucide="plus" aria-hidden="true"></i>Nova diligência</button>@endif
            </div>
            @if ($canEdit)
                <div class="collapse" id="newDiligenceForm">
                    <form class="accountability-form-band diligence-create-grid" method="POST" action="{{ route('emendas.accountability.diligences.store', $amendment) }}">
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $diligenceCreateToken }}">
                        <div class="span-2"><label class="form-label" for="diligence_title">Título <span class="required-mark">*</span></label><input class="form-control" id="diligence_title" name="title" maxlength="180" required></div>
                        <div><label class="form-label" for="diligence_responsible">Responsável</label><select class="form-select" id="diligence_responsible" name="assigned_user_id"><option value="">Responsável da prestação</option>@foreach ($responsibleUsers as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div>
                        <div><label class="form-label" for="diligence_received_at">Recebida em <span class="required-mark">*</span></label><input class="form-control" id="diligence_received_at" name="received_at" type="date" required></div>
                        <div><label class="form-label" for="diligence_due_at">Prazo <span class="required-mark">*</span></label><input class="form-control" id="diligence_due_at" name="due_at" type="date" required></div>
                        <div class="span-3"><label class="form-label" for="diligence_description">Solicitação <span class="required-mark">*</span></label><textarea class="form-control" id="diligence_description" name="description" rows="2" maxlength="3000" required></textarea></div>
                        <button class="btn btn-primary accountability-inline-submit" type="submit"><i data-lucide="check" aria-hidden="true"></i>Registrar</button>
                    </form>
                </div>
            @endif
            <div class="diligence-list">
                @forelse ($process->diligences as $diligence)
                    <article class="diligence-row {{ $diligence->isOverdue() ? 'is-overdue' : '' }}" id="diligencia-{{ $diligence->id }}">
                        <span class="diligence-icon"><i data-lucide="message-square" aria-hidden="true"></i></span>
                        <div class="diligence-copy"><div class="d-flex flex-wrap align-items-center gap-2"><h3 class="h6 mb-0">{{ $diligence->title }}</h3><span class="diligence-status status-{{ $diligence->status }}">{{ $diligence->statusLabel() }}</span>@if ($diligence->isOverdue())<span class="badge text-bg-danger">Atrasada</span>@endif</div><p>{{ $diligence->description }}</p><small>{{ $diligence->assignedUser?->name ?? $process->responsibleUser?->name ?? 'Sem responsável' }} · prazo {{ $diligence->due_at->format('d/m/Y') }}</small>@if ($diligence->response_notes)<div class="diligence-response"><strong>Resposta:</strong> {{ $diligence->response_notes }} · protocolo {{ $diligence->response_protocol }}</div>@endif</div>
                        @if ($canEdit)
                            <details class="diligence-editor"><summary title="Responder diligência" aria-label="Responder diligência"><i data-lucide="send" aria-hidden="true"></i></summary><form method="POST" action="{{ route('emendas.accountability.diligences.update', [$amendment, $diligence]) }}">@csrf @method('PATCH')<input name="_submission_token" type="hidden" value="{{ $diligenceUpdateTokens->get($diligence->id) }}"><div><label class="form-label" for="diligence_status_{{ $diligence->id }}">Situação</label><select class="form-select" id="diligence_status_{{ $diligence->id }}" name="status">@foreach (App\Models\AccountabilityDiligence::statuses() as $value => $label)<option value="{{ $value }}" @selected($diligence->status === $value)>{{ $label }}</option>@endforeach</select></div><div><label class="form-label" for="response_protocol_{{ $diligence->id }}">Protocolo</label><input class="form-control" id="response_protocol_{{ $diligence->id }}" name="response_protocol" value="{{ $diligence->response_protocol }}"></div><div class="span-2"><label class="form-label" for="response_notes_{{ $diligence->id }}">Resposta</label><textarea class="form-control" id="response_notes_{{ $diligence->id }}" name="response_notes" rows="2">{{ $diligence->response_notes }}</textarea></div><button class="btn btn-primary accountability-inline-submit" type="submit"><i data-lucide="send" aria-hidden="true"></i>Salvar resposta</button></form></details>
                        @endif
                    </article>
                @empty
                    <div class="empty-state">Nenhuma diligência registrada.</div>
                @endforelse
            </div>
        </section>

        <section class="content-panel dossier-actions">
            <div><span class="dossier-icon"><i data-lucide="package-check" aria-hidden="true"></i></span><div><h2 class="h5 mb-1">Dossiê de auditoria</h2><p class="text-secondary mb-0">{{ $amendment->documents->count() }} documento(s), {{ $amendment->executionStages->count() }} etapa(s) e {{ $amendment->financialCommitments->count() }} empenho(s).</p></div></div>
            <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="{{ route('emendas.accountability.dossier.pdf', $amendment) }}"><i data-lucide="file-down" aria-hidden="true"></i>PDF</a><a class="btn btn-primary" href="{{ route('emendas.accountability.dossier.package', $amendment) }}"><i data-lucide="package" aria-hidden="true"></i>PDF + documentos</a></div>
        </section>
    @endif
@endsection
