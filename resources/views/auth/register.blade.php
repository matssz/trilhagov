@extends('layouts.app')

@section('title', 'Cadastrar município | TrilhaGov')

@section('content')
    <div class="auth-shell">
        <a class="d-inline-block mb-3" href="{{ route('login') }}">Voltar para o login</a>
        <div class="mb-4">
            <p class="page-kicker mb-2">Primeiro acesso</p>
            <h1 class="h3 mb-1">Cadastrar município</h1>
        </div>

        <div class="auth-panel">
            <form method="POST" action="{{ route('register') }}" novalidate>
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $submissionToken }}">
                @error('_submission_token')
                    <div class="alert alert-warning">{{ $message }}</div>
                @enderror
                <h2 class="h6 mb-3">Responsável pelo acesso</h2>
                <div class="mb-3">
                    <label class="form-label" for="name">Nome</label>
                    <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">E-mail</label>
                    <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" required>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label" for="password">Senha</label>
                        <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" required>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="password_confirmation">Confirmar senha</label>
                        <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" required>
                    </div>
                </div>

                <h2 class="h6 mb-3 pt-3 border-top">Município</h2>
                <div class="mb-3">
                    <label class="form-label" for="municipality_name">Nome do município</label>
                    <input class="form-control @error('municipality_name') is-invalid @enderror" id="municipality_name" name="municipality_name" value="{{ old('municipality_name') }}" required>
                    @error('municipality_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-5">
                        <label class="form-label" for="state">UF</label>
                        <select class="form-select @error('state') is-invalid @enderror" id="state" name="state" required>
                            <option value="">Selecione</option>
                            @foreach ($states as $uf => $stateName)
                                <option value="{{ $uf }}" @selected(old('state') === $uf)>{{ $uf }} · {{ $stateName }}</option>
                            @endforeach
                        </select>
                        @error('state')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12 col-md-7">
                        <label class="form-label" for="ibge_code">Código IBGE</label>
                        <input class="form-control @error('ibge_code') is-invalid @enderror" id="ibge_code" name="ibge_code" value="{{ old('ibge_code') }}" inputmode="numeric" maxlength="7" required>
                        @error('ibge_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="cnpj">CNPJ principal</label>
                    <input class="form-control @error('cnpj') is-invalid @enderror" id="cnpj" name="cnpj" value="{{ old('cnpj') }}" inputmode="numeric" maxlength="18" required>
                    @error('cnpj')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-primary w-100" type="submit">Criar acesso</button>
            </form>
        </div>
    </div>
@endsection
