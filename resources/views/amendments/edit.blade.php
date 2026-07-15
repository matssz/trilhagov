@extends('layouts.app')

@section('title', 'Editar emenda | TrilhaGov')

@section('content')
    <a class="d-inline-block mb-3" href="{{ route('emendas.show', $amendment) }}">Voltar para a emenda</a>
    <div class="mb-4">
        <p class="page-kicker mb-2">{{ $municipality->name }} / {{ $municipality->state }}</p>
        <h1 class="h3 mb-1">Editar {{ $amendment->reference }}</h1>
    </div>
    <form method="POST" action="{{ route('emendas.update', $amendment) }}" data-amendment-form novalidate>
        @csrf
        @method('PUT')
        <input name="_submission_token" type="hidden" value="{{ $submissionToken }}">
        <x-validation-summary />
        @error('_submission_token')
            <div class="alert alert-warning">{{ $message }}</div>
        @enderror
        <div class="content-panel p-3 p-md-4">
            @include('amendments._form')
        </div>
        <div class="d-flex flex-column-reverse flex-sm-row justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ route('emendas.show', $amendment) }}">Cancelar</a>
            <button class="btn btn-primary" type="submit"><i data-lucide="circle-check" aria-hidden="true"></i>Salvar alterações</button>
        </div>
    </form>
@endsection
