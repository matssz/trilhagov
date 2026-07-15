@php($amendment = $amendment ?? null)

<section class="form-section">
    <h2 class="h5 mb-3">Identificação</h2>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="reference">Identificação da emenda</label>
            <input class="form-control @error('reference') is-invalid @enderror" id="reference" name="reference" value="{{ old('reference', $amendment?->reference) }}" maxlength="100" autofocus required>
            @error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label" for="fiscal_year">Exercício</label>
            <input class="form-control @error('fiscal_year') is-invalid @enderror" id="fiscal_year" name="fiscal_year" type="number" value="{{ old('fiscal_year', $amendment?->fiscal_year ?? now()->year) }}" min="2000" max="{{ now()->year + 1 }}" required>
            @error('fiscal_year')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label" for="government_sphere">Esfera</label>
            <select class="form-select @error('government_sphere') is-invalid @enderror" id="government_sphere" name="government_sphere" required>
                @foreach ($governmentSpheres as $value => $label)
                    <option value="{{ $value }}" @selected(old('government_sphere', $amendment?->government_sphere ?? 'federal') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('government_sphere')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="authorship_type">Tipo de autoria</label>
            <select class="form-select @error('authorship_type') is-invalid @enderror" id="authorship_type" name="authorship_type" required>
                @foreach ($authorshipTypes as $value => $label)
                    <option value="{{ $value }}" @selected(old('authorship_type', $amendment?->authorship_type ?? 'individual') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('authorship_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="transfer_type">Modalidade</label>
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
            @error('transferegov_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</section>

<section class="form-section">
    <h2 class="h5 mb-3">Origem e destinação</h2>
    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label" for="author_name">Autor da emenda</label>
            <input class="form-control @error('author_name') is-invalid @enderror" id="author_name" name="author_name" value="{{ old('author_name', $amendment?->author_name) }}" maxlength="255" required>
            @error('author_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="author_party">Partido</label>
            <input class="form-control text-uppercase @error('author_party') is-invalid @enderror" id="author_party" name="author_party" value="{{ old('author_party', $amendment?->author_party) }}" maxlength="20">
            @error('author_party')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <label class="form-label" for="object">Objeto</label>
            <textarea class="form-control @error('object') is-invalid @enderror" id="object" name="object" rows="4" maxlength="5000" required>{{ old('object', $amendment?->object) }}</textarea>
            @error('object')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <label class="form-label" for="responsible_department">Secretaria ou órgão responsável</label>
            <input class="form-control @error('responsible_department') is-invalid @enderror" id="responsible_department" name="responsible_department" value="{{ old('responsible_department', $amendment?->responsible_department) }}" maxlength="255" required>
            @error('responsible_department')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</section>

<section class="form-section">
    <h2 class="h5 mb-3">Valores e situação</h2>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="expected_amount">Valor previsto</label>
            <div class="input-group"><span class="input-group-text">R$</span><input class="form-control @error('expected_amount') is-invalid @enderror" id="expected_amount" name="expected_amount" type="number" step="0.01" min="0" value="{{ old('expected_amount', $amendment?->expected_amount) }}" required></div>
            @error('expected_amount')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="received_amount">Valor recebido</label>
            <div class="input-group"><span class="input-group-text">R$</span><input class="form-control @error('received_amount') is-invalid @enderror" id="received_amount" name="received_amount" type="number" step="0.01" min="0" value="{{ old('received_amount', $amendment?->received_amount) }}"></div>
            @error('received_amount')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="status">Situação</label>
            <select class="form-select @error('status') is-invalid @enderror" id="status" name="status" required>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $amendment?->status ?? 'identified') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="indicated_at">Data da indicação</label>
            <input class="form-control @error('indicated_at') is-invalid @enderror" id="indicated_at" name="indicated_at" type="date" value="{{ old('indicated_at', $amendment?->indicated_at?->format('Y-m-d')) }}">
            @error('indicated_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="received_at">Data do recebimento</label>
            <input class="form-control @error('received_at') is-invalid @enderror" id="received_at" name="received_at" type="date" value="{{ old('received_at', $amendment?->received_at?->format('Y-m-d')) }}">
            @error('received_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</section>

<section class="form-section">
    <h2 class="h5 mb-3">Prazos de controle</h2>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="communication_deadline">Comunicação e publicidade</label>
            <input class="form-control @error('communication_deadline') is-invalid @enderror" id="communication_deadline" name="communication_deadline" type="date" value="{{ old('communication_deadline', $amendment?->communication_deadline?->format('Y-m-d')) }}">
            @error('communication_deadline')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <label class="form-label small mt-2" for="communication_completed_at">Concluído em</label>
            <input class="form-control form-control-sm @error('communication_completed_at') is-invalid @enderror" id="communication_completed_at" name="communication_completed_at" type="date" value="{{ old('communication_completed_at', $amendment?->communication_completed_at?->format('Y-m-d')) }}">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="execution_deadline">Execução</label>
            <input class="form-control @error('execution_deadline') is-invalid @enderror" id="execution_deadline" name="execution_deadline" type="date" value="{{ old('execution_deadline', $amendment?->execution_deadline?->format('Y-m-d')) }}">
            @error('execution_deadline')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <label class="form-label small mt-2" for="execution_completed_at">Concluído em</label>
            <input class="form-control form-control-sm @error('execution_completed_at') is-invalid @enderror" id="execution_completed_at" name="execution_completed_at" type="date" value="{{ old('execution_completed_at', $amendment?->execution_completed_at?->format('Y-m-d')) }}">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="accountability_deadline">Prestação de contas</label>
            <input class="form-control @error('accountability_deadline') is-invalid @enderror" id="accountability_deadline" name="accountability_deadline" type="date" value="{{ old('accountability_deadline', $amendment?->accountability_deadline?->format('Y-m-d')) }}">
            @error('accountability_deadline')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <label class="form-label small mt-2" for="accountability_completed_at">Concluído em</label>
            <input class="form-control form-control-sm @error('accountability_completed_at') is-invalid @enderror" id="accountability_completed_at" name="accountability_completed_at" type="date" value="{{ old('accountability_completed_at', $amendment?->accountability_completed_at?->format('Y-m-d')) }}">
        </div>
        <div class="col-12">
            <label class="form-label" for="notes">Observações internas</label>
            <textarea class="form-control @error('notes') is-invalid @enderror" id="notes" name="notes" rows="3" maxlength="10000">{{ old('notes', $amendment?->notes) }}</textarea>
            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</section>
