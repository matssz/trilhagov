@extends('layouts.app')

@section('title', 'Checklist documental | TrilhaGov')

@section('content')
    <div class="mb-4">
        <p class="page-kicker mb-2">{{ $municipality->name }} / {{ $municipality->state }}</p>
        <h1 class="h3 mb-1">Checklist documental</h1>
        <p class="text-secondary mb-0">Defina quais evidências devem ser acompanhadas em todas as emendas do município.</p>
    </div>

    <x-validation-summary />

    <section class="content-panel mb-4">
        <div class="content-panel-header">
            <h2 class="h5 mb-1">Adicionar tipo de documento</h2>
            <p class="small text-secondary mb-0">A obrigatoriedade deve seguir a modalidade da emenda e a orientação do órgão responsável.</p>
        </div>
        <div class="content-panel-body">
            <form class="document-type-create-form" method="POST" action="{{ route('document-types.store') }}" novalidate>
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $submissionToken }}">
                <div>
                    <label class="form-label" for="name">Nome <span class="required-mark">*</span></label>
                    <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="form-label" for="description">Descrição</label>
                    <input class="form-control @error('description') is-invalid @enderror" id="description" name="description" value="{{ old('description') }}" maxlength="500">
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="form-label" for="sort_order">Ordem</label>
                    <input class="form-control @error('sort_order') is-invalid @enderror" id="sort_order" name="sort_order" type="number" value="{{ old('sort_order', 0) }}" min="0" max="10000">
                    @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-check form-switch document-type-toggle">
                    <input class="form-check-input" id="is_required" name="is_required" type="checkbox" value="1" @checked(old('is_required'))>
                    <label class="form-check-label" for="is_required">Obrigatório</label>
                </div>
                <button class="btn btn-primary document-type-create-button" type="submit">
                    <i data-lucide="plus" aria-hidden="true"></i>Adicionar
                </button>
            </form>
        </div>
    </section>

    <section class="content-panel">
        <div class="content-panel-header">
            <h2 class="h5 mb-1">Tipos configurados</h2>
            <p class="small text-secondary mb-0">Desativar um tipo o remove dos próximos acompanhamentos sem apagar arquivos já enviados.</p>
        </div>
        <div class="document-type-list">
            @forelse ($documentTypes as $type)
                <form class="document-type-row" method="POST" action="{{ route('document-types.update', $type) }}">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label class="form-label" for="type-name-{{ $type->id }}">Nome</label>
                        <input class="form-control" id="type-name-{{ $type->id }}" name="name" value="{{ $type->name }}" required>
                    </div>
                    <div>
                        <label class="form-label" for="type-description-{{ $type->id }}">Descrição</label>
                        <input class="form-control" id="type-description-{{ $type->id }}" name="description" value="{{ $type->description }}" maxlength="500">
                        <div class="form-text">{{ $type->documents_count }} {{ $type->documents_count === 1 ? 'arquivo vinculado' : 'arquivos vinculados' }}</div>
                    </div>
                    <div>
                        <label class="form-label" for="type-order-{{ $type->id }}">Ordem</label>
                        <input class="form-control" id="type-order-{{ $type->id }}" name="sort_order" type="number" value="{{ $type->sort_order }}" min="0" max="10000" required>
                    </div>
                    <div class="document-type-switches">
                        <div class="form-check form-switch">
                            <input class="form-check-input" id="type-required-{{ $type->id }}" name="is_required" type="checkbox" value="1" @checked($type->is_required)>
                            <label class="form-check-label" for="type-required-{{ $type->id }}">Obrigatório</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" id="type-active-{{ $type->id }}" name="is_active" type="checkbox" value="1" @checked($type->is_active)>
                            <label class="form-check-label" for="type-active-{{ $type->id }}">Ativo</label>
                        </div>
                    </div>
                    <button class="btn btn-outline-primary document-type-save" type="submit">Salvar</button>
                </form>
            @empty
                <div class="empty-state">Nenhum tipo de documento configurado.</div>
            @endforelse
        </div>
    </section>
@endsection
