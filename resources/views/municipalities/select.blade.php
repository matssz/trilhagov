@extends('layouts.app')

@section('title', 'Selecionar município | TrilhaGov')

@section('content')
    <div class="auth-shell">
        <div class="mb-4">
            <p class="page-kicker mb-2">Contexto de trabalho</p>
            <h1 class="h3 mb-1">Selecionar município</h1>
        </div>

        <form method="POST" action="{{ route('municipalities.activate') }}">
            @csrf
            <x-validation-summary />
            <div class="d-grid gap-3 mb-3">
                @foreach ($municipalities as $municipality)
                    <label class="municipality-option">
                        <span class="d-flex align-items-start gap-3">
                            <input class="form-check-input mt-1" name="municipality_id" type="radio" value="{{ $municipality->id }}" required>
                            <span>
                                <strong class="d-block">{{ $municipality->name }} / {{ $municipality->state }}</strong>
                                <small class="text-secondary">IBGE {{ $municipality->ibge_code }}</small>
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('municipality_id')<div class="text-danger small mb-3">{{ $message }}</div>@enderror
            <button class="btn btn-primary w-100" type="submit">Continuar</button>
        </form>
    </div>
@endsection
