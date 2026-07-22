@extends('layouts.app')

@section('title', 'Trocar conta | TrilhaGov')

@section('content')
    <div class="auth-shell">
        <div class="mb-4">
            <p class="page-kicker mb-2">Acesso municipal</p>
            <h1 class="h3 mb-1">Este convite pertence a outra conta</h1>
            <p class="text-secondary mb-0">Você está conectado como {{ auth()->user()->email }}.</p>
        </div>

        <div class="auth-panel">
            <div class="invitation-summary mb-4">
                <strong>{{ $invitation->municipality->name }} / {{ $invitation->municipality->state }}</strong>
                <span>Perfil: {{ $invitation->roleLabel() }}</span>
                <small>Convite destinado a {{ $invitation->email }}</small>
            </div>

            <div class="alert alert-warning app-alert align-items-start mb-4" role="alert">
                <i data-lucide="shield-alert" aria-hidden="true"></i>
                <span>Para proteger os acessos do Município, somente o e-mail convidado pode aceitar este link.</span>
            </div>

            <form method="POST" action="{{ route('invitations.switch-account', $token) }}">
                @csrf
                <button class="btn btn-primary w-100" type="submit">
                    <i data-lucide="log-out" aria-hidden="true"></i>Sair e continuar com o convite
                </button>
            </form>
        </div>
    </div>
@endsection
