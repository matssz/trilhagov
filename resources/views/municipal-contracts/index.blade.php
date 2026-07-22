@extends('layouts.app')

@section('title', 'Obras e contratos | TrilhaGov')

@section('content')
    @php
        $money = function ($value) {
            return 'R$ '.number_format((float) $value, 2, ',', '.');
        };
    @endphp
    <div class="page-heading contracts-heading">
        <div><span class="page-kicker">Lei 14.133 · gestão municipal</span><h1>Obras e contratos</h1><p>Planejamento, fiscalização, medições e alterações contratuais vinculadas às emendas.</p></div>
        @if ($canEdit)<button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#new-contract" aria-expanded="{{ $errors->any() ? 'true' : 'false' }}" aria-controls="new-contract"><i data-lucide="plus" aria-hidden="true"></i>Novo processo</button>@endif
    </div>

    <div class="metric-strip contract-metrics">
        <article><span>Processos monitorados</span><strong>{{ $metrics['total'] }}</strong><small>Exercício {{ $year }}</small></article>
        <article><span>Valor contratado</span><strong>{{ $money($metrics['contracted_amount']) }}</strong><small>Valor atualizado dos contratos</small></article>
        <article><span>Em execução</span><strong>{{ $metrics['executing'] }}</strong><small>{{ $metrics['pending_measurements'] }} medição(ões) aguardando ateste</small></article>
        <article class="{{ $metrics['suspended'] + $metrics['risk'] > 0 ? 'metric-attention' : '' }}"><span>Atenção gerencial</span><strong>{{ $metrics['risk'] }}</strong><small>{{ $metrics['suspended'] }} contrato(s) paralisado(s)</small></article>
    </div>

    @if ($canEdit)
        <section class="collapse {{ $errors->any() ? 'show' : '' }} content-panel contract-create-panel" id="new-contract">
            <div class="content-panel-header"><div><span class="step-index">1</span><h2 class="h5 mb-0">Abrir processo de contratação</h2></div><span class="legal-badge">Fase preparatória</span></div>
            <form class="contract-create-grid" method="POST" action="{{ route('municipal-contracts.store') }}" novalidate data-prevent-double-submit>
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $createToken }}">
                <div><label class="form-label" for="parliamentary_amendment_id">Emenda vinculada <span class="required-mark">*</span></label><select class="form-select @error('parliamentary_amendment_id') is-invalid @enderror" id="parliamentary_amendment_id" name="parliamentary_amendment_id" required><option value="">Selecione</option>@foreach($amendments as $amendment)<option value="{{ $amendment->id }}" @selected((string) old('parliamentary_amendment_id') === (string) $amendment->id)>{{ $amendment->reference }} · {{ Str::limit($amendment->object, 62) }}</option>@endforeach</select>@error('parliamentary_amendment_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="process_number">Processo administrativo <span class="required-mark">*</span></label><input class="form-control @error('process_number') is-invalid @enderror" id="process_number" name="process_number" value="{{ old('process_number') }}" maxlength="100" required>@error('process_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="object_type">Tipo do objeto <span class="required-mark">*</span></label><select class="form-select" id="object_type" name="object_type" required>@foreach($objectTypes as $value => $label)<option value="{{ $value }}" @selected(old('object_type') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label class="form-label" for="procurement_method">Forma de contratação <span class="required-mark">*</span></label><select class="form-select" id="procurement_method" name="procurement_method" required>@foreach($procurementMethods as $value => $label)<option value="{{ $value }}" @selected(old('procurement_method') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div><label class="form-label" for="estimated_amount">Valor estimado <span class="required-mark">*</span></label><input class="form-control" id="estimated_amount" name="estimated_amount" type="number" min="0.01" step="0.01" value="{{ old('estimated_amount') }}" required></div>
                <div class="span-3"><label class="form-label" for="object">Objeto da contratação <span class="required-mark">*</span></label><textarea class="form-control @error('object') is-invalid @enderror" id="object" name="object" rows="2" maxlength="5000" required>{{ old('object') }}</textarea>@error('object')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-3 contract-create-actions"><button class="btn btn-primary" type="submit"><i data-lucide="clipboard-list" aria-hidden="true"></i>Criar processo</button></div>
            </form>
        </section>
    @endif

    <form class="contract-filter-bar" method="GET">
        <label><span>Exercício</span><input class="form-control" name="year" type="number" min="2021" max="{{ now()->year + 1 }}" value="{{ $year }}"></label>
        <label><span>Situação</span><select class="form-select" name="status"><option value="">Todas</option>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="contract-filter-search"><span>Pesquisa</span><input class="form-control" name="search" value="{{ request('search') }}" placeholder="Processo, contrato, empresa ou objeto"></label>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="list-filter" aria-hidden="true"></i>Filtrar</button>
    </form>

    <section class="content-panel contract-list-panel">
        <div class="content-panel-header"><div><span class="page-kicker">Carteira contratual</span><h2 class="h5 mb-0">Processos do Município</h2></div><span class="record-count">{{ $contracts->total() }}</span></div>
        @if($contracts->isEmpty())
            <div class="empty-state"><i data-lucide="hard-hat" aria-hidden="true"></i><h3>Nenhum processo neste recorte</h3><p>Cadastre a contratação vinculada a uma emenda para acompanhar todo o ciclo.</p></div>
        @else
            <div class="contract-list">
                @foreach($contracts as $contract)
                    @php
                        $approvedMeasurements = $contract->measurements->where('status', 'approved');
                        $physical = (float) ($approvedMeasurements->sortByDesc('sequence')->first()?->cumulative_physical_percentage ?? 0);
                        $measured = (float) $approvedMeasurements->sum('amount');
                        $financial = (float) $contract->current_amount > 0 ? min(100, round($measured / (float) $contract->current_amount * 100, 1)) : 0;
                    @endphp
                    <article class="contract-row">
                        <div class="contract-row-main"><span class="contract-type-icon"><i data-lucide="{{ in_array($contract->object_type, ['public_work','engineering_service','renovation']) ? 'hard-hat' : 'briefcase-business' }}" aria-hidden="true"></i></span><div><span class="page-kicker">{{ $contract->code() }} · {{ $contract->process_number }}</span><strong>{{ $contract->object }}</strong><small>{{ $contract->amendment->reference }} · {{ $contract->supplier_name ?: 'Fornecedor ainda não selecionado' }}</small></div></div>
                        <div class="contract-row-value"><small>Valor atualizado</small><strong>{{ $money($contract->current_amount ?? $contract->estimated_amount) }}</strong><span class="status-pill is-{{ $contract->status === 'suspended' ? 'danger' : (in_array($contract->status, ['executing','completed']) ? 'success' : 'warning') }}">{{ $contract->statusLabel() }}</span></div>
                        <div class="contract-progress"><span><small>Financeiro</small><strong>{{ number_format($financial, 1, ',', '.') }}%</strong></span><div><i style="width: {{ $financial }}%"></i><b style="width: {{ $physical }}%"></b></div><span><small>Físico</small><strong>{{ number_format($physical, 1, ',', '.') }}%</strong></span></div>
                        <a class="icon-button" href="{{ route('municipal-contracts.show', $contract) }}" title="Abrir contrato" aria-label="Abrir {{ $contract->code() }}"><i data-lucide="arrow-right" aria-hidden="true"></i></a>
                    </article>
                @endforeach
            </div>
            <div class="panel-pagination">{{ $contracts->links() }}</div>
        @endif
    </section>
@endsection
