@props(['code', 'title', 'message'])

<div class="error-state">
    <img class="error-state-symbol" src="{{ asset('images/trilhagov-symbol.svg') }}" alt="">
    <p class="page-kicker mb-2">Erro {{ $code }}</p>
    <h1 class="h3 mb-2">{{ $title }}</h1>
    <p class="text-secondary mb-4">{{ $message }}</p>
    <a class="btn btn-primary" href="{{ auth()->check() ? route('dashboard') : route('login') }}">Voltar para o início</a>
</div>
