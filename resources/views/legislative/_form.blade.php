@php($editing = isset($proposal))
@php($minimumAmount = $automation['minimum_amount'] ?? 0.01)
@php($maximumAmount = $automation['maximum_amount'] ?? null)
@php($htmlMinimumAmount = $maximumAmount !== null && $maximumAmount < $minimumAmount ? 0.01 : $minimumAmount)

<div class="legislative-simple-form">
    <section class="legislative-form-section span-2">
        <div class="legislative-section-heading">
            <span>1</span>
            <div><h2>Pedido principal</h2><p>Informe o que deseja indicar e o valor estimado. O sistema confere cota e saúde automaticamente.</p></div>
        </div>
        <div class="legislative-fields">
            @unless($editing)
                <label><span>Exercício <b>*</b></span><input class="form-control @error('fiscal_year') is-invalid @enderror" name="fiscal_year" type="number" min="{{ now()->year }}" max="{{ now()->year + 2 }}" value="{{ old('fiscal_year', $year) }}" required>@error('fiscal_year')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            @endunless
            <label><span>Valor estimado (R$) <b>*</b></span><input class="form-control @error('estimated_amount') is-invalid @enderror" name="estimated_amount" type="number" min="{{ $htmlMinimumAmount }}" @if($maximumAmount !== null) max="{{ $maximumAmount }}" @endif step="0.01" value="{{ old('estimated_amount', $proposal->estimated_amount ?? '') }}" required>@error('estimated_amount')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label class="{{ $editing ? 'span-2' : '' }}"><span>Objeto específico <b>*</b></span><textarea class="form-control @error('object') is-invalid @enderror" name="object" rows="3" minlength="20" maxlength="5000" required placeholder="Ex.: Aquisição de equipamento para a unidade de saúde do bairro...">{{ old('object', $proposal->object ?? '') }}</textarea>@error('object')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label><span>Secretaria ou órgão executor <b>*</b></span><input class="form-control @error('responsible_department') is-invalid @enderror" name="responsible_department" value="{{ old('responsible_department', $proposal->responsible_department ?? '') }}" maxlength="255" required placeholder="Ex.: Secretaria de Saúde">@error('responsible_department')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label><span>Natureza da despesa <b>*</b></span><select class="form-select @error('expense_destination') is-invalid @enderror" name="expense_destination" required><option value="">Selecione</option>@foreach($expenseDestinations as $value => $label)<option value="{{ $value }}" @selected(old('expense_destination', $proposal->expense_destination ?? '') === $value)>{{ $label }}</option>@endforeach</select>@error('expense_destination')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label class="legislative-switch health"><input class="form-check-input" name="health_related" type="checkbox" value="1" @checked(old('health_related', $proposal->health_related ?? false))><span><strong>Destinar para saúde</strong><small>O sistema usa esta marcação para conferir a reserva mínima do vereador.</small></span></label>
        </div>
    </section>

    <section class="legislative-form-section span-2">
        <div class="legislative-section-heading">
            <span>2</span>
            <div><h2>Destino e justificativa</h2><p>Use linguagem simples. A equipe técnica complementa o que for necessário depois.</p></div>
        </div>
        <div class="legislative-fields">
            <label><span>Beneficiário ou local atendido <b>*</b></span><input class="form-control @error('beneficiary_name') is-invalid @enderror" name="beneficiary_name" value="{{ old('beneficiary_name', $proposal->beneficiary_name ?? '') }}" maxlength="255" required placeholder="Ex.: UBS Central, Escola Municipal...">@error('beneficiary_name')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label><span>Localidade</span><input class="form-control @error('beneficiary_location') is-invalid @enderror" name="beneficiary_location" value="{{ old('beneficiary_location', $proposal->beneficiary_location ?? $municipality->name) }}" maxlength="255">@error('beneficiary_location')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label class="span-2"><span>Justificativa de interesse público <b>*</b></span><textarea class="form-control @error('justification') is-invalid @enderror" name="justification" rows="4" minlength="30" maxlength="5000" required placeholder="Explique o problema, quem será atendido e por que essa entrega importa.">{{ old('justification', $proposal->justification ?? '') }}</textarea>@error('justification')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
        </div>
    </section>

    <input type="hidden" name="priority" value="{{ old('priority', $proposal->priority ?? 'normal') }}">

    <details class="legislative-extra-fields span-2">
        <summary><span><i data-lucide="sliders-horizontal" aria-hidden="true"></i><strong>Detalhes opcionais</strong><small>Programa, ação orçamentária, quantidade, prazo e dados de entidade.</small></span><i data-lucide="chevron-down" aria-hidden="true"></i></summary>
        <div class="legislative-fields">
            <label><span>Tipo de beneficiário</span><select class="form-select @error('beneficiary_type') is-invalid @enderror" name="beneficiary_type">@foreach($beneficiaryTypes as $value => $label)<option value="{{ $value }}" @selected(old('beneficiary_type', $proposal->beneficiary_type ?? 'municipal_body') === $value)>{{ $label }}</option>@endforeach</select>@error('beneficiary_type')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label><span>CNPJ da entidade</span><input class="form-control @error('beneficiary_cnpj') is-invalid @enderror" name="beneficiary_cnpj" value="{{ old('beneficiary_cnpj', $proposal->beneficiary_cnpj ?? '') }}" maxlength="20">@error('beneficiary_cnpj')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label><span>Forma de execução</span><select class="form-select @error('transfer_type') is-invalid @enderror" name="transfer_type">@foreach($transferTypes as $value => $label)<option value="{{ $value }}" @selected(old('transfer_type', $proposal->transfer_type ?? 'direct_execution') === $value)>{{ $label }}</option>@endforeach</select>@error('transfer_type')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label><span>Fonte da estimativa</span><input class="form-control @error('estimate_source') is-invalid @enderror" name="estimate_source" value="{{ old('estimate_source', $proposal->estimate_source ?? '') }}" maxlength="255" placeholder="Cotação, contrato similar ou pesquisa simples">@error('estimate_source')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label><span>Programa do PPA/LOA</span><input class="form-control @error('program_reference') is-invalid @enderror" name="program_reference" value="{{ old('program_reference', $proposal->program_reference ?? '') }}" maxlength="255">@error('program_reference')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label><span>Ação orçamentária</span><input class="form-control @error('action_reference') is-invalid @enderror" name="action_reference" value="{{ old('action_reference', $proposal->action_reference ?? '') }}" maxlength="255">@error('action_reference')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label class="span-2"><span>Necessidade pública</span><textarea class="form-control @error('public_need') is-invalid @enderror" name="public_need" rows="3" maxlength="5000">{{ old('public_need', $proposal->public_need ?? '') }}</textarea>@error('public_need')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label><span>População atendida</span><input class="form-control @error('target_population') is-invalid @enderror" name="target_population" value="{{ old('target_population', $proposal->target_population ?? '') }}" maxlength="255">@error('target_population')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label><span>Quantidade ou entrega estimada</span><input class="form-control @error('estimated_quantity') is-invalid @enderror" name="estimated_quantity" value="{{ old('estimated_quantity', $proposal->estimated_quantity ?? '') }}" maxlength="255">@error('estimated_quantity')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label><span>Data pretendida</span><input class="form-control @error('desired_contract_at') is-invalid @enderror" name="desired_contract_at" type="date" value="{{ old('desired_contract_at', isset($proposal) ? $proposal->desired_contract_at?->toDateString() : '') }}">@error('desired_contract_at')<small class="invalid-feedback">{{ $message }}</small>@enderror</label>
            <label class="legislative-switch"><input class="form-check-input" name="third_sector_conflict_declaration" type="checkbox" value="1" @checked(old('third_sector_conflict_declaration', $proposal->third_sector_conflict_declaration ?? false))><span><strong>Declaração preliminar de conflito</strong><small>Não há vínculo ou conflito conhecido com dirigentes da entidade indicada.</small></span></label>
        </div>
    </details>
</div>
