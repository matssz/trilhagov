@extends('layouts.app')

@section('title', 'Entrar | Emendas Municipais')

@section('content')
    <div class="auth-shell">
        <div class="mb-4">
            <p class="page-kicker mb-2">Acesso restrito</p>
            <h1 class="h3 mb-1">Entrar</h1>
            <p class="text-secondary mb-0">Gestão municipal de emendas parlamentares.</p>
        </div>

        <div class="auth-panel">
            <form method="POST" action="{{ route('login') }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="email">E-mail</label>
                    <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" autofocus required>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Senha</label>
                    <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" autocomplete="current-password" required>
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" id="remember" name="remember" type="checkbox" value="1">
                    <label class="form-check-label" for="remember">Manter conectado</label>
                </div>
                <button class="btn btn-primary w-100" type="submit">Entrar</button>
            </form>
        </div>
        <p class="text-center text-secondary mt-4">Primeiro acesso? <a href="{{ route('register') }}">Cadastrar município</a></p>
    </div>
@endsection
