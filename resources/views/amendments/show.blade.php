@extends('layouts.app')

@section('title', $amendment->reference . ' | TrilhaGov')

@section('content')
    <a class="d-inline-block mb-3" href="{{ route('emendas.index') }}">Voltar para emendas</a>

    @if ($amendment->hasOverdueDeadline())
        <div class="alert alert-danger" role="alert">
            Há prazo vencido nesta emenda. Confira o marco e atualize a situação.
        </div>
    @elseif ($amendment->hasUpcomingDeadline())
        <div class="alert alert-warning" role="alert">
            Há prazo previsto para os próximos 30 dias.
        </div>
    @endif

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <p class="page-kicker mb-0">Emenda {{ $amendment->fiscal_year }}</p>
                <x-amendment-status-badge :status="$amendment->status" :label="$amendment->statusLabel()" />
                <x-risk-badge :level="$amendment->risk_level" :label="$amendment->riskLabel()" :score="$amendment->risk_score" />
            </div>
            <h1 class="h3 mb-1">{{ $amendment->reference }}</h1>
            <p class="text-secondary mb-0">{{ $amendment->municipality->name }} / {{ $amendment->municipality->state }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if ($amendment->supportsTcespCompliance())
                <a class="btn btn-outline-primary" href="{{ route('emendas.work-plan', $amendment) }}"><i data-lucide="clipboard-list" aria-hidden="true"></i>Plano de trabalho</a>
                <a class="btn btn-outline-primary" href="{{ route('emendas.compliance', $amendment) }}"><i data-lucide="badge-check" aria-hidden="true"></i>Conferir TCESP</a>
            @endif
            <a class="btn btn-outline-primary" href="{{ route('emendas.execution', $amendment) }}"><i data-lucide="gauge" aria-hidden="true"></i>Acompanhar execução</a>
            @if ($canEdit)
                <a class="btn btn-primary" href="{{ route('emendas.edit', $amendment) }}">Editar emenda</a>
            @endif
        </div>
    </div>

    <nav class="amendment-tabs mb-4" aria-label="Seções da emenda">
        <a class="active" href="{{ route('emendas.show', $amendment) }}" aria-current="page">Visão geral</a>
        @if ($amendment->supportsTcespCompliance())
            <a href="{{ route('emendas.work-plan', $amendment) }}">Plano de trabalho</a>
        @endif
        <a href="{{ route('emendas.execution', $amendment) }}">Execução</a>
        @if ($amendment->supportsTcespCompliance())
            <a href="{{ route('emendas.compliance', $amendment) }}">Conformidade TCESP</a>
        @endif
        <a href="{{ route('emendas.accountability', $amendment) }}">Prestação de contas</a>
    </nav>

    <div class="row g-4">
        <div class="col-lg-8">
            <section class="content-panel mb-4">
                <div class="content-panel-header"><h2 class="h5 mb-0">Dados da emenda</h2></div>
                <div class="content-panel-body">
                    <dl class="data-list">
                        <dt>Esfera</dt><dd>{{ $amendment->governmentSphereLabel() }}</dd>
                        <dt>Tipo de autoria</dt><dd>{{ $amendment->authorshipTypeLabel() }}</dd>
                        <dt>Modalidade</dt><dd>{{ $amendment->transferTypeLabel() }}</dd>
                        <dt>Autor</dt><dd>{{ $amendment->author_name }}{{ $amendment->author_party ? ' / '.$amendment->author_party : '' }}</dd>
                        <dt>Código Transferegov</dt><dd>{{ $amendment->transferegov_code ?: 'Não informado' }}</dd>
                        <dt>Órgão responsável</dt><dd>{{ $amendment->responsible_department }}</dd>
                        <dt>Responsável operacional</dt><dd>{{ $amendment->responsibleUser?->name ?? 'Não definido' }}</dd>
                        <dt>Data da indicação</dt><dd>{{ $amendment->indicated_at?->format('d/m/Y') ?: 'Não informada' }}</dd>
                        <dt>Data do recebimento</dt><dd>{{ $amendment->received_at?->format('d/m/Y') ?: 'Não informada' }}</dd>
                        <dt>Valor previsto</dt><dd>R$ {{ number_format($amendment->expected_amount, 2, ',', '.') }}</dd>
                        <dt>Valor recebido</dt><dd>{{ $amendment->received_amount !== null ? 'R$ '.number_format($amendment->received_amount, 2, ',', '.') : 'Não informado' }}</dd>
                    </dl>
                </div>
            </section>

            <section class="content-panel mb-4">
                <div class="content-panel-header"><h2 class="h5 mb-0">Objeto</h2></div>
                <div class="content-panel-body">
                    <p class="mb-0" style="white-space: pre-line">{{ $amendment->object }}</p>
                    @if ($amendment->notes)
                        <hr>
                        <h3 class="h6">Observações internas</h3>
                        <p class="mb-0 text-secondary" style="white-space: pre-line">{{ $amendment->notes }}</p>
                    @endif
                </div>
            </section>

        </div>

        <div class="col-lg-4">
            <section class="content-panel mb-4">
                <div class="content-panel-header d-flex align-items-center justify-content-between gap-2">
                    <h2 class="h5 mb-0">Matriz de risco</h2>
                    <x-risk-badge :level="$amendment->risk_level" :label="$amendment->riskLabel()" :score="$amendment->risk_score" />
                </div>
                <div class="content-panel-body">
                    <div class="risk-meter" role="progressbar" aria-label="Pontuação de risco" aria-valuenow="{{ $amendment->risk_score }}" aria-valuemin="0" aria-valuemax="100">
                        <span class="risk-meter-{{ $amendment->risk_level }}" style="width: {{ $amendment->risk_score }}%"></span>
                    </div>
                    @if ($amendment->risk_reasons)
                        <ul class="risk-reasons">
                            @foreach ($amendment->risk_reasons as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="small text-secondary mb-0">Nenhuma pendência relevante detectada.</p>
                    @endif
                    @if ($amendment->risk_calculated_at)
                        <small class="text-secondary">Atualizado em {{ $amendment->risk_calculated_at->format('d/m/Y H:i') }}</small>
                    @endif
                </div>
            </section>
            <section class="content-panel">
                <div class="content-panel-header"><h2 class="h5 mb-0">Prazos de controle</h2></div>
                <div class="content-panel-body">
                    @foreach ([
                        ['label' => 'Comunicação e publicidade', 'date' => $amendment->communication_deadline, 'completed_at' => $amendment->communication_completed_at],
                        ['label' => 'Execução', 'date' => $amendment->execution_deadline, 'completed_at' => $amendment->execution_completed_at],
                        ['label' => 'Prestação de contas', 'date' => $amendment->accountability_deadline, 'completed_at' => $amendment->accountability_completed_at],
                    ] as $deadline)
                        <div class="deadline-row">
                            <span>{{ $deadline['label'] }}</span>
                            @if ($deadline['completed_at'])
                                <span class="text-success text-end">Concluído<br><small>{{ $deadline['completed_at']->format('d/m/Y') }}</small></span>
                            @elseif ($deadline['date'])
                                <span class="deadline-date {{ $deadline['date']->isBefore(today()) && $amendment->status !== 'completed' ? 'deadline-overdue' : ($deadline['date']->betweenIncluded(today(), today()->addDays(30)) ? 'deadline-upcoming' : '') }}">
                                    {{ $deadline['date']->format('d/m/Y') }}
                                </span>
                            @else
                                <span class="text-secondary">Não informado</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        </div>

        <div class="col-12">
            <section class="content-panel mb-4" id="documentos">
                <div class="content-panel-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <i data-lucide="list-checks" aria-hidden="true"></i>
                            <h2 class="h5 mb-0">Checklist documental</h2>
                        </div>
                        @if ($checklistTotal > 0)
                            <p class="small text-secondary mb-0 mt-1">{{ $checklistCompleted }} de {{ $checklistTotal }} tipos com documento</p>
                        @endif
                    </div>
                    @if ($canManageChecklist)
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('document-types.index') }}">Configurar checklist</a>
                    @endif
                </div>

                @if ($checklistTotal === 0)
                    <div class="empty-state">
                        <p class="mb-0">O município ainda não configurou tipos de documento.</p>
                    </div>
                @else
                    <div class="checklist-progress" role="progressbar" aria-label="Progresso do checklist documental" aria-valuenow="{{ $checklistCompleted }}" aria-valuemin="0" aria-valuemax="{{ $checklistTotal }}">
                        <span style="width: {{ round(($checklistCompleted / $checklistTotal) * 100) }}%"></span>
                    </div>
                    @if ($requiredPending > 0)
                        <div class="checklist-warning">
                            <i data-lucide="triangle-alert" aria-hidden="true"></i>
                            <span>{{ $requiredPending }} {{ $requiredPending === 1 ? 'documento obrigatório está pendente' : 'documentos obrigatórios estão pendentes' }}.</span>
                        </div>
                    @endif
                    <div class="checklist-list">
                        @foreach ($documentTypes as $type)
                            @php($latestDocument = $latestDocumentsByType->get($type->id))
                            <div class="checklist-row">
                                <span class="checklist-state {{ $latestDocument ? 'complete' : 'pending' }}" aria-hidden="true">
                                    <i data-lucide="{{ $latestDocument ? 'file-check-2' : 'file-text' }}"></i>
                                </span>
                                <div class="checklist-copy">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <strong>{{ $type->name }}</strong>
                                        @if ($type->is_required)
                                            <span class="badge text-bg-warning">Obrigatório</span>
                                        @endif
                                    </div>
                                    <small>{{ $type->description ?: 'Sem descrição.' }}</small>
                                </div>
                                <div class="checklist-result">
                                    @if ($latestDocument)
                                        <span class="badge text-bg-success">Enviado</span>
                                        <a href="{{ route('emendas.documents.download', [$amendment, $latestDocument]) }}">
                                            Versão {{ $latestDocument->version }}
                                        </a>
                                    @else
                                        <span class="badge text-bg-light">Pendente</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($canEdit && $documentTypes->isNotEmpty())
                    <div class="document-upload-section">
                        <h3 class="h6 mb-3">Anexar documento</h3>
                        <x-validation-summary />
                        <form class="document-upload-form document-upload-form-with-stage" method="POST" action="{{ route('emendas.documents.store', $amendment) }}" enctype="multipart/form-data" novalidate>
                            @csrf
                            <input name="_submission_token" type="hidden" value="{{ $documentSubmissionToken }}">
                            <div>
                                <label class="form-label" for="document_type_id">Tipo <span class="required-mark">*</span></label>
                                <select class="form-select @error('document_type_id') is-invalid @enderror" id="document_type_id" name="document_type_id" required>
                                    <option value="">Selecione</option>
                                    @foreach ($documentTypes as $type)
                                        <option value="{{ $type->id }}" @selected((string) old('document_type_id') === (string) $type->id)>{{ $type->name }}</option>
                                    @endforeach
                                </select>
                                @error('document_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="form-label" for="document">Arquivo <span class="required-mark">*</span></label>
                                <input class="form-control @error('document') is-invalid @enderror" id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx,.csv,.doc,.docx" required>
                                <div class="form-text">PDF, imagem, planilha ou documento de texto, com até 10 MB.</div>
                                @error('document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="form-label" for="execution_stage_id">Etapa de execução</label>
                                <select class="form-select @error('execution_stage_id') is-invalid @enderror" id="execution_stage_id" name="execution_stage_id">
                                    <option value="">Documento geral da emenda</option>
                                    @foreach ($executionStages as $stage)
                                        <option value="{{ $stage->id }}" @selected((string) old('execution_stage_id') === (string) $stage->id)>{{ $stage->title }}</option>
                                    @endforeach
                                </select>
                                @error('execution_stage_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="form-label" for="document_notes">Observação</label>
                                <input class="form-control @error('notes') is-invalid @enderror" id="document_notes" name="notes" value="{{ old('notes') }}" maxlength="500">
                                @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button class="btn btn-primary document-upload-button" type="submit">
                                <i data-lucide="upload" aria-hidden="true"></i>Anexar
                            </button>
                        </form>
                    </div>
                @endif
            </section>

            <section class="content-panel mb-4">
                <div class="content-panel-header d-flex align-items-center gap-2">
                    <i data-lucide="file-check-2" aria-hidden="true"></i>
                    <h2 class="h5 mb-0">Arquivos e versões</h2>
                </div>
                @if ($documents->isEmpty())
                    <div class="empty-state">Nenhum documento anexado nesta emenda.</div>
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 document-table">
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Arquivo</th>
                                    <th>Versão</th>
                                    <th>Etapa</th>
                                    <th>Enviado por</th>
                                    <th>Data</th>
                                    <th class="text-end">Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($documents as $document)
                                    <tr>
                                        <td data-label="Tipo">{{ $document->documentType->name }}</td>
                                        <td data-label="Arquivo">
                                            <div class="document-name">{{ $document->original_name }}</div>
                                            <small class="text-secondary">{{ $document->formattedSize() }}{{ $document->notes ? ' · '.$document->notes : '' }}</small>
                                        </td>
                                        <td data-label="Versão">{{ $document->version }}</td>
                                        <td data-label="Etapa">{{ $document->executionStage?->title ?? 'Documento geral' }}</td>
                                        <td data-label="Enviado por">{{ $document->uploader_name }}</td>
                                        <td class="text-nowrap" data-label="Data">{{ $document->created_at->format('d/m/Y H:i') }}</td>
                                        <td class="text-end" data-label="Ação">
                                            <a class="btn btn-sm btn-outline-primary" href="{{ route('emendas.documents.download', [$amendment, $document]) }}">
                                                <i data-lucide="download" aria-hidden="true"></i>Baixar
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="content-panel">
                <div class="content-panel-header d-flex align-items-center gap-2">
                    <i data-lucide="history" aria-hidden="true"></i>
                    <h2 class="h5 mb-0">Histórico de alterações</h2>
                </div>
                <div class="content-panel-body audit-timeline">
                    @forelse ($amendment->auditLogs as $auditLog)
                        @php($changes = $auditLog->changesForDisplay())
                        <article class="audit-entry">
                            <span class="audit-marker" aria-hidden="true"></span>
                            <div class="audit-entry-content">
                                <div class="d-flex flex-column flex-sm-row justify-content-between gap-1">
                                    <strong>{{ $auditLog->actionLabel() }}</strong>
                                    <time class="text-secondary small" datetime="{{ $auditLog->created_at->toIso8601String() }}">
                                        {{ $auditLog->created_at->format('d/m/Y') }} às {{ $auditLog->created_at->format('H:i') }}
                                    </time>
                                </div>
                                <div class="text-secondary small">{{ $auditLog->actor_name }}</div>

                                @if ($changes !== [])
                                    <details class="audit-details mt-2">
                                        <summary>{{ count($changes) }} {{ count($changes) === 1 ? 'campo alterado' : 'campos alterados' }}</summary>
                                        <dl class="audit-changes mb-0 mt-2">
                                            @foreach ($changes as $change)
                                                <div>
                                                    <dt>{{ $change['label'] }}</dt>
                                                    <dd><span>{{ $change['old'] }}</span><strong>para</strong><span>{{ $change['new'] }}</span></dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    </details>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="empty-state">O histórico começará na próxima alteração desta emenda.</div>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
@endsection
