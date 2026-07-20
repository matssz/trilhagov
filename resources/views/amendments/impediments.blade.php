@extends('layouts.app')

@section('title', 'Impedimentos '.$amendment->reference.' | TrilhaGov')

@section('content')
    <a class="back-link mb-3" href="{{ route('emendas.show', $amendment) }}">
        <i data-lucide="arrow-left" aria-hidden="true"></i>Voltar para a emenda
    </a>

    <div class="page-heading mb-4">
        <div>
            <p class="page-kicker mb-2">Controle técnico municipal</p>
            <h1 class="h3 mb-1">Impedimentos e diligências</h1>
            <p class="text-secondary mb-0">Emenda {{ $amendment->reference }} · {{ $amendment->object }}</p>
        </div>
        @if ($canEdit)
            <a class="btn btn-primary" href="#novo-impedimento">
                <i data-lucide="plus" aria-hidden="true"></i>Novo impedimento
            </a>
        @endif
    </div>

    <nav class="amendment-tabs mb-4" aria-label="Seções da emenda">
        <a href="{{ route('emendas.show', $amendment) }}">Visão geral</a>
        @if ($amendment->supportsTcespCompliance())
            <a href="{{ route('emendas.work-plan', $amendment) }}">Plano de trabalho</a>
        @endif
        <a class="active" href="{{ route('emendas.impediments', $amendment) }}" aria-current="page">Impedimentos</a>
        <a href="{{ route('emendas.execution', $amendment) }}">Execução</a>
        @if ($amendment->supportsTcespCompliance())
            <a href="{{ route('emendas.compliance', $amendment) }}">Conformidade TCESP</a>
        @endif
        <a href="{{ route('emendas.accountability', $amendment) }}">Prestação de contas</a>
    </nav>

    <x-validation-summary />
    @error('remapping')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror

    <section class="impediment-summary mb-4" aria-label="Resumo dos impedimentos">
        <div class="impediment-summary-primary">
            <span><i data-lucide="shield-alert" aria-hidden="true"></i></span>
            <div><small>Em acompanhamento</small><strong>{{ $summary['open'] }}</strong></div>
        </div>
        <div><small>Total registrado</small><strong>{{ $summary['total'] }}</strong></div>
        <div class="{{ $summary['overdue'] > 0 ? 'is-critical' : '' }}"><small>Prazos vencidos</small><strong>{{ $summary['overdue'] }}</strong></div>
        <div><small>Insuperáveis</small><strong>{{ $summary['insurmountable'] }}</strong></div>
    </section>

    <section class="content-panel mb-4">
        <div class="content-panel-header impediment-panel-heading">
            <div>
                <p class="page-kicker mb-1">Linha de decisão</p>
                <h2 class="h5 mb-0">Ocorrências registradas</h2>
            </div>
            <span>{{ $impediments->count() }} {{ $impediments->count() === 1 ? 'registro' : 'registros' }}</span>
        </div>

        @forelse ($impediments as $impediment)
            @php
                $activeRemapping = $impediment->remappings->first();
                $openDiligences = $impediment->diligences->where('status', 'open')->count();
            @endphp
            <article class="impediment-record" id="impedimento-{{ $impediment->id }}">
                <div class="impediment-record-rail status-{{ $impediment->status }}"></div>
                <div class="impediment-record-main">
                    <div class="impediment-record-head">
                        <div class="impediment-record-title">
                            <span class="impediment-category-icon"><i data-lucide="file-warning" aria-hidden="true"></i></span>
                            <div>
                                <div class="impediment-eyebrow">
                                    <span>{{ $impediment->categoryLabel() }}</span>
                                    <span class="impediment-nature nature-{{ $impediment->nature }}">{{ $impediment->natureLabel() }}</span>
                                </div>
                                <h3>{{ $impediment->title }}</h3>
                            </div>
                        </div>
                        <span class="impediment-status status-{{ $impediment->status }}">{{ $impediment->statusLabel() }}</span>
                    </div>

                    <p class="impediment-description">{{ $impediment->description }}</p>

                    <div class="impediment-facts">
                        <span><i data-lucide="user-round-check" aria-hidden="true"></i><small>Responsável</small><strong>{{ $impediment->assignedUser?->name ?? 'Não definido' }}</strong></span>
                        <span class="{{ ! $impediment->communicated_at && $impediment->communication_due_at?->isBefore(today()) ? 'is-overdue' : '' }}"><i data-lucide="send" aria-hidden="true"></i><small>Comunicação formal</small><strong>{{ $impediment->communicated_at?->format('d/m/Y') ?? ($impediment->communication_due_at ? 'Até '.$impediment->communication_due_at->format('d/m/Y') : 'Não parametrizada') }}</strong></span>
                        <span class="{{ $impediment->isOverdue() ? 'is-overdue' : '' }}"><i data-lucide="calendar-clock" aria-hidden="true"></i><small>Prazo</small><strong>{{ $impediment->resolution_due_at?->format('d/m/Y') ?? 'Não definido' }}</strong></span>
                        <span><i data-lucide="message-square" aria-hidden="true"></i><small>Diligências abertas</small><strong>{{ $openDiligences }}</strong></span>
                        <span><i data-lucide="route" aria-hidden="true"></i><small>Remanejamento</small><strong>{{ $activeRemapping?->statusLabel() ?? 'Não iniciado' }}</strong></span>
                    </div>

                    <details class="impediment-details">
                        <summary><span>Ver detalhes e ações</span><i data-lucide="chevron-down" aria-hidden="true"></i></summary>
                        <div class="impediment-details-body">
                            <div class="impediment-impact">
                                <i data-lucide="info" aria-hidden="true"></i>
                                <div><strong>Impacto identificado</strong><p>{{ $impediment->impact }}</p></div>
                            </div>

                            @if ($impediment->evidenceDocument)
                                <p class="evidence-link"><i data-lucide="paperclip" aria-hidden="true"></i>Evidência: <a href="{{ route('emendas.documents.download', [$amendment, $impediment->evidenceDocument]) }}">{{ $impediment->evidenceDocument->original_name }}</a></p>
                            @endif

                            @if ($canEdit)
                                <form class="impediment-update-form" method="POST" action="{{ route('emendas.impediments.update', [$amendment, $impediment]) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input name="_submission_token" type="hidden" value="{{ $updateTokens[$impediment->id] }}">
                                    <input name="identified_at" type="hidden" value="{{ $impediment->identified_at->format('Y-m-d') }}">
                                    <div>
                                        <label class="form-label" for="nature-{{ $impediment->id }}">Natureza</label>
                                        <select class="form-select" id="nature-{{ $impediment->id }}" name="nature" required>
                                            @foreach ($natures as $value => $label)<option value="{{ $value }}" @selected($impediment->nature === $value)>{{ $label }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label" for="status-{{ $impediment->id }}">Situação</label>
                                        <select class="form-select" id="status-{{ $impediment->id }}" name="status" required>
                                            @foreach ($availableStatuses as $value => $label)<option value="{{ $value }}" @selected($impediment->status === $value)>{{ $label }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label" for="assigned-{{ $impediment->id }}">Responsável</label>
                                        <select class="form-select" id="assigned-{{ $impediment->id }}" name="assigned_user_id">
                                            <option value="">Não definido</option>
                                            @foreach ($responsibleUsers as $user)<option value="{{ $user->id }}" @selected($impediment->assigned_user_id === $user->id)>{{ $user->name }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label" for="due-{{ $impediment->id }}">Prazo de resolução</label>
                                        <input class="form-control" id="due-{{ $impediment->id }}" name="resolution_due_at" type="date" value="{{ $impediment->resolution_due_at?->format('Y-m-d') }}">
                                    </div>
                                    <div>
                                        <label class="form-label" for="communicated-{{ $impediment->id }}">Comunicado em</label>
                                        <input class="form-control" id="communicated-{{ $impediment->id }}" name="communicated_at" type="date" value="{{ $impediment->communicated_at?->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}">
                                    </div>
                                    <div>
                                        <label class="form-label" for="communication-reference-{{ $impediment->id }}">Protocolo da comunicação</label>
                                        <input class="form-control" id="communication-reference-{{ $impediment->id }}" name="communication_reference" value="{{ $impediment->communication_reference }}" maxlength="180">
                                        @if ($impediment->communication_due_at)<div class="form-text">Prazo normativo: {{ $impediment->communication_due_at->format('d/m/Y') }}</div>@endif
                                    </div>
                                    <div class="span-2">
                                        <label class="form-label" for="evidence-{{ $impediment->id }}">Documento de evidência</label>
                                        <select class="form-select" id="evidence-{{ $impediment->id }}" name="evidence_document_id">
                                            <option value="">Sem documento vinculado</option>
                                            @foreach ($documents as $document)<option value="{{ $document->id }}" @selected($impediment->evidence_document_id === $document->id)>{{ $document->documentType->name }} · {{ $document->original_name }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div class="span-2">
                                        <label class="form-label" for="resolution-{{ $impediment->id }}">Fundamentação ou solução adotada</label>
                                        <textarea class="form-control" id="resolution-{{ $impediment->id }}" name="resolution_notes" rows="3" maxlength="5000">{{ $impediment->resolution_notes }}</textarea>
                                    </div>
                                    <button class="btn btn-primary span-2 justify-self-start" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar análise</button>
                                </form>
                            @endif

                            <section class="impediment-subsection">
                                <div class="impediment-subsection-heading">
                                    <div><i data-lucide="message-square" aria-hidden="true"></i><span><strong>Diligências técnicas</strong><small>Pedidos formais, respostas e protocolos</small></span></div>
                                    <span>{{ $impediment->diligences->count() }}</span>
                                </div>

                                @foreach ($impediment->diligences as $diligence)
                                    <div class="diligence-row {{ $diligence->isOverdue() ? 'is-overdue' : '' }}">
                                        <div>
                                            <div class="d-flex flex-wrap align-items-center gap-2"><strong>{{ $diligence->title }}</strong><span class="compact-status">{{ $diligence->statusLabel() }}</span></div>
                                            <small>Prazo {{ $diligence->due_at->format('d/m/Y') }} · {{ $diligence->assignedUser?->name ?? 'Sem responsável' }}</small>
                                            <p>{{ $diligence->request_details }}</p>
                                            @if ($diligence->response_notes)
                                                <p class="diligence-response"><strong>Resposta:</strong> {{ $diligence->response_notes }} <small>{{ $diligence->response_protocol ? '· '.$diligence->response_protocol : '' }}</small></p>
                                            @endif
                                        </div>
                                        @if ($canEdit)
                                            <form method="POST" action="{{ route('emendas.impediments.diligences.update', [$amendment, $impediment, $diligence]) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input name="_submission_token" type="hidden" value="{{ $diligenceUpdateTokens[$diligence->id] }}">
                                                <select class="form-select form-select-sm" name="status" aria-label="Situação da diligência" required>
                                                    @foreach (($isManager ? App\Models\TechnicalDiligence::statuses() : collect(App\Models\TechnicalDiligence::statuses())->only(['open', 'responded'])->all()) as $value => $label)<option value="{{ $value }}" @selected($diligence->status === $value)>{{ $label }}</option>@endforeach
                                                </select>
                                                <textarea class="form-control form-control-sm" name="response_notes" rows="2" maxlength="5000" placeholder="Resposta objetiva">{{ $diligence->response_notes }}</textarea>
                                                <input class="form-control form-control-sm" name="response_protocol" value="{{ $diligence->response_protocol }}" maxlength="120" placeholder="Protocolo da resposta">
                                                <select class="form-select form-select-sm" name="evidence_document_id" aria-label="Documento da resposta">
                                                    <option value="">Sem documento</option>
                                                    @foreach ($documents as $document)<option value="{{ $document->id }}" @selected($diligence->evidence_document_id === $document->id)>{{ $document->original_name }}</option>@endforeach
                                                </select>
                                                <button class="btn btn-sm btn-outline-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Atualizar</button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach

                                @if ($canEdit && $impediment->isOpen())
                                    <details class="inline-create">
                                        <summary><i data-lucide="plus" aria-hidden="true"></i>Abrir diligência</summary>
                                        <form method="POST" action="{{ route('emendas.impediments.diligences.store', [$amendment, $impediment]) }}">
                                            @csrf
                                            <input name="_submission_token" type="hidden" value="{{ $diligenceCreateTokens[$impediment->id] }}">
                                            <input class="form-control" name="title" maxlength="180" placeholder="Título da diligência" required>
                                            <textarea class="form-control span-2" name="request_details" rows="3" maxlength="5000" placeholder="O que precisa ser apresentado ou corrigido" required></textarea>
                                            <select class="form-select" name="assigned_user_id">
                                                <option value="">Sem responsável definido</option>
                                                @foreach ($responsibleUsers as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach
                                            </select>
                                            <input class="form-control" name="requested_at" type="date" value="{{ today()->format('Y-m-d') }}" aria-label="Data da solicitação" required>
                                            <input class="form-control" name="due_at" type="date" aria-label="Prazo da diligência" required>
                                            <button class="btn btn-primary" type="submit"><i data-lucide="send" aria-hidden="true"></i>Abrir diligência</button>
                                        </form>
                                    </details>
                                @endif
                            </section>

                            <section class="impediment-subsection remapping-section">
                                <div class="impediment-subsection-heading">
                                    <div><i data-lucide="route" aria-hidden="true"></i><span><strong>Remanejamento</strong><small>Alternativa para impedimento insuperável</small></span></div>
                                    @if ($activeRemapping)<span>{{ $activeRemapping->statusLabel() }}</span>@endif
                                </div>

                                @forelse ($impediment->remappings as $remapping)
                                    <article class="remapping-record">
                                        <div class="remapping-comparison">
                                            <div><small>Objeto original preservado</small><p>{{ $remapping->original_object }}</p></div>
                                            <i data-lucide="chevron-right" aria-hidden="true"></i>
                                            <div><small>Objeto proposto</small><p>{{ $remapping->proposed_object }}</p></div>
                                        </div>
                                        <div class="remapping-meta">
                                            <span>{{ $remapping->statusLabel() }}</span>
                                            <strong>R$ {{ number_format($remapping->amount, 2, ',', '.') }}</strong>
                                            @if ($remapping->decision_reference)<small>Decisão: {{ $remapping->decision_reference }}</small>@endif
                                        </div>
                                        <p class="mb-2"><strong>Justificativa:</strong> {{ $remapping->justification }}</p>
                                        @if ($remapping->decision_notes)<p class="decision-note"><strong>Fundamentação:</strong> {{ $remapping->decision_notes }}</p>@endif

                                        @if ($canEdit && $remapping->status === App\Models\AmendmentRemapping::STATUS_DRAFT)
                                            <form class="remapping-draft-form" method="POST" action="{{ route('emendas.impediments.remappings.update', [$amendment, $impediment, $remapping]) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input name="_submission_token" type="hidden" value="{{ $remappingUpdateTokens[$remapping->id] }}">
                                                <textarea class="form-control span-2" name="proposed_object" rows="3" maxlength="5000" aria-label="Objeto proposto" required>{{ $remapping->proposed_object }}</textarea>
                                                <textarea class="form-control span-2" name="justification" rows="3" maxlength="5000" aria-label="Justificativa do remanejamento" required>{{ $remapping->justification }}</textarea>
                                                <input class="form-control" name="amount" type="number" step="0.01" min="0.01" max="9999999999999.99" value="{{ $remapping->amount }}" aria-label="Valor a remanejar" required>
                                                <button class="btn btn-outline-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar rascunho</button>
                                            </form>
                                            <form method="POST" action="{{ route('emendas.impediments.remappings.submit', [$amendment, $impediment, $remapping]) }}">
                                                @csrf
                                                <input name="_submission_token" type="hidden" value="{{ $remappingSubmitTokens[$remapping->id] }}">
                                                <button class="btn btn-primary" type="submit"><i data-lucide="send" aria-hidden="true"></i>Enviar para decisão</button>
                                            </form>
                                        @endif

                                        @if ($isManager && $remapping->status === App\Models\AmendmentRemapping::STATUS_SUBMITTED)
                                            <form class="remapping-decision" method="POST" action="{{ route('emendas.impediments.remappings.decide', [$amendment, $impediment, $remapping]) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input name="_submission_token" type="hidden" value="{{ $remappingDecisionTokens[$remapping->id] }}">
                                                <select class="form-select" name="status" required>
                                                    <option value="approved">Aprovar remanejamento</option>
                                                    <option value="rejected">Rejeitar remanejamento</option>
                                                </select>
                                                <input class="form-control" name="decision_reference" maxlength="160" placeholder="Ato, processo ou protocolo" required>
                                                <textarea class="form-control span-2" name="decision_notes" rows="3" maxlength="5000" placeholder="Fundamentação da decisão" required></textarea>
                                                <button class="btn btn-primary" type="submit"><i data-lucide="badge-check" aria-hidden="true"></i>Registrar decisão</button>
                                            </form>
                                        @endif
                                    </article>
                                @empty
                                    @if ($canEdit && $impediment->nature === App\Models\TechnicalImpediment::NATURE_INSURMOUNTABLE)
                                        <details class="inline-create">
                                            <summary><i data-lucide="plus" aria-hidden="true"></i>Propor remanejamento</summary>
                                            <form method="POST" action="{{ route('emendas.impediments.remappings.store', [$amendment, $impediment]) }}">
                                                @csrf
                                                <input name="_submission_token" type="hidden" value="{{ $remappingCreateTokens[$impediment->id] }}">
                                                <textarea class="form-control span-2" name="proposed_object" rows="4" maxlength="5000" placeholder="Novo objeto proposto" required></textarea>
                                                <textarea class="form-control span-2" name="justification" rows="3" maxlength="5000" placeholder="Justificativa técnica e interesse público preservado" required></textarea>
                                                <div><label class="form-label">Valor a remanejar</label><input class="form-control" name="amount" type="number" step="0.01" min="0.01" max="9999999999999.99" value="{{ $amendment->expected_amount }}" required></div>
                                                <button class="btn btn-primary align-self-end" type="submit"><i data-lucide="save" aria-hidden="true"></i>Criar proposta</button>
                                            </form>
                                        </details>
                                    @else
                                        <p class="subsection-empty">Classifique como insuperável para habilitar uma proposta, sempre com fundamentação.</p>
                                    @endif
                                @endforelse

                                @if ($canEdit
                                    && $impediment->nature === App\Models\TechnicalImpediment::NATURE_INSURMOUNTABLE
                                    && $impediment->remappings->isNotEmpty()
                                    && $impediment->remappings->whereIn('status', ['draft', 'submitted', 'approved'])->isEmpty())
                                    <details class="inline-create">
                                        <summary><i data-lucide="plus" aria-hidden="true"></i>Propor novo remanejamento</summary>
                                        <form method="POST" action="{{ route('emendas.impediments.remappings.store', [$amendment, $impediment]) }}">
                                            @csrf
                                            <input name="_submission_token" type="hidden" value="{{ $remappingCreateTokens[$impediment->id] }}">
                                            <textarea class="form-control span-2" name="proposed_object" rows="4" maxlength="5000" placeholder="Novo objeto proposto" required></textarea>
                                            <textarea class="form-control span-2" name="justification" rows="3" maxlength="5000" placeholder="Justificativa técnica e interesse público preservado" required></textarea>
                                            <input class="form-control" name="amount" type="number" step="0.01" min="0.01" max="9999999999999.99" value="{{ $amendment->expected_amount }}" aria-label="Valor a remanejar" required>
                                            <button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Criar proposta</button>
                                        </form>
                                    </details>
                                @endif
                            </section>
                        </div>
                    </details>
                </div>
            </article>
        @empty
            <div class="empty-state impediment-empty">
                <span><i data-lucide="shield-check" aria-hidden="true"></i></span>
                <div><strong>Nenhum impedimento registrado</strong><p class="mb-0">A emenda não possui ocorrências técnicas formalizadas neste momento.</p></div>
            </div>
        @endforelse
    </section>

    @if ($canEdit)
        <section class="content-panel pearl-panel" id="novo-impedimento">
            <div class="content-panel-header">
                <p class="page-kicker mb-1">Nova ocorrência</p>
                <h2 class="h5 mb-0">Registrar impedimento</h2>
            </div>
            <div class="content-panel-body">
                <form class="impediment-create-form" method="POST" action="{{ route('emendas.impediments.store', $amendment) }}">
                    @csrf
                    <input name="_submission_token" type="hidden" value="{{ $createToken }}">
                    <div>
                        <label class="form-label" for="category">Categoria <span class="required-mark">*</span></label>
                        <select class="form-select" id="category" name="category" required><option value="">Selecione</option>@foreach ($categories as $value => $label)<option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>@endforeach</select>
                    </div>
                    <div>
                        <label class="form-label" for="nature">Natureza inicial <span class="required-mark">*</span></label>
                        <select class="form-select" id="nature" name="nature" required>@foreach ($natures as $value => $label)<option value="{{ $value }}" @selected(old('nature', 'under_analysis') === $value)>{{ $label }}</option>@endforeach</select>
                    </div>
                    <div>
                        <label class="form-label" for="assigned_user_id">Responsável</label>
                        <select class="form-select" id="assigned_user_id" name="assigned_user_id"><option value="">Definir depois</option>@foreach ($responsibleUsers as $user)<option value="{{ $user->id }}" @selected((string) old('assigned_user_id') === (string) $user->id)>{{ $user->name }}</option>@endforeach</select>
                    </div>
                    <div>
                        <label class="form-label" for="identified_at">Identificado em <span class="required-mark">*</span></label>
                        <input class="form-control" id="identified_at" name="identified_at" type="date" value="{{ old('identified_at', today()->format('Y-m-d')) }}" required>
                        @if ($suggestedCommunicationDueAt)
                            <div class="form-text">Comunicação formal sugerida até {{ \Illuminate\Support\Carbon::parse($suggestedCommunicationDueAt)->format('d/m/Y') }}.</div>
                        @endif
                    </div>
                    <div>
                        <label class="form-label" for="resolution_due_at">Prazo de resolução</label>
                        <input class="form-control" id="resolution_due_at" name="resolution_due_at" type="date" value="{{ old('resolution_due_at', $suggestedResolutionDueAt) }}">
                        @if ($regulatoryProfile?->impediment_correction_days !== null)
                            <div class="form-text">Sugestão da norma {{ $regulatoryProfile->fiscal_year }}/v{{ $regulatoryProfile->version }}: {{ $regulatoryProfile->impediment_correction_days }} dia(s) para saneamento.</div>
                        @endif
                    </div>
                    <div>
                        <label class="form-label" for="evidence_document_id">Evidência inicial</label>
                        <select class="form-select" id="evidence_document_id" name="evidence_document_id"><option value="">Sem documento vinculado</option>@foreach ($documents as $document)<option value="{{ $document->id }}" @selected((string) old('evidence_document_id') === (string) $document->id)>{{ $document->documentType->name }} · {{ $document->original_name }}</option>@endforeach</select>
                    </div>
                    <div class="span-2">
                        <label class="form-label" for="title">Título objetivo <span class="required-mark">*</span></label>
                        <input class="form-control" id="title" name="title" value="{{ old('title') }}" maxlength="180" required>
                    </div>
                    <div class="span-2">
                        <label class="form-label" for="description">Constatação técnica <span class="required-mark">*</span></label>
                        <textarea class="form-control" id="description" name="description" rows="4" maxlength="5000" required>{{ old('description') }}</textarea>
                    </div>
                    <div class="span-2">
                        <label class="form-label" for="impact">Impacto na execução <span class="required-mark">*</span></label>
                        <textarea class="form-control" id="impact" name="impact" rows="3" maxlength="5000" required>{{ old('impact') }}</textarea>
                    </div>
                    <button class="btn btn-primary span-2 justify-self-start" type="submit"><i data-lucide="plus" aria-hidden="true"></i>Registrar impedimento</button>
                </form>
            </div>
        </section>
    @endif
@endsection
