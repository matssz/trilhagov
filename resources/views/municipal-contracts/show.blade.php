@extends('layouts.app')

@section('title', $contract->code().' | TrilhaGov')

@section('content')
    @php
        $money = function ($value) {
            return 'R$ '.number_format((float) $value, 2, ',', '.');
        };
        $statusOrder = ['planning','selection','contracted','executing','suspended','completed'];
        $currentIndex = array_search($contract->status, $statusOrder, true);
    @endphp
    <a class="back-link mb-3" href="{{ route('municipal-contracts.index', ['year' => $contract->amendment->fiscal_year]) }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Obras e contratos</a>
    <div class="page-heading contract-detail-heading">
        <div><span class="page-kicker">{{ $contract->code() }} · Processo {{ $contract->process_number }}</span><h1>{{ $contract->contract_number ? 'Contrato '.$contract->contract_number : 'Processo em preparação' }}</h1><p>{{ $contract->object }}</p></div>
        <div class="contract-heading-actions"><span class="status-pill is-{{ $contract->status === 'suspended' ? 'danger' : (in_array($contract->status, ['executing','completed']) ? 'success' : 'warning') }}">{{ $contract->statusLabel() }}</span><a class="btn btn-outline-secondary" href="{{ route('municipal-contracts.pdf', $contract) }}"><i data-lucide="file-down" aria-hidden="true"></i>Dossiê PDF</a></div>
    </div>

    <div class="contract-context-band">
        <div><span>Emenda</span><strong>{{ $contract->amendment->reference }}</strong></div>
        <div><span>Contratada</span><strong>{{ $contract->supplier_name ?: 'Não definida' }}</strong></div>
        <div><span>Valor atualizado</span><strong>{{ $money($contract->current_amount ?? $contract->estimated_amount) }}</strong></div>
        <div><span>Fiscal</span><strong>{{ $contract->inspector?->name ?: 'Não designado' }}</strong></div>
    </div>

    @if($contract->status !== 'cancelled')
        <section class="contract-lifecycle" aria-label="Etapas do contrato">
            @foreach(['planning' => 'Planejamento','selection' => 'Seleção','contracted' => 'Contrato','executing' => 'Execução','completed' => 'Recebimento'] as $key => $label)
                @php
                    $index = array_search($key, $statusOrder, true);
                @endphp
                <span class="{{ $currentIndex !== false && $index <= $currentIndex ? 'is-complete' : '' }} {{ $contract->status === $key ? 'is-current' : '' }}"><i data-lucide="{{ $currentIndex !== false && $index < $currentIndex ? 'check' : 'circle-dot' }}" aria-hidden="true"></i><strong>{{ $label }}</strong></span>
                @if(!$loop->last)<b></b>@endif
            @endforeach
        </section>
    @endif

    <section class="contract-diagnostic {{ $diagnostic['ready'] ? 'is-ready' : '' }}">
        <div class="contract-diagnostic-title"><i data-lucide="{{ $diagnostic['ready'] ? 'shield-check' : 'triangle-alert' }}" aria-hidden="true"></i><div><strong>{{ $diagnostic['ready'] ? 'Controles obrigatórios atendidos para a etapa atual' : count($diagnostic['blockers']).' pendência(s) impedem o avanço' }}</strong><small>Financeiro medido {{ number_format($diagnostic['financial_percentage'], 1, ',', '.') }}% · Avanço físico {{ number_format($diagnostic['physical_percentage'], 1, ',', '.') }}%</small></div></div>
        @if($diagnostic['blockers'])<ul>@foreach($diagnostic['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>@endif
        @if($diagnostic['warnings'])<div class="diagnostic-warnings">@foreach($diagnostic['warnings'] as $warning)<span><i data-lucide="info" aria-hidden="true"></i>{{ $warning }}</span>@endforeach</div>@endif
    </section>

    @if($canEdit)
        <form class="contract-form" method="POST" action="{{ route('municipal-contracts.update', $contract) }}" novalidate data-prevent-double-submit>
            @csrf @method('PATCH')
            <input name="_submission_token" type="hidden" value="{{ $updateToken }}">
            <input name="parliamentary_amendment_id" type="hidden" value="{{ $contract->parliamentary_amendment_id }}">
            <section class="content-panel">
                <div class="content-panel-header"><div><span class="step-index">1</span><h2 class="h5 mb-0">Planejamento e seleção</h2></div><span class="legal-badge">Arts. 18 e 92</span></div>
                <div class="contract-form-grid">
                    <div><label class="form-label">Processo administrativo <span class="required-mark">*</span></label><input class="form-control" name="process_number" value="{{ old('process_number', $contract->process_number) }}" required></div>
                    <div><label class="form-label">Número do contrato</label><input class="form-control" name="contract_number" value="{{ old('contract_number', $contract->contract_number) }}"></div>
                    <div><label class="form-label">Tipo do objeto <span class="required-mark">*</span></label><select class="form-select" name="object_type">@foreach($objectTypes as $value => $label)<option value="{{ $value }}" @selected(old('object_type', $contract->object_type) === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div><label class="form-label">Forma de contratação <span class="required-mark">*</span></label><select class="form-select" name="procurement_method">@foreach($procurementMethods as $value => $label)<option value="{{ $value }}" @selected(old('procurement_method', $contract->procurement_method) === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div><label class="form-label">Regime de execução</label><select class="form-select" name="execution_regime"><option value="">Selecione</option>@foreach($executionRegimes as $value => $label)<option value="{{ $value }}" @selected(old('execution_regime', $contract->execution_regime) === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div><label class="form-label">Critério de julgamento</label><input class="form-control" name="judgment_criterion" value="{{ old('judgment_criterion', $contract->judgment_criterion) }}"></div>
                    <div><label class="form-label">Valor estimado <span class="required-mark">*</span></label><input class="form-control" name="estimated_amount" type="number" min="0.01" step="0.01" value="{{ old('estimated_amount', $contract->estimated_amount) }}" required></div>
                    <div><label class="form-label">Local da obra ou entrega</label><input class="form-control" name="site_location" value="{{ old('site_location', $contract->site_location) }}"></div>
                    <div class="span-2"><label class="form-label">Objeto <span class="required-mark">*</span></label><textarea class="form-control" name="object" rows="2" required>{{ old('object', $contract->object) }}</textarea></div>
                </div>
                <div class="planning-check-grid">
                    @foreach($planningChecklist as $key => $label)<label><input class="form-check-input" name="planning_checklist[{{ $key }}]" type="checkbox" value="1" @checked(old("planning_checklist.{$key}", $contract->planning_checklist[$key] ?? false))><span><strong>{{ $label }}</strong><small>Confirmação do responsável municipal</small></span></label>@endforeach
                </div>
            </section>

            <section class="content-panel">
                <div class="content-panel-header"><div><span class="step-index">2</span><h2 class="h5 mb-0">Instrumento contratual e fiscalização</h2></div><span class="legal-badge">Arts. 94 e 117</span></div>
                <div class="contract-form-grid">
                    <div><label class="form-label">Contratada</label><input class="form-control" name="supplier_name" value="{{ old('supplier_name', $contract->supplier_name) }}"></div>
                    <div><label class="form-label">CNPJ ou CPF</label><input class="form-control" name="supplier_document" value="{{ old('supplier_document', $contract->supplier_document) }}"></div>
                    <div><label class="form-label">Valor original</label><input class="form-control" name="original_amount" type="number" min="0.01" step="0.01" value="{{ old('original_amount', $contract->original_amount) }}"></div>
                    <div><label class="form-label">Assinatura</label><input class="form-control" name="signed_at" type="date" value="{{ old('signed_at', $contract->signed_at?->toDateString()) }}"></div>
                    <div><label class="form-label">Início da vigência</label><input class="form-control" name="effective_start_at" type="date" value="{{ old('effective_start_at', $contract->effective_start_at?->toDateString()) }}"></div>
                    <div><label class="form-label">Fim da vigência</label><input class="form-control" name="effective_end_at" type="date" value="{{ old('effective_end_at', $contract->effective_end_at?->toDateString()) }}"></div>
                    <div><label class="form-label">Ordem de serviço</label><input class="form-control" name="work_order_at" type="date" value="{{ old('work_order_at', $contract->work_order_at?->toDateString()) }}"></div>
                    <div><label class="form-label">Garantia do objeto em meses</label><input class="form-control" name="warranty_months" type="number" min="0" max="600" value="{{ old('warranty_months', $contract->warranty_months) }}"></div>
                    <div><label class="form-label">Gestor do contrato</label><select class="form-select" name="contract_manager_id"><option value="">Selecione</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected((string) old('contract_manager_id', $contract->contract_manager_id) === (string) $member->id)>{{ $member->name }}</option>@endforeach</select></div>
                    <div><label class="form-label">Fiscal do contrato</label><select class="form-select" name="contract_inspector_id"><option value="">Selecione</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected((string) old('contract_inspector_id', $contract->contract_inspector_id) === (string) $member->id)>{{ $member->name }}</option>@endforeach</select></div>
                    <div><label class="form-label">Responsável técnico</label><input class="form-control" name="technical_responsible" value="{{ old('technical_responsible', $contract->technical_responsible) }}"></div>
                    <div><label class="form-label">ART, RRT ou registro técnico</label><input class="form-control" name="technical_registration" value="{{ old('technical_registration', $contract->technical_registration) }}"></div>
                    <div><label class="form-label">Canal de publicidade</label><select class="form-select" name="publication_type"><option value="">Selecione</option>@foreach($publicationTypes as $value => $label)<option value="{{ $value }}" @selected(old('publication_type', $contract->publication_type) === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div><label class="form-label">Data da publicação</label><input class="form-control" name="published_at" type="date" value="{{ old('published_at', $contract->published_at?->toDateString()) }}"></div>
                    <div class="span-2"><label class="form-label">Link, diário ou referência da publicação</label><input class="form-control" name="publication_reference" value="{{ old('publication_reference', $contract->publication_reference) }}"></div>
                    <div class="span-2"><label class="form-label">Critérios e periodicidade de medição</label><textarea class="form-control" name="measurement_criteria" rows="2">{{ old('measurement_criteria', $contract->measurement_criteria) }}</textarea></div>
                    <div class="span-2"><label class="form-label">Condições e prazo de pagamento</label><textarea class="form-control" name="payment_terms" rows="2">{{ old('payment_terms', $contract->payment_terms) }}</textarea></div>
                </div>
            </section>

            <section class="content-panel">
                <div class="content-panel-header"><div><span class="step-index">3</span><h2 class="h5 mb-0">Recebimento do objeto</h2></div><span class="legal-badge">Art. 140</span></div>
                <div class="contract-form-grid">
                    <div><label class="form-label">Termo de recebimento provisório</label><input class="form-control" name="provisional_acceptance_reference" value="{{ old('provisional_acceptance_reference', $contract->provisional_acceptance_reference) }}"></div>
                    <div><label class="form-label">Data do recebimento provisório</label><input class="form-control" name="provisional_accepted_at" type="date" value="{{ old('provisional_accepted_at', $contract->provisional_accepted_at?->toDateString()) }}"></div>
                    <div><label class="form-label">Termo de recebimento definitivo</label><input class="form-control" name="definitive_acceptance_reference" value="{{ old('definitive_acceptance_reference', $contract->definitive_acceptance_reference) }}"></div>
                    <div><label class="form-label">Data do recebimento definitivo</label><input class="form-control" name="definitive_accepted_at" type="date" value="{{ old('definitive_accepted_at', $contract->definitive_accepted_at?->toDateString()) }}"></div>
                </div>
                <div class="contract-form-actions"><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar controles</button></div>
            </section>
        </form>

        <section class="contract-transition-band">
            <div><i data-lucide="route" aria-hidden="true"></i><span><strong>Movimentar etapa</strong><small>O sistema bloqueará avanços incompatíveis com os controles registrados.</small></span></div>
            <form method="POST" action="{{ route('municipal-contracts.transition', $contract) }}" data-prevent-double-submit>@csrf<input name="_submission_token" type="hidden" value="{{ $transitionToken }}"><select class="form-select" name="action" required><option value="">Selecione a ação</option>@if($contract->status === 'planning')<option value="selection">Iniciar seleção</option>@endif @if($contract->status === 'selection')<option value="contracted">Formalizar contrato</option>@endif @if($contract->status === 'contracted')<option value="executing">Iniciar execução</option>@endif @if($contract->status === 'executing')<option value="suspend">Paralisar</option><option value="complete">Receber definitivamente</option>@endif @if($contract->status === 'suspended')<option value="resume">Retomar execução</option>@endif @if(in_array($contract->status, ['planning','selection','contracted']))<option value="cancel">Cancelar processo</option>@endif</select><input class="form-control" name="event_date" type="date" title="Data da paralisação ou retomada"><input class="form-control" name="reason" placeholder="Fundamentação quando exigida"><button class="btn btn-primary" type="submit"><i data-lucide="arrow-right" aria-hidden="true"></i>Confirmar</button></form>
        </section>
    @endif

    <section class="content-panel contract-operations-panel" id="medicoes">
        <div class="content-panel-header"><div><span class="step-index">4</span><h2 class="h5 mb-0">Medições e atestes</h2></div><span class="record-count">{{ $contract->measurements->count() }}</span></div>
        @if($canEdit && in_array($contract->status, ['executing','suspended']))
            <details class="operation-entry"><summary><i data-lucide="plus" aria-hidden="true"></i>Registrar medição</summary><form class="operation-form" method="POST" action="{{ route('contract-measurements.store', $contract) }}" data-prevent-double-submit>@csrf<input name="_submission_token" type="hidden" value="{{ $measurementToken }}"><label>Início do período<input class="form-control" name="period_start_at" type="date" required></label><label>Fim do período<input class="form-control" name="period_end_at" type="date" required></label><label>Data da medição<input class="form-control" name="measured_at" type="date" required></label><label>Valor medido<input class="form-control" name="amount" type="number" min="0.01" step="0.01" required></label><label>Avanço físico acumulado (%)<input class="form-control" name="cumulative_physical_percentage" type="number" min="0.01" max="100" step="0.01" required></label><label>Evidência<select class="form-select" name="evidence_document_id"><option value="">Selecione</option>@foreach($documents as $document)<option value="{{ $document->id }}">{{ $document->documentType->name }} · {{ $document->original_name }}</option>@endforeach</select></label><label class="span-3">Serviços e quantitativos medidos<textarea class="form-control" name="notes" rows="2" required></textarea></label><div class="span-3 operation-actions"><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Enviar para ateste</button></div></form></details>
        @endif
        @if($contract->measurements->isEmpty())<div class="empty-state compact"><i data-lucide="gauge" aria-hidden="true"></i><h3>Nenhuma medição registrada</h3><p>O histórico físico-financeiro aparecerá aqui.</p></div>@else
            <div class="table-responsive"><table class="table app-table align-middle mb-0"><thead><tr><th>Nº</th><th>Período</th><th>Valor</th><th>Físico acumulado</th><th>Evidência</th><th>Situação</th><th>Decisão</th></tr></thead><tbody>@foreach($contract->measurements as $measurement)<tr><td><strong>{{ $measurement->sequence }}</strong></td><td>{{ $measurement->period_start_at->format('d/m/Y') }} a {{ $measurement->period_end_at->format('d/m/Y') }}<small class="table-subtitle">Medida em {{ $measurement->measured_at->format('d/m/Y') }}</small></td><td>{{ $money($measurement->amount) }}</td><td>{{ number_format((float) $measurement->cumulative_physical_percentage, 2, ',', '.') }}%</td><td>{{ $measurement->evidenceDocument?->original_name ?: 'Pendente' }}</td><td><span class="status-pill is-{{ $measurement->status === 'approved' ? 'success' : ($measurement->status === 'rejected' ? 'danger' : 'warning') }}">{{ $measurement->statusLabel() }}</span></td><td>@if($canReview && $measurement->status === 'draft')<form class="inline-review-form" method="POST" action="{{ route('contract-measurements.decide', $measurement) }}" data-prevent-double-submit>@csrf<input name="_submission_token" type="hidden" value="{{ $measurementDecisionTokens[$measurement->id] }}"><input class="form-control" name="review_notes" placeholder="Fundamentação do fiscal" required><button class="icon-button is-success" name="action" value="approve" title="Atestar" aria-label="Atestar medição"><i data-lucide="check" aria-hidden="true"></i></button><button class="icon-button is-danger" name="action" value="reject" title="Rejeitar" aria-label="Rejeitar medição"><i data-lucide="circle-x" aria-hidden="true"></i></button></form>@else<small>{{ $measurement->reviewer?->name ?: 'Sem decisão' }}</small>@endif</td></tr>@endforeach</tbody></table></div>
        @endif
    </section>

    <section class="content-panel contract-operations-panel" id="aditivos">
        <div class="content-panel-header"><div><span class="step-index">5</span><h2 class="h5 mb-0">Termos aditivos</h2></div><span class="record-count">{{ $contract->addenda->count() }}</span></div>
        @if($canEdit && in_array($contract->status, ['contracted','executing','suspended']))
            <details class="operation-entry"><summary><i data-lucide="plus" aria-hidden="true"></i>Registrar termo aditivo</summary><form class="operation-form" method="POST" action="{{ route('contract-addenda.store', $contract) }}" data-prevent-double-submit>@csrf<input name="_submission_token" type="hidden" value="{{ $addendumToken }}"><label>Tipo<select class="form-select" name="type" required>@foreach($addendumTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label><label>Alteração de valor<input class="form-control" name="value_change" type="number" min="0" step="0.01" value="0" required></label><label>Alteração de prazo em dias<input class="form-control" name="days_change" type="number" min="-3650" max="3650" value="0" required></label><label>Início dos efeitos<input class="form-control" name="effective_at" type="date" required></label><label>Assinatura<input class="form-control" name="signed_at" type="date"></label><label>Publicação<input class="form-control" name="published_at" type="date"></label><label class="span-3">Referência da publicação<input class="form-control" name="publication_reference"></label><label class="span-3">Justificativa administrativa<textarea class="form-control" name="justification" rows="2" required></textarea></label><label class="span-3">Fundamentação técnica<textarea class="form-control" name="technical_basis" rows="2" required></textarea></label><label class="span-3">Justificativa para efeitos anteriores à assinatura<textarea class="form-control" name="advance_effects_justification" rows="2"></textarea></label><label class="span-3">Evidência<select class="form-select" name="evidence_document_id"><option value="">Selecione</option>@foreach($documents as $document)<option value="{{ $document->id }}">{{ $document->documentType->name }} · {{ $document->original_name }}</option>@endforeach</select></label><div class="span-3 operation-actions"><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Enviar para análise</button></div></form></details>
        @endif
        @if($contract->addenda->isEmpty())<div class="empty-state compact"><i data-lucide="file-plus-2" aria-hidden="true"></i><h3>Nenhum termo aditivo</h3><p>Alterações de valor, prazo ou projeto serão controladas aqui.</p></div>@else
            <div class="addendum-list">@foreach($contract->addenda as $addendum)<article><span class="addendum-number">{{ $addendum->sequence }}</span><div><strong>{{ $addendum->typeLabel() }}</strong><small>{{ $money($addendum->value_change) }} · {{ $addendum->days_change }} dia(s) · efeitos em {{ $addendum->effective_at->format('d/m/Y') }}</small><p>{{ $addendum->justification }}</p></div><span class="status-pill is-{{ $addendum->status === 'approved' ? 'success' : ($addendum->status === 'rejected' ? 'danger' : 'warning') }}">{{ $addendum->statusLabel() }}</span>@if($canDecideAddendum && $addendum->status === 'draft')<form class="addendum-review-form" method="POST" action="{{ route('contract-addenda.decide', $addendum) }}" data-prevent-double-submit>@csrf<input name="_submission_token" type="hidden" value="{{ $addendumDecisionTokens[$addendum->id] }}"><input class="form-control" name="review_notes" placeholder="Fundamentação da decisão" required><button class="btn btn-outline-secondary" name="action" value="reject" type="submit">Rejeitar</button><button class="btn btn-primary" name="action" value="approve" type="submit"><i data-lucide="check" aria-hidden="true"></i>Formalizar</button></form>@endif</article>@endforeach</div>
        @endif
    </section>

    <div class="report-notice"><i data-lucide="info" aria-hidden="true"></i><div><strong>Controle de apoio</strong><p>O TrilhaGov organiza evidências e bloqueios, mas não substitui projeto, parecer jurídico, responsabilidade técnica, fiscalização presencial ou publicação oficial.</p></div></div>
@endsection
