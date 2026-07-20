@extends('layouts.app')

@section('title', 'Preparação Audesp ' . $amendment->reference . ' | TrilhaGov')

@section('content')
    @php
        $suggestedApplicationCode = preg_replace('/\D/', '', (string) $amendment->application_code_fixed)
            . preg_replace('/\D/', '', (string) $amendment->application_code_variable);
        $suggestedApplicationCode = preg_match('/^(800|801|802|803|804|900|901|902|903)[0-9]{1,4}$/', $suggestedApplicationCode)
            ? $suggestedApplicationCode
            : '';
        $subfunctions = old('government_subfunctions', $registration ? implode(', ', $registration->government_subfunctions) : '');
        $bankAccountOpened = $registration !== null
            ? (int) $registration->bank_account_opened
            : (int) ($amendment->bank_tracking_type === 'specific_account');
    @endphp

    <a class="back-link mb-3" href="{{ route('emendas.index') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Voltar para emendas</a>

    <div class="audesp-heading mb-3">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <p class="page-kicker mb-0">Cadastro contábil e rastreabilidade municipal</p>
                <span class="audesp-schema-badge">XSD {{ \App\Models\AudespAmendmentRegistration::SCHEMA_VERSION }}</span>
            </div>
            <h1 class="h3 mb-1">Preparação Audesp</h1>
            <p class="text-secondary mb-0">{{ $amendment->reference }} · {{ $amendment->object }}</p>
        </div>
        <div class="audesp-heading-actions">
            <a class="btn btn-outline-primary" href="{{ route('audesp-homologations.index') }}"><i data-lucide="package-check" aria-hidden="true"></i>Homologar remessa</a>
            <a class="btn btn-outline-primary" href="{{ route('emendas.audesp.diagnostic', $amendment) }}"><i data-lucide="file-spreadsheet" aria-hidden="true"></i>Exportar diagnóstico</a>
            @if ($canEdit && $readiness['ready'])
                <form method="POST" action="{{ route('emendas.audesp.preview', $amendment) }}">
                    @csrf
                    <input name="_submission_token" type="hidden" value="{{ $previewToken }}">
                    <button class="btn btn-primary" type="submit"><i data-lucide="file-down" aria-hidden="true"></i>Gerar prévia XML</button>
                </form>
            @endif
        </div>
    </div>

    <nav class="amendment-tabs mb-4" aria-label="Seções da emenda">
        <a href="{{ route('emendas.show', $amendment) }}">Visão geral</a>
        <a href="{{ route('emendas.work-plan', $amendment) }}">Plano de trabalho</a>
        <a href="{{ route('emendas.impediments', $amendment) }}">Impedimentos</a>
        <a href="{{ route('emendas.execution', $amendment) }}">Execução</a>
        <a class="active" href="{{ route('emendas.audesp', $amendment) }}" aria-current="page">Audesp</a>
        <a href="{{ route('emendas.compliance', $amendment) }}">Conformidade TCESP</a>
        <a href="{{ route('emendas.accountability', $amendment) }}">Prestação de contas</a>
    </nav>

    <x-validation-summary />

    <div class="audesp-scope-note mb-4" role="note">
        <i data-lucide="shield-check" aria-hidden="true"></i>
        <div><strong>Conferência anterior ao Siafic</strong><p>O TrilhaGov prepara e diagnostica os dados. A prévia não transmite informações ao TCESP e deve ser homologada com a contabilidade e o fornecedor do sistema contábil.</p></div>
    </div>

    <div class="audesp-metrics mb-4">
        <article><span><i data-lucide="gauge" aria-hidden="true"></i></span><div><small>Prontidão</small><strong>{{ $readiness['score'] }}%</strong></div></article>
        <article><span><i data-lucide="circle-check" aria-hidden="true"></i></span><div><small>Conferências atendidas</small><strong>{{ collect($readiness['checks'])->where('passed', true)->count() }}/{{ count($readiness['checks']) }}</strong></div></article>
        <article><span><i data-lucide="circle-dollar-sign" aria-hidden="true"></i></span><div><small>Total liquidado</small><strong>R$ {{ number_format($liquidatedAmount, 2, ',', '.') }}</strong></div></article>
        <article class="{{ $unlinkedPayments ? 'has-risk' : '' }}"><span><i data-lucide="link-2" aria-hidden="true"></i></span><div><small>Pagamentos sem liquidação</small><strong>{{ $unlinkedPayments }}</strong></div></article>
    </div>

    <section class="content-panel audesp-readiness {{ $readiness['ready'] ? 'is-ready' : 'has-blockers' }} mb-4">
        <div class="content-panel-header">
            <div class="d-flex align-items-center gap-2"><i data-lucide="{{ $readiness['ready'] ? 'badge-check' : 'shield-alert' }}" aria-hidden="true"></i><h2 class="h5 mb-0">Diagnóstico da prévia</h2></div>
            <strong>{{ $readiness['ready'] ? 'Apta para prévia' : count($readiness['blockers']).' bloqueio(s)' }}</strong>
        </div>
        <div class="audesp-meter" role="progressbar" aria-label="Prontidão Audesp" aria-valuenow="{{ $readiness['score'] }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $readiness['score'] }}%"></span></div>
        @if ($readiness['blockers'] || $readiness['warnings'])
            <div class="audesp-diagnostics">
                @foreach ($readiness['blockers'] as $blocker)<div><i data-lucide="circle-alert" aria-hidden="true"></i><span>{{ $blocker }}</span></div>@endforeach
                @foreach ($readiness['warnings'] as $warning)<div class="warning"><i data-lucide="triangle-alert" aria-hidden="true"></i><span>{{ $warning }}</span></div>@endforeach
            </div>
        @else
            <p class="audesp-ready-copy"><i data-lucide="circle-check" aria-hidden="true"></i>Os campos do cadastro e a cadeia financeira passaram pelas conferências locais.</p>
        @endif
    </section>

    <section class="content-panel mb-4" id="cadastro-audesp">
        <div class="content-panel-header">
            <div class="d-flex align-items-center gap-2"><i data-lucide="database-zap" aria-hidden="true"></i><h2 class="h5 mb-0">Cadastro de Emendas Parlamentares</h2></div>
            <span class="small text-secondary">Comunicados Audesp 17, 19 e 24/2026</span>
        </div>

        @if ($canEdit)
            <form class="audesp-form" method="POST" action="{{ route('emendas.audesp.update', $amendment) }}" novalidate>
                @csrf
                @method('PUT')
                <input name="_submission_token" type="hidden" value="{{ $registrationToken }}">
                <div class="audesp-form-grid">
                    <div><label class="form-label" for="audesp_scope">Âmbito</label><input class="form-control" id="audesp_scope" value="M · Municipal" disabled><small>Definido pelo alcance desta emenda.</small></div>
                    <div><label class="form-label" for="amendment_type">Tipo da emenda <span class="required-mark">*</span></label><select class="form-select @error('amendment_type') is-invalid @enderror" id="amendment_type" name="amendment_type" required><option value="">Selecione</option>@foreach ($amendmentTypes as $value => $label)<option value="{{ $value }}" @selected((string) old('amendment_type', $registration?->amendment_type) === (string) $value)>{{ $value }} · {{ $label }}</option>@endforeach</select>@error('amendment_type')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="legal_basis">Fundamento legal <span class="required-mark">*</span></label><select class="form-select @error('legal_basis') is-invalid @enderror" id="legal_basis" name="legal_basis" required><option value="">Selecione</option>@foreach ($legalBases as $value => $label)<option value="{{ $value }}" @selected(old('legal_basis', $registration?->legal_basis) === $value)>{{ $label }}</option>@endforeach</select>@error('legal_basis')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="amendment_year">Ano da emenda <span class="required-mark">*</span></label><input class="form-control @error('amendment_year') is-invalid @enderror" id="amendment_year" name="amendment_year" type="number" min="2000" max="2099" value="{{ old('amendment_year', $registration?->amendment_year ?? $amendment->fiscal_year) }}" required>@error('amendment_year')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="span-2"><label class="form-label" for="proponent_name">Parlamentar proponente <span class="required-mark">*</span></label><input class="form-control @error('proponent_name') is-invalid @enderror" id="proponent_name" name="proponent_name" value="{{ old('proponent_name', $registration?->proponent_name ?? $amendment->author_name) }}" minlength="10" maxlength="100" required>@error('proponent_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="amendment_number">Número oficial <span class="required-mark">*</span></label><input class="form-control @error('amendment_number') is-invalid @enderror" id="amendment_number" name="amendment_number" value="{{ old('amendment_number', $registration?->amendment_number ?? $amendment->reference) }}" minlength="3" maxlength="30" required>@error('amendment_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="destination">Destinação <span class="required-mark">*</span></label><select class="form-select @error('destination') is-invalid @enderror" id="destination" name="destination" required><option value="">Selecione</option>@foreach ($destinations as $value => $label)<option value="{{ $value }}" @selected(old('destination', $registration?->destination ?? ($amendment->expense_destination === 'investment' ? 'I' : 'C')) === $value)>{{ $value }} · {{ $label }}</option>@endforeach</select>@error('destination')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="span-2"><label class="form-label" for="object">Objeto da emenda <span class="required-mark">*</span></label><textarea class="form-control @error('object') is-invalid @enderror" id="object" name="object" rows="3" minlength="10" maxlength="1000" required>{{ old('object', $registration?->object ?? $amendment->object) }}</textarea>@error('object')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="span-2"><label class="form-label" for="purpose">Finalidade da emenda <span class="required-mark">*</span></label><textarea class="form-control @error('purpose') is-invalid @enderror" id="purpose" name="purpose" rows="3" minlength="10" maxlength="1000" required>{{ old('purpose', $registration?->purpose ?? '') }}</textarea>@error('purpose')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="government_function">Função de governo <span class="required-mark">*</span></label><select class="form-select @error('government_function') is-invalid @enderror" id="government_function" name="government_function" required><option value="">Selecione</option>@foreach ($governmentFunctions as $value => $label)<option value="{{ $value }}" @selected((string) old('government_function', $registration?->government_function) === (string) $value)>{{ $value }} · {{ $label }}</option>@endforeach</select>@error('government_function')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="government_subfunctions">Subfunção(ões) <span class="required-mark">*</span></label><input class="form-control @error('government_subfunctions') is-invalid @enderror" id="government_subfunctions" name="government_subfunctions" value="{{ $subfunctions }}" placeholder="Ex.: 301, 302" required>@error('government_subfunctions')<div class="invalid-feedback">{{ $message }}</div>@enderror<small>Códigos oficiais de três dígitos, separados por vírgula.</small></div>
                    <div><label class="form-label" for="bank_account_opened">Conta específica aberta? <span class="required-mark">*</span></label><select class="form-select @error('bank_account_opened') is-invalid @enderror" id="bank_account_opened" name="bank_account_opened" required><option value="1" @selected((string) old('bank_account_opened', $bankAccountOpened) === '1')>S · Sim</option><option value="0" @selected((string) old('bank_account_opened', $bankAccountOpened) === '0')>N · Não</option></select>@error('bank_account_opened')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="application_code">Código de aplicação combinado <span class="required-mark">*</span></label><input class="form-control @error('application_code') is-invalid @enderror" id="application_code" name="application_code" value="{{ old('application_code', $registration?->application_code ?? $suggestedApplicationCode) }}" minlength="4" maxlength="7" inputmode="numeric" placeholder="Ex.: 8001" required>@error('application_code')<div class="invalid-feedback">{{ $message }}</div>@enderror<small>Prefixo oficial + parte variável, sem ponto ou hífen.</small></div>
                    <div class="audesp-code-summary span-2"><span>Fonte</span><strong>{{ $amendment->funding_source_code ?: 'Não informada' }}</strong><span>Aplicação fixa</span><strong>{{ $amendment->application_code_fixed ?: 'Não informada' }}</strong><span>Aplicação variável</span><strong>{{ $amendment->application_code_variable ?: 'Não informada' }}</strong></div>
                    <div class="span-2"><div class="form-check"><input class="form-check-input" id="prior_balance_reclassified" name="prior_balance_reclassified" type="checkbox" value="1" @checked(old('prior_balance_reclassified', $registration?->prior_balance_reclassified))><label class="form-check-label" for="prior_balance_reclassified">Saldo de exercício anterior reclassificado</label></div><small>Obrigatório para saldo remanescente de emenda de 2025 ou anterior.</small></div>
                    <div><label class="form-label" for="reclassification_reference">Referência da movimentação</label><input class="form-control @error('reclassification_reference') is-invalid @enderror" id="reclassification_reference" name="reclassification_reference" value="{{ old('reclassification_reference', $registration?->reclassification_reference) }}" maxlength="120">@error('reclassification_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="reclassified_at">Data da reclassificação</label><input class="form-control @error('reclassified_at') is-invalid @enderror" id="reclassified_at" name="reclassified_at" type="date" value="{{ old('reclassified_at', $registration?->reclassified_at?->toDateString()) }}">@error('reclassified_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                </div>
                <div class="audesp-form-footer">
                    <small>
                        @if ($registration)
                            Última atualização em {{ $registration->updated_at->format('d/m/Y H:i') }} por {{ $registration->creator->name }}.
                        @else
                            O salvamento ficará registrado na auditoria da emenda.
                        @endif
                    </small>
                    <button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar e diagnosticar</button>
                </div>
            </form>
        @elseif ($registration)
            <dl class="audesp-readonly-grid"><div><dt>Número e ano</dt><dd>{{ $registration->amendment_number }}/{{ $registration->amendment_year }}</dd></div><div><dt>Tipo</dt><dd>{{ $amendmentTypes[$registration->amendment_type] }}</dd></div><div><dt>Função</dt><dd>{{ $registration->government_function }} · {{ $governmentFunctions[$registration->government_function] }}</dd></div><div><dt>Subfunções</dt><dd>{{ implode(', ', $registration->government_subfunctions) }}</dd></div><div><dt>Código combinado</dt><dd>{{ $registration->application_code }}</dd></div><div><dt>Conta específica</dt><dd>{{ $registration->bank_account_opened ? 'Sim' : 'Não' }}</dd></div></dl>
        @else
            <div class="empty-state">O cadastro contábil Audesp ainda não foi preenchido.</div>
        @endif
    </section>

    <section class="content-panel" id="cadeia-contabil">
        <div class="content-panel-header">
            <div class="d-flex align-items-center gap-2"><i data-lucide="waypoints" aria-hidden="true"></i><h2 class="h5 mb-0">Cadeia empenho → liquidação → pagamento</h2></div>
            <span class="small text-secondary">Lei 4.320/1964 · trilha imutável</span>
        </div>
        <div class="audesp-chain-list">
            @forelse ($amendment->financialCommitments as $commitment)
                @php($remainingToLiquidate = $commitment->remainingToLiquidate())
                <article class="audesp-chain-item {{ $commitment->status === 'cancelled' ? 'is-cancelled' : '' }}">
                    <div class="audesp-chain-head"><div><span class="chain-step">1</span><span><strong>Empenho {{ $commitment->commitment_number }}</strong><small>{{ $commitment->committed_at->format('d/m/Y') }} · {{ $commitment->supplier_name }}</small></span></div><strong>R$ {{ number_format($commitment->committed_amount, 2, ',', '.') }}</strong></div>
                    <div class="audesp-chain-body">
                        <div class="audesp-chain-column"><div class="chain-column-title"><span class="chain-step">2</span><strong>Liquidações</strong><small>R$ {{ number_format($commitment->liquidatedAmount(), 2, ',', '.') }}</small></div>@forelse ($commitment->liquidations as $liquidation)<div class="chain-record"><span><strong>{{ $liquidation->liquidation_reference }}</strong><small>{{ $liquidation->liquidated_at->format('d/m/Y') }} · {{ $liquidation->supporting_document }}</small><small>Ateste: {{ $liquidation->acceptance_reference }}</small></span><strong>R$ {{ number_format($liquidation->amount, 2, ',', '.') }}</strong></div>@empty<div class="chain-empty">Nenhuma liquidação registrada.</div>@endforelse</div>
                        <div class="audesp-chain-column"><div class="chain-column-title"><span class="chain-step">3</span><strong>Pagamentos</strong><small>R$ {{ number_format($commitment->paidAmount(), 2, ',', '.') }}</small></div>@forelse ($commitment->payments as $payment)<div class="chain-record {{ $payment->financial_liquidation_id ? '' : 'has-risk' }}"><span><strong>{{ $payment->payment_reference }}</strong><small>{{ $payment->paid_at->format('d/m/Y') }} · {{ $payment->liquidation?->liquidation_reference ?? 'Sem liquidação vinculada' }}</small></span><strong>R$ {{ number_format($payment->amount, 2, ',', '.') }}</strong></div>@empty<div class="chain-empty">Nenhum pagamento registrado.</div>@endforelse</div>
                    </div>
                    @if ($canEdit && $commitment->status === 'active' && $remainingToLiquidate > 0)
                        <details class="audesp-liquidation-form"><summary><i data-lucide="plus" aria-hidden="true"></i>Registrar liquidação</summary><form method="POST" action="{{ route('emendas.liquidations.store', [$amendment, $commitment]) }}" novalidate>@csrf<input name="_submission_token" type="hidden" value="{{ $liquidationTokens->get($commitment->id) }}"><div><label class="form-label" for="liquidation_reference_{{ $commitment->id }}">Referência <span class="required-mark">*</span></label><input class="form-control" id="liquidation_reference_{{ $commitment->id }}" name="liquidation_reference" required></div><div><label class="form-label" for="liquidation_amount_{{ $commitment->id }}">Valor <span class="required-mark">*</span></label><input class="form-control" id="liquidation_amount_{{ $commitment->id }}" name="amount" type="number" min="0.01" max="{{ $remainingToLiquidate }}" step="0.01" required></div><div><label class="form-label" for="liquidated_at_{{ $commitment->id }}">Data <span class="required-mark">*</span></label><input class="form-control" id="liquidated_at_{{ $commitment->id }}" name="liquidated_at" type="date" min="{{ $commitment->committed_at->toDateString() }}" required></div><div><label class="form-label" for="supporting_document_{{ $commitment->id }}">Nota fiscal ou medição <span class="required-mark">*</span></label><input class="form-control" id="supporting_document_{{ $commitment->id }}" name="supporting_document" required></div><div><label class="form-label" for="acceptance_reference_{{ $commitment->id }}">Ateste ou termo de recebimento <span class="required-mark">*</span></label><input class="form-control" id="acceptance_reference_{{ $commitment->id }}" name="acceptance_reference" required></div><div class="span-2"><label class="form-label" for="liquidation_notes_{{ $commitment->id }}">Observação</label><input class="form-control" id="liquidation_notes_{{ $commitment->id }}" name="notes" maxlength="1000"></div><button class="btn btn-primary" type="submit"><i data-lucide="check" aria-hidden="true"></i>Confirmar liquidação</button></form></details>
                    @endif
                </article>
            @empty
                <div class="empty-state">Registre o primeiro empenho na aba Execução para iniciar a cadeia contábil.</div>
            @endforelse
        </div>
    </section>
@endsection
