@extends('layouts.app')

@section('title', 'Aceitar convite | TrilhaGov')

@section('content')
    <div class="auth-shell">
        <div class="mb-4">
            <p class="page-kicker mb-2">Acesso municipal</p>
            <h1 class="h3 mb-1">Aceitar convite</h1>
        </div>

        <div class="auth-panel">
            <div class="invitation-summary mb-4">
                <strong>{{ $invitation->municipality->name }} / {{ $invitation->municipality->state }}</strong>
                <span>Perfil: {{ $invitation->roleLabel() }}</span>
                <small>Válido até {{ $invitation->expires_at->format('d/m/Y \à\s H:i') }}</small>
            </div>

            <x-validation-summary />

            <form method="POST" action="{{ route('invitations.accept', $token) }}" novalidate>
                @csrf

                @if ($needsRegistration)
                    <div class="mb-3">
                        <label class="form-label" for="name">Seu nome <span class="required-mark">*</span></label>
                        <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" autocomplete="name" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="invited_email">E-mail convidado</label>
                        <input class="form-control" id="invited_email" type="email" value="{{ $invitation->email }}" readonly>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label" for="password">Criar senha <span class="required-mark">*</span></label>
                            <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" autocomplete="new-password" required>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="password_confirmation">Confirmar senha <span class="required-mark">*</span></label>
                            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
                        </div>
                    </div>
                @else
                    <div class="alert alert-light border mb-4">
                        O acesso será adicionado à conta <strong>{{ auth()->user()->email }}</strong>.
                    </div>
                @endif

                <button class="btn btn-primary w-100" type="submit">
                    <i data-lucide="circle-check" aria-hidden="true"></i>Aceitar e acessar município
                </button>
            </form>
        </div>
    </div>
@endsection
