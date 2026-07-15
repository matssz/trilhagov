@extends('layouts.app')

@section('title', 'Nova emenda | TrilhaGov')

@section('content')
    <a class="d-inline-block mb-3" href="{{ route('emendas.index') }}">Voltar para emendas</a>
    <div class="mb-4">
        <p class="page-kicker mb-2">{{ $municipality->name }} / {{ $municipality->state }}</p>
        <h1 class="h3 mb-1">Cadastrar emenda</h1>
    </div>
    <form method="POST" action="{{ route('emendas.store') }}" novalidate>
        @csrf
        <input name="_submission_token" type="hidden" value="{{ $submissionToken }}">
        @error('_submission_token')
            <div class="alert alert-warning">{{ $message }}</div>
        @enderror
        <div class="content-panel p-3 p-md-4">
            @include('amendments._form')
        </div>
        <div class="d-flex flex-column-reverse flex-sm-row justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ route('emendas.index') }}">Cancelar</a>
            <button class="btn btn-primary" type="submit">Cadastrar emenda</button>
        </div>
    </form>
@endsection
