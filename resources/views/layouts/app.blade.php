<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'Emendas Municipais')</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <nav class="navbar navbar-expand-lg app-navbar" data-bs-theme="dark">
            <div class="container">
                <a class="navbar-brand fw-bold" href="{{ auth()->check() ? route('dashboard') : route('login') }}">
                    Emendas Municipais
                </a>
                @auth
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#appNavigation" aria-controls="appNavigation" aria-expanded="false" aria-label="Abrir navegação">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <div class="collapse navbar-collapse" id="appNavigation">
                        <ul class="navbar-nav me-auto">
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">Painel</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs('emendas.*') ? 'active' : '' }}" href="{{ route('emendas.index') }}">Emendas</a>
                            </li>
                        </ul>
                        <div class="d-lg-flex align-items-lg-center gap-3 mt-3 mt-lg-0">
                            <span class="navbar-text small">{{ auth()->user()->name }}</span>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-light" type="submit">Sair</button>
                            </form>
                        </div>
                    </div>
                @endauth
            </div>
        </nav>

        <main class="app-main">
            <div class="container">
                @if (session('status'))
                    <div class="alert alert-success" role="alert">{{ session('status') }}</div>
                @endif
                @if (session('warning'))
                    <div class="alert alert-warning" role="alert">{{ session('warning') }}</div>
                @endif
                @yield('content')
            </div>
        </main>
    </body>
</html>
