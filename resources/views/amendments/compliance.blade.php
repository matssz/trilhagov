@extends('layouts.app')

@section('title', 'Conformidade TCESP '.$amendment->reference.' | TrilhaGov')

@section('content')
    <a class="d-inline-block mb-3" href="{{ route('emendas.show', $amendment) }}">Voltar para a emenda</a>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <p class="page-kicker mb-0">Controle municipal</p>
                <span class="compliance-version">Manual TCESP · jul/2026</span>
            </div>
            <h1 class="h3 mb-1">Matriz de conformidade</h1>
            <p class="text-secondary mb-0">Emenda {{ $amendment->reference }} · {{ $amendment->municipality->name }}/SP</p>
        </div>
        <a class="btn btn-outline-primary" href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">
            <i data-lucide="external-link" aria-hidden="true"></i>Consultar fonte oficial
        </a>
    </div>

    <nav class="amendment-tabs mb-4" aria-label="Seções da emenda">
        <a href="{{ route('emendas.show', $amendment) }}">Visão geral</a>
        <a href="{{ route('emendas.work-plan', $amendment) }}">Plano de trabalho</a>
        <a href="{{ route('emendas.execution', $amendment) }}">Execução</a>
        <a class="active" href="{{ route('emendas.compliance', $amendment) }}" aria-current="page">Conformidade TCESP</a>
        <a href="{{ route('emendas.accountability', $amendment) }}">Prestação de contas</a>
    </nav>

    <div class="compliance-notice mb-4" role="note">
        <i data-lucide="scale" aria-hidden="true"></i>
        <div>
            <strong>Instrumento de apoio à conferência</strong>
            <p>Esta matriz organiza evidências com base no manual oficial. O resultado não substitui análise jurídica, parecer técnico nem validação do TCESP.</p>
        </div>
    </div>

    <x-validation-summary />

    <section class="compliance-overview mb-4" aria-label="Resumo da conformidade">
        <div class="compliance-score">
            <div class="compliance-score-ring" style="--score: {{ $summary['percentage'] }}">
                <strong>{{ $summary['percentage'] }}%</strong>
                <span>atendido</span>
            </div>
            <div>
                <h2 class="h5 mb-1">Diagnóstico documentado</h2>
                <p class="text-secondary mb-0">{{ $summary['compliant'] }} de {{ $summary['applicable'] }} itens aplicáveis possuem evidência de atendimento.</p>
            </div>
        </div>
        <div class="compliance-metrics">
            <div><span class="dot dot-compliant"></span><small>Atendidos</small><strong>{{ $summary['compliant'] }}</strong></div>
            <div><span class="dot dot-non-compliant"></span><small>Não atendidos</small><strong>{{ $summary['non_compliant'] }}</strong></div>
            <div><span class="dot dot-pending"></span><small>Pendentes</small><strong>{{ $summary['pending'] }}</strong></div>
            <div><span class="dot dot-na"></span><small>Não se aplicam</small><strong>{{ $summary['not_applicable'] }}</strong></div>
        </div>
    </section>

    <div class="compliance-progress mb-4" role="progressbar" aria-label="Itens atendidos da matriz TCESP" aria-valuenow="{{ $summary['percentage'] }}" aria-valuemin="0" aria-valuemax="100">
        <span style="width: {{ $summary['percentage'] }}%"></span>
    </div>

    <div class="compliance-category-list">
        @foreach ($categories as $categoryCode => $category)
            @php($items = $groupedMatrix->get($categoryCode, collect()))
            @php($categoryPending = $items->where('status', App\Models\AmendmentComplianceReview::STATUS_PENDING)->count())
            <section class="content-panel compliance-category" aria-labelledby="categoria-{{ $categoryCode }}">
                <div class="content-panel-header compliance-category-header">
                    <div class="d-flex align-items-center gap-2">
                        <span class="compliance-category-icon"><i data-lucide="{{ $category['icon'] }}" aria-hidden="true"></i></span>
                        <div>
                            <h2 class="h5 mb-0" id="categoria-{{ $categoryCode }}">{{ $category['label'] }}</h2>
                            <small>{{ $items->count() }} verificações · {{ $categoryPending }} pendentes</small>
                        </div>
                    </div>
                </div>

                <div class="compliance-rules">
                    @foreach ($items as $item)
                        @php($review = $item['review'])
                        @php($formHasErrors = old('_rule_code') === $item['code'] && $errors->any())
                        <details class="compliance-rule status-{{ $item['status'] }}" id="regra-{{ $item['code'] }}" @if($formHasErrors) open @endif>
                            <summary>
                                <span class="compliance-rule-state" aria-hidden="true">
                                    <i data-lucide="{{ match($item['status']) { 'compliant' => 'circle-check', 'non_compliant' => 'circle-x', 'not_applicable' => 'circle-minus', default => 'circle-dashed' } }}"></i>
                                </span>
                                <span class="compliance-rule-title">
                                    <span class="d-flex flex-wrap align-items-center gap-2">
                                        <strong>{{ $item['title'] }}</strong>
                                        @if ($item['critical'])<span class="compliance-critical">Essencial</span>@endif
                                    </span>
                                    <small>{{ $item['code'] }} · {{ $item['source'] }}</small>
                                </span>
                                <span class="compliance-state-label">{{ $statuses[$item['status']] }}</span>
                                <i class="compliance-chevron" data-lucide="chevron-down" aria-hidden="true"></i>
                            </summary>

                            <div class="compliance-rule-body">
                                <div class="compliance-guidance">
                                    <strong>O que verificar</strong>
                                    <p>{{ $item['guidance'] }}</p>
                                </div>

                                @if ($review && ($review->evidence_notes || $review->document))
                                    <div class="compliance-current-evidence">
                                        <div>
                                            <strong>Evidência registrada</strong>
                                            @if ($review->evidence_notes)<p>{{ $review->evidence_notes }}</p>@endif
                                            @if ($review->document)
                                                <a href="{{ route('emendas.documents.download', [$amendment, $review->document]) }}">
                                                    <i data-lucide="paperclip" aria-hidden="true"></i>{{ $review->document->original_name }}
                                                </a>
                                            @endif
                                        </div>
                                        @if ($review->reviewed_at)
                                            <small>{{ $review->reviewer?->name ?? 'Usuário removido' }} · {{ $review->reviewed_at->format('d/m/Y H:i') }}</small>
                                        @endif
                                    </div>
                                @endif

                                @if ($canEdit)
                                    <form class="compliance-review-form" method="POST" action="{{ route('emendas.compliance.update', [$amendment, $item['code']]) }}" novalidate>
                                        @csrf
                                        @method('PATCH')
                                        <input name="_submission_token" type="hidden" value="{{ $reviewTokens->get($item['code']) }}">
                                        <input name="_rule_code" type="hidden" value="{{ $item['code'] }}">
                                        <div>
                                            <label class="form-label" for="status_{{ $item['code'] }}">Situação <span class="required-mark">*</span></label>
                                            <select class="form-select {{ $formHasErrors && $errors->has('status') ? 'is-invalid' : '' }}" id="status_{{ $item['code'] }}" name="status" required>
                                                @foreach ($statuses as $value => $label)
                                                    <option value="{{ $value }}" @selected(($formHasErrors ? old('status') : $item['status']) === $value)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @if ($formHasErrors)@error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                        </div>
                                        <div>
                                            <label class="form-label" for="document_{{ $item['code'] }}">Documento de evidência</label>
                                            <select class="form-select {{ $formHasErrors && $errors->has('amendment_document_id') ? 'is-invalid' : '' }}" id="document_{{ $item['code'] }}" name="amendment_document_id">
                                                <option value="">Nenhum documento vinculado</option>
                                                @foreach ($amendment->documents as $document)
                                                    <option value="{{ $document->id }}" @selected((string) ($formHasErrors ? old('amendment_document_id') : $review?->amendment_document_id) === (string) $document->id)>
                                                        {{ $document->documentType->name }} · {{ $document->original_name }} · v{{ $document->version }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @if ($formHasErrors)@error('amendment_document_id')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                        </div>
                                        <div class="compliance-notes-field">
                                            <label class="form-label" for="evidence_{{ $item['code'] }}">Evidência, constatação ou justificativa</label>
                                            <textarea class="form-control {{ $formHasErrors && $errors->has('evidence_notes') ? 'is-invalid' : '' }}" id="evidence_{{ $item['code'] }}" name="evidence_notes" rows="3" maxlength="5000" placeholder="Indique onde a comprovação pode ser conferida ou descreva a pendência.">{{ $formHasErrors ? old('evidence_notes') : $review?->evidence_notes }}</textarea>
                                            @if ($formHasErrors)@error('evidence_notes')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                        </div>
                                        <button class="btn btn-primary compliance-save" type="submit">
                                            <i data-lucide="save" aria-hidden="true"></i>Salvar revisão
                                        </button>
                                    </form>
                                @elseif (! $review)
                                    <p class="small text-secondary mb-0">A revisão ainda não foi iniciada.</p>
                                @endif
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    <p class="compliance-source-note">
        Fonte de referência: <a href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">{{ $sourceLabel }}</a> · versão interna {{ $frameworkVersion }}.
        A legislação local, a LDO e orientações posteriores também devem ser conferidas.
    </p>
@endsection
