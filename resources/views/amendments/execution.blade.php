@extends('layouts.app')

@section('title', 'Execução ' . $amendment->reference . ' | TrilhaGov')

@section('content')
    <a class="d-inline-flex align-items-center gap-2 mb-3" href="{{ route('emendas.index') }}">
        <i data-lucide="arrow-left" aria-hidden="true"></i>Voltar para emendas
    </a>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-3">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <p class="page-kicker mb-0">Execução física e financeira</p>
                <x-amendment-status-badge :status="$amendment->status" :label="$amendment->statusLabel()" />
                <x-risk-badge :level="$amendment->risk_level" :label="$amendment->riskLabel()" :score="$amendment->risk_score" />
            </div>
            <h1 class="h3 mb-1">{{ $amendment->reference }}</h1>
            <p class="text-secondary mb-0">{{ $amendment->object }}</p>
        </div>
        @if ($canEdit)
            <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newStageForm" aria-expanded="false" aria-controls="newStageForm">
                <i data-lucide="plus" aria-hidden="true"></i>Nova etapa
            </button>
        @endif
    </div>

    <nav class="amendment-tabs mb-4" aria-label="Seções da emenda">
        <a href="{{ route('emendas.show', $amendment) }}">Visão geral</a>
        <a class="active" href="{{ route('emendas.execution', $amendment) }}" aria-current="page">Execução</a>
        @if ($amendment->supportsTcespCompliance())
            <a href="{{ route('emendas.compliance', $amendment) }}">Conformidade TCESP</a>
        @endif
        <a href="{{ route('emendas.accountability', $amendment) }}">Prestação de contas</a>
    </nav>

    <x-validation-summary />

    @if ($availableBalance < 0 || $uncommittedBalance < 0)
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
            <i data-lucide="triangle-alert" aria-hidden="true"></i>
            <div>
                <strong>Divergência financeira detectada.</strong>
                <div>Os registros de empenho ou pagamento ultrapassam o valor recebido. Confira os lançamentos antes de prosseguir.</div>
            </div>
        </div>
    @elseif ($amendment->received_amount === null)
        <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
            <i data-lucide="triangle-alert" aria-hidden="true"></i>
            <div><strong>Valor recebido não informado.</strong> A conciliação ficará incompleta até esse dado ser registrado na emenda.</div>
        </div>
    @endif

    <div class="metric-grid execution-metrics mb-4">
        @foreach ([
            ['icon' => 'landmark', 'label' => 'Recebido', 'value' => $receivedAmount, 'class' => ''],
            ['icon' => 'briefcase-business', 'label' => 'Empenhado', 'value' => $committedAmount, 'class' => $uncommittedBalance < 0 ? 'metric-danger' : ''],
            ['icon' => 'receipt-text', 'label' => 'Pago', 'value' => $paidAmount, 'class' => $availableBalance < 0 ? 'metric-danger' : ''],
            ['icon' => 'wallet-cards', 'label' => 'Saldo disponível', 'value' => $availableBalance, 'class' => $availableBalance < 0 ? 'metric-danger' : ''],
        ] as $metric)
            <article class="metric-card {{ $metric['class'] }}">
                <span class="metric-icon"><i data-lucide="{{ $metric['icon'] }}" aria-hidden="true"></i></span>
                <div class="metric-label">{{ $metric['label'] }}</div>
                <div class="metric-value">R$ {{ number_format($metric['value'], 2, ',', '.') }}</div>
            </article>
        @endforeach
    </div>

    <section class="content-panel mb-4">
        <div class="content-panel-header d-flex align-items-center gap-2">
            <i data-lucide="gauge" aria-hidden="true"></i>
            <h2 class="h5 mb-0">Avanço consolidado</h2>
        </div>
        <div class="execution-progress-grid">
            <div class="execution-progress-item">
                <div class="d-flex justify-content-between gap-3">
                    <strong>Execução física</strong><span>{{ $physicalPercentage }}%</span>
                </div>
                <div class="execution-progress" role="progressbar" aria-label="Execução física" aria-valuenow="{{ $physicalPercentage }}" aria-valuemin="0" aria-valuemax="100">
                    <span style="width: {{ min(100, $physicalPercentage) }}%"></span>
                </div>
                <small>Média do progresso informado nas {{ $amendment->executionStages->count() }} etapa(s).</small>
            </div>
            <div class="execution-progress-item">
                <div class="d-flex justify-content-between gap-3">
                    <strong>Execução financeira</strong><span>{{ $financialPercentage }}%</span>
                </div>
                <div class="execution-progress financial" role="progressbar" aria-label="Execução financeira" aria-valuenow="{{ $financialPercentage }}" aria-valuemin="0" aria-valuemax="100">
                    <span style="width: {{ min(100, $financialPercentage) }}%"></span>
                </div>
                <small>Pagamentos registrados em relação ao valor recebido.</small>
            </div>
        </div>
    </section>

    <section class="content-panel mb-4" id="stages">
        <div class="content-panel-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <i data-lucide="clipboard-check" aria-hidden="true"></i>
                <h2 class="h5 mb-0">Etapas e entregas</h2>
            </div>
            <span class="small text-secondary">{{ $amendment->executionStages->count() }} cadastrada(s)</span>
        </div>

        @if ($canEdit)
            <div class="collapse {{ $errors->has('title') ? 'show' : '' }}" id="newStageForm">
                <form class="execution-form-band" method="POST" action="{{ route('emendas.stages.store', $amendment) }}" novalidate>
                    @csrf
                    <input name="_submission_token" type="hidden" value="{{ $stageCreateToken }}">
                    <div class="execution-form-heading">
                        <strong>Nova etapa física</strong>
                        <small>Divida o objeto em entregas verificáveis.</small>
                    </div>
                    <div class="stage-form-grid">
                        <div class="span-2">
                            <label class="form-label" for="stage_title">Título <span class="required-mark">*</span></label>
                            <input class="form-control @error('title') is-invalid @enderror" id="stage_title" name="title" value="{{ old('title') }}" maxlength="160" required>
                            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="stage_responsible">Responsável</label>
                            <select class="form-select @error('responsible_user_id') is-invalid @enderror" id="stage_responsible" name="responsible_user_id">
                                <option value="">Responsável da emenda</option>
                                @foreach ($responsibleUsers as $user)
                                    <option value="{{ $user->id }}" @selected((string) old('responsible_user_id') === (string) $user->id)>{{ $user->name }}</option>
                                @endforeach
                            </select>
                            @error('responsible_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="stage_amount">Valor planejado</label>
                            <input class="form-control @error('planned_amount') is-invalid @enderror" id="stage_amount" name="planned_amount" type="number" value="{{ old('planned_amount') }}" min="0" step="0.01">
                            @error('planned_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="stage_start">Início previsto</label>
                            <input class="form-control @error('planned_start_at') is-invalid @enderror" id="stage_start" name="planned_start_at" type="date" value="{{ old('planned_start_at') }}">
                            @error('planned_start_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="stage_end">Conclusão prevista</label>
                            <input class="form-control @error('planned_end_at') is-invalid @enderror" id="stage_end" name="planned_end_at" type="date" value="{{ old('planned_end_at') }}">
                            @error('planned_end_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label class="form-label" for="stage_order">Ordem <span class="required-mark">*</span></label>
                            <input class="form-control @error('sort_order') is-invalid @enderror" id="stage_order" name="sort_order" type="number" value="{{ old('sort_order', 10) }}" min="0" max="65000" required>
                            @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <input name="status" type="hidden" value="planned">
                        <input name="progress_percentage" type="hidden" value="0">
                        <div class="span-full">
                            <label class="form-label" for="stage_description">Entrega esperada</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" id="stage_description" name="description" rows="2" maxlength="2000">{{ old('description') }}</textarea>
                            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3">
                        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#newStageForm">Cancelar</button>
                        <button class="btn btn-primary" type="submit"><i data-lucide="check" aria-hidden="true"></i>Criar etapa</button>
                    </div>
                </form>
            </div>
        @endif

        <div class="execution-stage-list">
            @forelse ($amendment->executionStages as $stage)
                <article class="execution-stage-row {{ $stage->isOverdue() ? 'is-overdue' : '' }}" id="etapa-{{ $stage->id }}">
                    <div class="stage-order">{{ $loop->iteration }}</div>
                    <div class="stage-main">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <h3 class="h6 mb-0">{{ $stage->title }}</h3>
                            <span class="stage-status stage-status-{{ $stage->status }}">{{ $stage->statusLabel() }}</span>
                            @if ($stage->isOverdue())<span class="badge text-bg-danger">Atrasada</span>@endif
                        </div>
                        @if ($stage->description)<p>{{ $stage->description }}</p>@endif
                        <div class="stage-meta">
                            <span><i data-lucide="user-round-check" aria-hidden="true"></i>{{ $stage->responsibleUser?->name ?? $amendment->responsibleUser?->name ?? 'Não definido' }}</span>
                            <span><i data-lucide="calendar-clock" aria-hidden="true"></i>{{ $stage->planned_end_at?->format('d/m/Y') ?? 'Sem prazo' }}</span>
                            @if ($stage->planned_amount !== null)<span><i data-lucide="circle-dollar-sign" aria-hidden="true"></i>R$ {{ number_format($stage->planned_amount, 2, ',', '.') }}</span>@endif
                            <span><i data-lucide="file-check-2" aria-hidden="true"></i>{{ $stage->documents->count() }} evidência(s)</span>
                        </div>
                        <div class="execution-progress compact" role="progressbar" aria-label="Progresso de {{ $stage->title }}" aria-valuenow="{{ $stage->progress_percentage }}" aria-valuemin="0" aria-valuemax="100">
                            <span style="width: {{ $stage->progress_percentage }}%"></span>
                        </div>
                    </div>
                    <strong class="stage-percentage">{{ $stage->progress_percentage }}%</strong>
                    @if ($canEdit)
                        <details class="stage-editor">
                            <summary title="Editar etapa" aria-label="Editar etapa"><i data-lucide="pencil" aria-hidden="true"></i></summary>
                            <form method="POST" action="{{ route('emendas.stages.update', [$amendment, $stage]) }}" novalidate>
                                @csrf
                                @method('PATCH')
                                <input name="_submission_token" type="hidden" value="{{ $stageUpdateTokens->get($stage->id) }}">
                                <div class="stage-edit-grid">
                                    <div class="span-2"><label class="form-label" for="stage_title_{{ $stage->id }}">Título</label><input class="form-control" id="stage_title_{{ $stage->id }}" name="title" value="{{ $stage->title }}" required></div>
                                    <div><label class="form-label" for="stage_status_{{ $stage->id }}">Situação</label><select class="form-select" id="stage_status_{{ $stage->id }}" name="status" required>@foreach ($stageStatuses as $value => $label)<option value="{{ $value }}" @selected($stage->status === $value)>{{ $label }}</option>@endforeach</select></div>
                                    <div><label class="form-label" for="stage_progress_{{ $stage->id }}">Progresso (%)</label><input class="form-control" id="stage_progress_{{ $stage->id }}" name="progress_percentage" type="number" value="{{ $stage->progress_percentage }}" min="0" max="100" required></div>
                                    <div><label class="form-label" for="stage_responsible_{{ $stage->id }}">Responsável</label><select class="form-select" id="stage_responsible_{{ $stage->id }}" name="responsible_user_id"><option value="">Responsável da emenda</option>@foreach ($responsibleUsers as $user)<option value="{{ $user->id }}" @selected($stage->responsible_user_id === $user->id)>{{ $user->name }}</option>@endforeach</select></div>
                                    <div><label class="form-label" for="stage_amount_{{ $stage->id }}">Valor planejado</label><input class="form-control" id="stage_amount_{{ $stage->id }}" name="planned_amount" type="number" value="{{ $stage->planned_amount }}" min="0" step="0.01"></div>
                                    <div><label class="form-label" for="stage_start_{{ $stage->id }}">Início previsto</label><input class="form-control" id="stage_start_{{ $stage->id }}" name="planned_start_at" type="date" value="{{ $stage->planned_start_at?->toDateString() }}"></div>
                                    <div><label class="form-label" for="stage_end_{{ $stage->id }}">Conclusão prevista</label><input class="form-control" id="stage_end_{{ $stage->id }}" name="planned_end_at" type="date" value="{{ $stage->planned_end_at?->toDateString() }}"></div>
                                    <div><label class="form-label" for="stage_order_{{ $stage->id }}">Ordem</label><input class="form-control" id="stage_order_{{ $stage->id }}" name="sort_order" type="number" value="{{ $stage->sort_order }}" min="0" max="65000" required></div>
                                    <div class="span-full"><label class="form-label" for="stage_description_{{ $stage->id }}">Entrega esperada</label><textarea class="form-control" id="stage_description_{{ $stage->id }}" name="description" rows="2">{{ $stage->description }}</textarea></div>
                                </div>
                                <div class="text-end mt-3"><button class="btn btn-sm btn-primary" type="submit"><i data-lucide="check" aria-hidden="true"></i>Salvar etapa</button></div>
                            </form>
                        </details>
                    @endif
                </article>
            @empty
                <div class="empty-state">Cadastre as entregas que comprovam a execução do objeto da emenda.</div>
            @endforelse
        </div>
    </section>

    <section class="content-panel mb-4" id="commitments">
        <div class="content-panel-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
            <div class="d-flex align-items-center gap-2"><i data-lucide="briefcase-business" aria-hidden="true"></i><h2 class="h5 mb-0">Empenhos e pagamentos</h2></div>
            @if ($canEdit)
                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newCommitmentForm" aria-expanded="false" aria-controls="newCommitmentForm"><i data-lucide="plus" aria-hidden="true"></i>Novo empenho</button>
            @endif
        </div>

        @if ($canEdit)
            <div class="collapse {{ $errors->has('commitment_number') ? 'show' : '' }}" id="newCommitmentForm">
                <form class="execution-form-band" method="POST" action="{{ route('emendas.commitments.store', $amendment) }}" novalidate>
                    @csrf
                    <input name="_submission_token" type="hidden" value="{{ $commitmentCreateToken }}">
                    <div class="execution-form-heading"><strong>Registrar empenho</strong><small>Use os mesmos números do processo administrativo e do sistema contábil.</small></div>
                    <div class="commitment-form-grid">
                        <div><label class="form-label" for="commitment_number">Número <span class="required-mark">*</span></label><input class="form-control @error('commitment_number') is-invalid @enderror" id="commitment_number" name="commitment_number" value="{{ old('commitment_number') }}" required>@error('commitment_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div><label class="form-label" for="procurement_process">Processo de contratação <span class="required-mark">*</span></label><input class="form-control @error('procurement_process') is-invalid @enderror" id="procurement_process" name="procurement_process" value="{{ old('procurement_process') }}" required>@error('procurement_process')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div><label class="form-label" for="commitment_stage">Etapa vinculada</label><select class="form-select @error('execution_stage_id') is-invalid @enderror" id="commitment_stage" name="execution_stage_id"><option value="">Sem etapa específica</option>@foreach ($amendment->executionStages as $stage)<option value="{{ $stage->id }}" @selected((string) old('execution_stage_id') === (string) $stage->id)>{{ $stage->title }}</option>@endforeach</select>@error('execution_stage_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div><label class="form-label" for="supplier_name">Fornecedor <span class="required-mark">*</span></label><input class="form-control @error('supplier_name') is-invalid @enderror" id="supplier_name" name="supplier_name" value="{{ old('supplier_name') }}" required>@error('supplier_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div><label class="form-label" for="supplier_document">CPF ou CNPJ</label><input class="form-control @error('supplier_document') is-invalid @enderror" id="supplier_document" name="supplier_document" value="{{ old('supplier_document') }}" inputmode="numeric">@error('supplier_document')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div><label class="form-label" for="committed_amount">Valor empenhado <span class="required-mark">*</span></label><input class="form-control @error('committed_amount') is-invalid @enderror" id="committed_amount" name="committed_amount" type="number" value="{{ old('committed_amount') }}" min="0.01" step="0.01" required>@error('committed_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div><label class="form-label" for="committed_at">Data do empenho <span class="required-mark">*</span></label><input class="form-control @error('committed_at') is-invalid @enderror" id="committed_at" name="committed_at" type="date" value="{{ old('committed_at') }}" required>@error('committed_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="span-full"><label class="form-label" for="object_description">Vínculo com o objeto da emenda <span class="required-mark">*</span></label><textarea class="form-control @error('object_description') is-invalid @enderror" id="object_description" name="object_description" rows="2" maxlength="2000" required>{{ old('object_description') }}</textarea>@error('object_description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-3"><button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#newCommitmentForm">Cancelar</button><button class="btn btn-primary" type="submit"><i data-lucide="check" aria-hidden="true"></i>Registrar empenho</button></div>
                </form>
            </div>
        @endif

        <div class="commitment-list">
            @forelse ($amendment->financialCommitments as $commitment)
                @php($commitmentPaid = (float) $commitment->payments->sum('amount'))
                @php($commitmentRemaining = max(0, (float) $commitment->committed_amount - $commitmentPaid))
                <article class="commitment-row {{ $commitment->status === 'cancelled' ? 'is-cancelled' : '' }}">
                    <div class="commitment-summary">
                        <div class="min-width-0">
                            <div class="d-flex flex-wrap align-items-center gap-2"><h3 class="h6 mb-0">Empenho {{ $commitment->commitment_number }}</h3>@if ($commitment->status === 'cancelled')<span class="badge text-bg-secondary">Cancelado</span>@elseif ($commitmentRemaining <= 0)<span class="badge text-bg-success">Pago</span>@else<span class="badge text-bg-warning">Com saldo</span>@endif</div>
                            <p class="mb-1">{{ $commitment->supplier_name }}{{ $commitment->supplier_document ? ' · '.$commitment->supplier_document : '' }}</p>
                            <small>Processo {{ $commitment->procurement_process }} · {{ $commitment->committed_at->format('d/m/Y') }}{{ $commitment->executionStage ? ' · '.$commitment->executionStage->title : '' }}</small>
                        </div>
                        <div class="commitment-values"><span><small>Empenhado</small><strong>R$ {{ number_format($commitment->committed_amount, 2, ',', '.') }}</strong></span><span><small>Pago</small><strong>R$ {{ number_format($commitmentPaid, 2, ',', '.') }}</strong></span><span><small>Saldo</small><strong>R$ {{ number_format($commitmentRemaining, 2, ',', '.') }}</strong></span></div>
                    </div>
                    <details class="commitment-details">
                        <summary><span>Ver movimentações e objeto</span><i data-lucide="chevron-down" aria-hidden="true"></i></summary>
                        <div class="commitment-detail-body">
                            <div class="commitment-object"><strong>Vínculo com o objeto</strong><p>{{ $commitment->object_description }}</p>@if ($commitment->cancellation_reason)<div class="alert alert-secondary mb-0"><strong>Motivo do cancelamento:</strong> {{ $commitment->cancellation_reason }}</div>@endif</div>
                            @if ($commitment->payments->isNotEmpty())
                                <div class="payment-list">
                                    @foreach ($commitment->payments as $payment)
                                        <div><span><strong>{{ $payment->payment_reference }}</strong><small>{{ $payment->paid_at->format('d/m/Y') }} · {{ $payment->creator->name }}</small></span><strong>R$ {{ number_format($payment->amount, 2, ',', '.') }}</strong></div>
                                    @endforeach
                                </div>
                            @else
                                <p class="small text-secondary">Nenhum pagamento registrado.</p>
                            @endif

                            @if ($canEdit && $commitment->status === 'active' && $commitmentRemaining > 0)
                                <form class="payment-form" method="POST" action="{{ route('emendas.payments.store', [$amendment, $commitment]) }}" novalidate>
                                    @csrf
                                    <input name="_submission_token" type="hidden" value="{{ $paymentCreateTokens->get($commitment->id) }}">
                                    <div><label class="form-label" for="payment_reference_{{ $commitment->id }}">Referência <span class="required-mark">*</span></label><input class="form-control" id="payment_reference_{{ $commitment->id }}" name="payment_reference" required></div>
                                    <div><label class="form-label" for="payment_amount_{{ $commitment->id }}">Valor <span class="required-mark">*</span></label><input class="form-control" id="payment_amount_{{ $commitment->id }}" name="amount" type="number" min="0.01" max="{{ $commitmentRemaining }}" step="0.01" required></div>
                                    <div><label class="form-label" for="paid_at_{{ $commitment->id }}">Data <span class="required-mark">*</span></label><input class="form-control" id="paid_at_{{ $commitment->id }}" name="paid_at" type="date" min="{{ $commitment->committed_at->toDateString() }}" required></div>
                                    <div><label class="form-label" for="payment_notes_{{ $commitment->id }}">Observação</label><input class="form-control" id="payment_notes_{{ $commitment->id }}" name="notes" maxlength="500"></div>
                                    <button class="btn btn-primary payment-submit" type="submit"><i data-lucide="receipt-text" aria-hidden="true"></i>Registrar pagamento</button>
                                </form>
                            @endif

                            @if ($canEdit && $commitment->status === 'active' && $commitment->payments->isEmpty())
                                <details class="cancel-commitment mt-3"><summary>Cancelar empenho</summary><form class="d-flex flex-column flex-md-row align-items-md-end gap-2 mt-2" method="POST" action="{{ route('emendas.commitments.cancel', [$amendment, $commitment]) }}">@csrf @method('PATCH')<input name="_submission_token" type="hidden" value="{{ $commitmentCancelTokens->get($commitment->id) }}"><div class="flex-grow-1"><label class="form-label" for="cancellation_reason_{{ $commitment->id }}">Motivo <span class="required-mark">*</span></label><input class="form-control" id="cancellation_reason_{{ $commitment->id }}" name="cancellation_reason" maxlength="1000" required></div><button class="btn btn-outline-danger" type="submit"><i data-lucide="circle-x" aria-hidden="true"></i>Confirmar cancelamento</button></form></details>
                            @endif
                        </div>
                    </details>
                </article>
            @empty
                <div class="empty-state">Nenhum empenho registrado para esta emenda.</div>
            @endforelse
        </div>
    </section>

    <section class="content-panel" id="evidence">
        <div class="content-panel-header d-flex align-items-center gap-2"><i data-lucide="file-check-2" aria-hidden="true"></i><h2 class="h5 mb-0">Evidências de entrega</h2></div>
        @if ($canEdit && $documentTypes->isNotEmpty() && $amendment->executionStages->isNotEmpty())
            <form class="execution-evidence-form" method="POST" action="{{ route('emendas.documents.store', $amendment) }}" enctype="multipart/form-data" novalidate>
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $documentSubmissionToken }}">
                <div><label class="form-label" for="evidence_stage">Etapa <span class="required-mark">*</span></label><select class="form-select" id="evidence_stage" name="execution_stage_id" required><option value="">Selecione</option>@foreach ($amendment->executionStages as $stage)<option value="{{ $stage->id }}">{{ $stage->title }}</option>@endforeach</select></div>
                <div><label class="form-label" for="evidence_type">Tipo <span class="required-mark">*</span></label><select class="form-select" id="evidence_type" name="document_type_id" required><option value="">Selecione</option>@foreach ($documentTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></div>
                <div><label class="form-label" for="evidence_file">Arquivo <span class="required-mark">*</span></label><input class="form-control" id="evidence_file" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx,.csv,.doc,.docx" required></div>
                <div><label class="form-label" for="evidence_notes">Observação</label><input class="form-control" id="evidence_notes" name="notes" maxlength="500"></div>
                <button class="btn btn-primary evidence-submit" type="submit"><i data-lucide="upload" aria-hidden="true"></i>Anexar evidência</button>
            </form>
        @elseif ($canEdit)
            <div class="checklist-warning"><i data-lucide="triangle-alert" aria-hidden="true"></i><span>Cadastre ao menos uma etapa e um tipo de documento para anexar evidências.</span></div>
        @endif

        <div class="evidence-list">
            @forelse ($amendment->documents->whereNotNull('execution_stage_id') as $document)
                <div class="evidence-row"><span class="checklist-state complete"><i data-lucide="file-check-2" aria-hidden="true"></i></span><span class="min-width-0"><strong>{{ $document->executionStage?->title ?? 'Etapa removida' }}</strong><small>{{ $document->documentType->name }} · {{ $document->original_name }} · versão {{ $document->version }}</small></span><a class="btn btn-sm btn-outline-primary" href="{{ route('emendas.documents.download', [$amendment, $document]) }}" title="Baixar evidência"><i data-lucide="download" aria-hidden="true"></i><span>Baixar</span></a></div>
            @empty
                <div class="empty-state">Ainda não há evidências vinculadas às etapas.</div>
            @endforelse
        </div>
    </section>
@endsection
