@extends('layouts.app')

@section('title', 'Verificacao em duas etapas | TrilhaGov')

@section('content')
    <div class="auth-shell">
        <div class="mb-4">
            <p class="page-kicker mb-2">Acesso protegido</p>
            <h1 class="h3 mb-1">Verificacao em duas etapas</h1>
            <p class="text-secondary mb-0">Informe o codigo de 6 digitos para concluir o login.</p>
        </div>

        <div class="auth-panel">
            <form method="POST" action="{{ route('mfa.verify') }}" novalidate>
                @csrf
                <x-validation-summary />
                <div class="mb-3">
                    <label class="form-label" for="code">Codigo <span class="required-mark">*</span></label>
                    <input class="form-control @error('code') is-invalid @enderror" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" autofocus required>
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-primary w-100" type="submit">Confirmar acesso</button>
            </form>
        </div>
        <p class="text-center text-secondary mt-4">O codigo expira em 10 minutos. Volte ao login para gerar outro.</p>
    </div>
@endsection
