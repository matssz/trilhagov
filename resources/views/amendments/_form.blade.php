@php($amendment = $amendment ?? null)

@if ($activeRegulatoryProfiles->isNotEmpty())
    <div class="normative-form-context">
        <i data-lucide="landmark" aria-hidden="true"></i>
        <div>
            <strong>Normas municipais vigentes</strong>
            <span>@foreach($activeRegulatoryProfiles as $rules){{ $rules->fiscal_year }}/v{{ $rules->version }}{{ ! $loop->last ? ' · ' : '' }}@endforeach</span>
        </div>
        <a href="{{ route('municipal-rules.index') }}">Consultar</a>
    </div>
@endif

<section class="form-section">
    <div class="form-section-heading"><span>1</span><h2 class="h5 mb-0">Identificação</h2></div>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="reference">Identificação da emenda <span class="required-mark">*</span></label>
            <input class="form-control @error('reference') is-invalid @enderror" id="reference" name="reference" value="{{ old('reference', $amendment?->reference) }}" maxlength="100" autofocus required>
            @error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label" for="fiscal_year">Exercício <span class="required-mark">*</span></label>
            <input class="form-control @error('fiscal_year') is-invalid @enderror" id="fiscal_year" name="fiscal_year" type="number" value="{{ old('fiscal_year', $amendment?->fiscal_year ?? now()->year) }}" min="2000" max="{{ now()->year + 1 }}" required>
            @error('fiscal_year')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label" for="government_sphere">Esfera <span class="required-mark">*</span></label>
            <select class="form-select @error('government_sphere') is-invalid @enderror" id="government_sphere" name="government_sphere" required>
                @foreach ($governmentSpheres as $value => $label)
                    <option value="{{ $value }}" @selected(old('government_sphere', $amendment?->government_sphere ?? 'municipal') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('government_sphere')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="authorship_type">Tipo de autoria <span class="required-mark">*</span></label>
            <select class="form-select @error('authorship_type') is-invalid @enderror" id="authorship_type" name="authorship_type" required>
                @foreach ($authorshipTypes as $value => $label)
                    <option value="{{ $value }}" @selected(old('authorship_type', $amendment?->authorship_type ?? 'individual') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('authorship_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="transfer_type">Modalidade <span class="required-mark">*</span></label>
            <select class="form-select @error('transfer_type') is-invalid @enderror" id="transfer_type" name="transfer_type" required>
                @foreach ($transferTypes as $value => $label)
                    <option value="{{ $value }}" @selected(old('transfer_type', $amendment?->transfer_type ?? 'special') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('transfer_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="transferegov_code">Código Transferegov</label>
            <input class="form-control @error('transferegov_code') is-invalid @enderror" id="transferegov_code" name="transferegov_code" value="{{ old('transferegov_code', $amendment?->transferegov_code) }}" maxlength="100">
            <div class="form-text">Obrigatório para emendas federais.</div>
            @error('transferegov_code')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
    </div>
</section>

<section class="form-section">
    <div class="form-section-heading"><span>2</span><h2 class="h5 mb-0">Origem e destinação</h2></div>
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label" for="author_name">Autor da emenda <span class="required-mark">*</span></label>
            <input class="form-control @error('author_name') is-invalid @enderror" id="author_name" name="author_name" value="{{ old('author_name', $amendment?->author_name) }}" maxlength="255" required>
            @error('author_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="author_party">Partido <span class="required-mark">*</span></label>
            <input class="form-control text-uppercase @error('author_party') is-invalid @enderror" id="author_party" name="author_party" value="{{ old('author_party', $amendment?->author_party) }}" maxlength="20">
            <div class="form-text">Obrigatório para autoria individual.</div>
            @error('author_party')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <label class="form-label" for="object">Objeto <span class="required-mark">*</span></label>
            <textarea class="form-control @error('object') is-invalid @enderror" id="object" name="object" rows="4" maxlength="5000" required>{{ old('object', $amendment?->object) }}</textarea>
            @error('object')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="responsible_department">Secretaria ou órgão responsável <span class="required-mark">*</span></label>
            <input class="form-control @error('responsible_department') is-invalid @enderror" id="responsible_department" name="responsible_department" value="{{ old('responsible_department', $amendment?->responsible_department) }}" maxlength="255" required>
            @error('responsible_department')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="responsible_user_id">Responsável operacional <span class="required-mark">*</span></label>
            <select class="form-select @error('responsible_user_id') is-invalid @enderror" id="responsible_user_id" name="responsible_user_id" required>
                <option value="">Selecione uma pessoa</option>
                @foreach ($responsibleUsers as $responsibleUser)
                    <option value="{{ $responsibleUser->id }}" @selected((string) old('responsible_user_id', $amendment?->responsible_user_id) === (string) $responsibleUser->id)>
                        {{ $responsibleUser->name }} · {{ App\Models\User::municipalityRoles()[$responsibleUser->pivot->role] }}
                    </option>
                @endforeach
            </select>
            <div class="form-text">Receberá os alertas e responderá pelo acompanhamento.</div>
            @error('responsible_user_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
    </div>
</section>

<section class="form-section">
    <div class="form-section-heading"><span>3</span><h2 class="h5 mb-0">Transparência e rastreabilidade municipal</h2></div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="expense_destination">Destinação da despesa</label>
            <select class="form-select @error('expense_destination') is-invalid @enderror" id="expense_destination" name="expense_destination">
                <option value="">Selecione</option>
                @foreach ($expenseDestinations as $value => $label)<option value="{{ $value }}" @selected(old('expense_destination', $amendment?->expense_destination) === $value)>{{ $label }}</option>@endforeach
            </select>
            @error('expense_destination')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-8">
            <label class="form-label" for="beneficiary_location">Município ou localidade beneficiada</label>
            <input class="form-control @error('beneficiary_location') is-invalid @enderror" id="beneficiary_location" name="beneficiary_location" value="{{ old('beneficiary_location', $amendment?->beneficiary_location ?? $municipality->name) }}" maxlength="255">
            @error('beneficiary_location')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="administrative_process">Processo administrativo</label>
            <input class="form-control @error('administrative_process') is-invalid @enderror" id="administrative_process" name="administrative_process" value="{{ old('administrative_process', $amendment?->administrative_process) }}" maxlength="255">
            @error('administrative_process')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="legal_instrument">Instrumento jurídico <span class="text-secondary fw-normal">(quando houver)</span></label>
            <input class="form-control @error('legal_instrument') is-invalid @enderror" id="legal_instrument" name="legal_instrument" value="{{ old('legal_instrument', $amendment?->legal_instrument) }}" maxlength="255" placeholder="Ex.: Termo de Fomento nº 04/2026">
            @error('legal_instrument')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="bank_tracking_type">Rastreabilidade dos recursos</label>
            <select class="form-select @error('bank_tracking_type') is-invalid @enderror" id="bank_tracking_type" name="bank_tracking_type">
                <option value="">Selecione</option>
                @foreach ($bankTrackingTypes as $value => $label)<option value="{{ $value }}" @selected(old('bank_tracking_type', $amendment?->bank_tracking_type) === $value)>{{ $label }}</option>@endforeach
            </select>
            <div class="form-text">A dispensa da conta individualizada é exclusiva da execução direta pela Prefeitura.</div>
            @error('bank_tracking_type')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="bank_account_number">Número da conta bancária específica</label>
            <input class="form-control @error('bank_account_number') is-invalid @enderror" id="bank_account_number" name="bank_account_number" value="{{ old('bank_account_number', $amendment?->bank_account_number) }}" maxlength="100">
            @error('bank_account_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="funding_source_code">Fonte de Recursos</label>
            <input class="form-control @error('funding_source_code') is-invalid @enderror" id="funding_source_code" name="funding_source_code" value="{{ old('funding_source_code', $amendment?->funding_source_code) }}" maxlength="100">
            @error('funding_source_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="application_code_fixed">Código de Aplicação Fixo</label>
            <input class="form-control @error('application_code_fixed') is-invalid @enderror" id="application_code_fixed" name="application_code_fixed" value="{{ old('application_code_fixed', $amendment?->application_code_fixed) }}" maxlength="100">
            @error('application_code_fixed')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="application_code_variable">Código de Aplicação Variável</label>
            <input class="form-control @error('application_code_variable') is-invalid @enderror" id="application_code_variable" name="application_code_variable" value="{{ old('application_code_variable', $amendment?->application_code_variable) }}" maxlength="100">
            @error('application_code_variable')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</section>

<section class="form-section">
    <div class="form-section-heading"><span>4</span><h2 class="h5 mb-0">Valores e situação</h2></div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="expected_amount">Valor previsto <span class="required-mark">*</span></label>
            <div class="input-group"><span class="input-group-text">R$</span><input class="form-control @error('expected_amount') is-invalid @enderror" id="expected_amount" name="expected_amount" type="number" step="0.01" min="0" value="{{ old('expected_amount', $amendment?->expected_amount) }}" required></div>
            @error('expected_amount')<div class="field-error mt-1">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="received_amount">Valor recebido</label>
            <div class="input-group"><span class="input-group-text">R$</span><input class="form-control @error('received_amount') is-invalid @enderror" id="received_amount" name="received_amount" type="number" step="0.01" min="0" value="{{ old('received_amount', $amendment?->received_amount) }}" data-required-for-status></div>
            <div class="form-text">Obrigatório após o recebimento do recurso.</div>
            @error('received_amount')<div class="field-error mt-1">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="status">Situação <span class="required-mark">*</span></label>
            <select class="form-select @error('status') is-invalid @enderror" id="status" name="status" required>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $amendment?->status ?? 'identified') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="indicated_at">Data da indicação <span class="required-mark">*</span></label>
            <input class="form-control @error('indicated_at') is-invalid @enderror" id="indicated_at" name="indicated_at" type="date" value="{{ old('indicated_at', $amendment?->indicated_at?->format('Y-m-d')) }}" max="{{ today()->format('Y-m-d') }}" required>
            @error('indicated_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="received_at">Data do recebimento</label>
            <input class="form-control @error('received_at') is-invalid @enderror" id="received_at" name="received_at" type="date" value="{{ old('received_at', $amendment?->received_at?->format('Y-m-d')) }}" max="{{ today()->format('Y-m-d') }}" data-required-for-status>
            <div class="form-text">Obrigatória após o recebimento do recurso.</div>
            @error('received_at')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="cancelled_at">Data do cancelamento</label>
            <input class="form-control @error('cancelled_at') is-invalid @enderror" id="cancelled_at" name="cancelled_at" type="date" value="{{ old('cancelled_at', $amendment?->cancelled_at?->format('Y-m-d')) }}" max="{{ today()->format('Y-m-d') }}">
            @error('cancelled_at')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="cancellation_reason">Motivo do cancelamento</label>
            <textarea class="form-control @error('cancellation_reason') is-invalid @enderror" id="cancellation_reason" name="cancellation_reason" rows="2" maxlength="3000">{{ old('cancellation_reason', $amendment?->cancellation_reason) }}</textarea>
            @error('cancellation_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</section>

<section class="form-section">
    <div class="form-section-heading"><span>5</span><h2 class="h5 mb-0">Prazos de controle</h2></div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="communication_deadline">Comunicação e publicidade <span class="required-mark">*</span></label>
            <input class="form-control @error('communication_deadline') is-invalid @enderror" id="communication_deadline" name="communication_deadline" type="date" value="{{ old('communication_deadline', $amendment?->communication_deadline?->format('Y-m-d')) }}" required>
            @error('communication_deadline')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <label class="form-label small mt-3" for="communication_completed_at">Concluído em</label>
            <input class="form-control form-control-sm @error('communication_completed_at') is-invalid @enderror" id="communication_completed_at" name="communication_completed_at" type="date" value="{{ old('communication_completed_at', $amendment?->communication_completed_at?->format('Y-m-d')) }}" max="{{ today()->format('Y-m-d') }}" data-required-when-completed>
            @error('communication_completed_at')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="execution_deadline">Execução <span class="required-mark">*</span></label>
            <input class="form-control @error('execution_deadline') is-invalid @enderror" id="execution_deadline" name="execution_deadline" type="date" value="{{ old('execution_deadline', $amendment?->execution_deadline?->format('Y-m-d')) }}" required>
            @error('execution_deadline')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <label class="form-label small mt-3" for="execution_completed_at">Concluído em</label>
            <input class="form-control form-control-sm @error('execution_completed_at') is-invalid @enderror" id="execution_completed_at" name="execution_completed_at" type="date" value="{{ old('execution_completed_at', $amendment?->execution_completed_at?->format('Y-m-d')) }}" max="{{ today()->format('Y-m-d') }}" data-required-when-completed>
            @error('execution_completed_at')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="application_deadline">Aplicação dos recursos</label>
            <input class="form-control @error('application_deadline') is-invalid @enderror" id="application_deadline" name="application_deadline" type="date" value="{{ old('application_deadline', $amendment?->application_deadline?->format('Y-m-d')) }}">
            <div class="form-text">Prazo publicado para aplicação do recurso.</div>
            @error('application_deadline')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="accountability_deadline">Prestação de contas <span class="required-mark">*</span></label>
            <input class="form-control @error('accountability_deadline') is-invalid @enderror" id="accountability_deadline" name="accountability_deadline" type="date" value="{{ old('accountability_deadline', $amendment?->accountability_deadline?->format('Y-m-d')) }}" required>
            @error('accountability_deadline')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <label class="form-label small mt-3" for="accountability_completed_at">Concluído em</label>
            <input class="form-control form-control-sm @error('accountability_completed_at') is-invalid @enderror" id="accountability_completed_at" name="accountability_completed_at" type="date" value="{{ old('accountability_completed_at', $amendment?->accountability_completed_at?->format('Y-m-d')) }}" max="{{ today()->format('Y-m-d') }}" data-required-when-completed>
            @error('accountability_completed_at')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <label class="form-label" for="notes">Observações internas <span class="text-secondary fw-normal">(opcional)</span></label>
            <textarea class="form-control @error('notes') is-invalid @enderror" id="notes" name="notes" rows="3" maxlength="10000">{{ old('notes', $amendment?->notes) }}</textarea>
            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</section>
