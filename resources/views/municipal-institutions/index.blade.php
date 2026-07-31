@extends('layouts.app')

@section('title', 'Cadastros municipais | TrilhaGov')

@section('content')
    <div class="page-heading institutions-heading">
        <div>
            <span class="page-kicker">{{ $municipality->name }} / {{ $municipality->state }}</span>
            <h1>Cadastros municipais</h1>
            <p>Base institucional para secretarias, vereadores, unidades, fornecedores, beneficiarios e fiscais.</p>
        </div>
        @if ($canEdit)
            <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#new-institution" aria-expanded="{{ $errors->any() ? 'true' : 'false' }}" aria-controls="new-institution">
                <i data-lucide="plus" aria-hidden="true"></i>Novo cadastro
            </button>
        @endif
    </div>

    <div class="metric-strip institution-metrics">
        <article><span>Total cadastrado</span><strong>{{ $metrics['total'] }}</strong><small>{{ $metrics['active'] }} ativo(s)</small></article>
        <article><span>Secretarias e orgaos</span><strong>{{ $metrics['departments'] }}</strong><small>Base para filtros e responsaveis</small></article>
        <article><span>Vereadores</span><strong>{{ $metrics['councilors'] }}</strong><small>Camara municipal</small></article>
        <article><span>Fornecedores / beneficiarios</span><strong>{{ $metrics['suppliers'] + $metrics['beneficiaries'] }}</strong><small>Prestacao, contratos e relatorios</small></article>
    </div>

    @if ($canEdit)
        <section class="collapse {{ $errors->any() ? 'show' : '' }} content-panel institution-create-panel" id="new-institution">
            <div class="content-panel-header"><div><span class="step-index">1</span><h2 class="h5 mb-0">Adicionar cadastro reutilizavel</h2></div><span class="legal-badge">Base municipal</span></div>
            <form class="institution-form-grid" method="POST" action="{{ route('municipal-institutions.store') }}" novalidate data-prevent-double-submit>
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $createToken }}">
                <div><label class="form-label" for="type">Tipo <span class="required-mark">*</span></label><select class="form-select @error('type') is-invalid @enderror" id="type" name="type" required>@foreach($types as $value => $label)<option value="{{ $value }}" @selected(old('type', $selectedType) === $value)>{{ $label }}</option>@endforeach</select>@error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="name">Nome de uso <span class="required-mark">*</span></label><input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" maxlength="255" required>@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="document">CPF/CNPJ</label><input class="form-control @error('document') is-invalid @enderror" id="document" name="document" value="{{ old('document') }}" maxlength="20">@error('document')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="department">Secretaria vinculada</label><input class="form-control" id="department" name="department" value="{{ old('department') }}" maxlength="255"></div>
                <div><label class="form-label" for="role_title">Cargo / funcao</label><input class="form-control" id="role_title" name="role_title" value="{{ old('role_title') }}" maxlength="255"></div>
                <div><label class="form-label" for="party">Partido</label><input class="form-control" id="party" name="party" value="{{ old('party') }}" maxlength="30"></div>
                <div><label class="form-label" for="email">E-mail</label><input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" maxlength="255">@error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="phone">Telefone</label><input class="form-control" id="phone" name="phone" value="{{ old('phone') }}" maxlength="40"></div>
                <div class="span-2"><label class="form-label" for="legal_name">Razao social / nome completo</label><input class="form-control" id="legal_name" name="legal_name" value="{{ old('legal_name') }}" maxlength="255"></div>
                <div><label class="form-label" for="city">Cidade</label><input class="form-control" id="city" name="city" value="{{ old('city', $municipality->name) }}" maxlength="120"></div>
                <div><label class="form-label" for="state">UF</label><input class="form-control @error('state') is-invalid @enderror" id="state" name="state" value="{{ old('state', $municipality->state) }}" maxlength="2">@error('state')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-2"><label class="form-label" for="address">Endereco</label><input class="form-control" id="address" name="address" value="{{ old('address') }}" maxlength="255"></div>
                <div class="span-2"><label class="form-label" for="notes">Observacoes</label><input class="form-control" id="notes" name="notes" value="{{ old('notes') }}" maxlength="2000"></div>
                <div class="span-4 institution-form-actions"><button class="btn btn-primary" type="submit"><i data-lucide="building-2" aria-hidden="true"></i>Cadastrar</button></div>
            </form>
        </section>
    @endif

    <form class="contract-filter-bar" method="GET">
        <label><span>Tipo</span><select class="form-select" name="type"><option value="">Todos</option>@foreach($types as $value => $label)<option value="{{ $value }}" @selected($selectedType === $value)>{{ $label }}</option>@endforeach</select></label>
        <label><span>Status</span><select class="form-select" name="status"><option value="active" @selected($selectedStatus !== 'inactive')>Ativos</option><option value="inactive" @selected($selectedStatus === 'inactive')>Inativos</option></select></label>
        <label class="contract-filter-search"><span>Pesquisa</span><input class="form-control" name="search" value="{{ $search }}" placeholder="Nome, CNPJ, secretaria ou vinculo"></label>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="list-filter" aria-hidden="true"></i>Filtrar</button>
    </form>

    <section class="content-panel institution-list-panel">
        <div class="content-panel-header"><div><span class="page-kicker">Base institucional</span><h2 class="h5 mb-0">Cadastros do Municipio</h2></div><span class="record-count">{{ $institutions->total() }}</span></div>
        @if($institutions->isEmpty())
            <div class="empty-state"><i data-lucide="building-2" aria-hidden="true"></i><h3>Nenhum cadastro neste recorte</h3><p>Cadastre primeiro as secretarias, vereadores e unidades que mais aparecem nas emendas municipais.</p></div>
        @else
            <div class="institution-list">
                @foreach($institutions as $institution)
                    <article class="institution-row {{ $institution->is_active ? '' : 'is-inactive' }}">
                        <div class="institution-main">
                            <span class="institution-icon"><i data-lucide="{{ match($institution->type) { 'department' => 'landmark', 'executing_unit' => 'building-2', 'councilor' => 'badge-user', 'beneficiary' => 'hand-heart', 'supplier' => 'briefcase-business', default => 'shield-check' } }}" aria-hidden="true"></i></span>
                            <div>
                                <span class="page-kicker">{{ $institution->typeLabel() }}{{ $institution->document ? ' · '.$institution->document : '' }}</span>
                                <strong>{{ $institution->name }}</strong>
                                <small>{{ $institution->department ?: ($institution->role_title ?: ($institution->legal_name ?: 'Sem vinculo adicional')) }}</small>
                            </div>
                        </div>
                        <div class="institution-contact">
                            <small>Contato</small>
                            <strong>{{ $institution->email ?: 'Nao informado' }}</strong>
                            <span>{{ $institution->phone ?: ($institution->city ? $institution->city.'/'.$institution->state : 'Sem telefone') }}</span>
                        </div>
                        <span class="status-pill is-{{ $institution->is_active ? 'success' : 'danger' }}">{{ $institution->is_active ? 'Ativo' : 'Inativo' }}</span>
                        @if ($canEdit)
                            <details class="institution-editor">
                                <summary class="icon-button" title="Editar cadastro" aria-label="Editar {{ $institution->name }}"><i data-lucide="pencil" aria-hidden="true"></i></summary>
                                <form class="institution-form-grid compact" method="POST" action="{{ route('municipal-institutions.update', $institution) }}" novalidate data-prevent-double-submit>
                                    @csrf
                                    @method('PATCH')
                                    <input name="_submission_token" type="hidden" value="{{ $updateTokens->get($institution->id) }}">
                                    <div><label class="form-label" for="type_{{ $institution->id }}">Tipo</label><select class="form-select" id="type_{{ $institution->id }}" name="type">@foreach($types as $value => $label)<option value="{{ $value }}" @selected($institution->type === $value)>{{ $label }}</option>@endforeach</select></div>
                                    <div><label class="form-label" for="name_{{ $institution->id }}">Nome</label><input class="form-control" id="name_{{ $institution->id }}" name="name" value="{{ $institution->name }}" required></div>
                                    <div><label class="form-label" for="document_{{ $institution->id }}">CPF/CNPJ</label><input class="form-control" id="document_{{ $institution->id }}" name="document" value="{{ $institution->document }}"></div>
                                    <div><label class="form-label" for="department_{{ $institution->id }}">Secretaria vinculada</label><input class="form-control" id="department_{{ $institution->id }}" name="department" value="{{ $institution->department }}"></div>
                                    <div><label class="form-label" for="role_title_{{ $institution->id }}">Cargo / funcao</label><input class="form-control" id="role_title_{{ $institution->id }}" name="role_title" value="{{ $institution->role_title }}"></div>
                                    <div><label class="form-label" for="party_{{ $institution->id }}">Partido</label><input class="form-control" id="party_{{ $institution->id }}" name="party" value="{{ $institution->party }}"></div>
                                    <div><label class="form-label" for="email_{{ $institution->id }}">E-mail</label><input class="form-control" id="email_{{ $institution->id }}" name="email" type="email" value="{{ $institution->email }}"></div>
                                    <div><label class="form-label" for="phone_{{ $institution->id }}">Telefone</label><input class="form-control" id="phone_{{ $institution->id }}" name="phone" value="{{ $institution->phone }}"></div>
                                    <div><label class="form-label" for="is_active_{{ $institution->id }}">Status</label><select class="form-select" id="is_active_{{ $institution->id }}" name="is_active"><option value="1" @selected($institution->is_active)>Ativo</option><option value="0" @selected(! $institution->is_active)>Inativo</option></select></div>
                                    <div class="span-2"><label class="form-label" for="legal_name_{{ $institution->id }}">Nome completo / razao social</label><input class="form-control" id="legal_name_{{ $institution->id }}" name="legal_name" value="{{ $institution->legal_name }}"></div>
                                    <div><label class="form-label" for="city_{{ $institution->id }}">Cidade</label><input class="form-control" id="city_{{ $institution->id }}" name="city" value="{{ $institution->city }}"></div>
                                    <div><label class="form-label" for="state_{{ $institution->id }}">UF</label><input class="form-control" id="state_{{ $institution->id }}" name="state" value="{{ $institution->state }}" maxlength="2"></div>
                                    <div class="span-2"><label class="form-label" for="address_{{ $institution->id }}">Endereco</label><input class="form-control" id="address_{{ $institution->id }}" name="address" value="{{ $institution->address }}"></div>
                                    <div class="span-2"><label class="form-label" for="notes_{{ $institution->id }}">Observacoes</label><input class="form-control" id="notes_{{ $institution->id }}" name="notes" value="{{ $institution->notes }}"></div>
                                    <div class="span-4 institution-form-actions"><button class="btn btn-primary" type="submit"><i data-lucide="check" aria-hidden="true"></i>Salvar cadastro</button></div>
                                </form>
                            </details>
                        @endif
                    </article>
                @endforeach
            </div>
            <div class="panel-pagination">{{ $institutions->links() }}</div>
        @endif
    </section>
@endsection
