@php
    $workspaceLayout = auth()->check()
        && ! request()->routeIs('municipalities.*')
        && ! request()->routeIs('invitations.*')
        && ! request()->routeIs('transparency.*');
    $activeRole = $workspaceLayout
        ? auth()->user()->roleForMunicipality((int) session('active_municipality_id'))
        : null;
    $canEditAmendments = in_array($activeRole, ['manager', 'editor'], true);
    $canManageUsers = $activeRole === 'manager';
    $activeRoleLabel = App\Models\User::municipalityRoles()[$activeRole] ?? 'Usuário municipal';
    $unreadNotificationCount = $workspaceLayout
        ? auth()->user()->unreadNotifications()
            ->whereJsonContains('data->municipality_id', (int) session('active_municipality_id'))
            ->count()
        : 0;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0a2f5a">
        <title>@yield('title', 'TrilhaGov')</title>
        <link rel="icon" href="{{ asset('images/trilhagov-symbol.svg') }}" type="image/svg+xml">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="{{ $workspaceLayout ? 'has-workspace' : 'has-public-layout' }}">
        <div class="route-progress" data-route-progress aria-hidden="true"><span></span></div>
        @if ($workspaceLayout)
            <div class="app-shell">
                <aside class="offcanvas-lg offcanvas-start app-sidebar" id="appSidebar" tabindex="-1" aria-labelledby="appSidebarLabel">
                    <div class="sidebar-header">
                        <a class="brand-lockup" href="{{ route('dashboard') }}" aria-label="TrilhaGov - Painel">
                            <img class="brand-symbol" src="{{ asset('images/trilhagov-symbol.svg') }}" alt="">
                            <span class="brand-copy" id="appSidebarLabel">
                                <span class="brand-name">Trilha<span>Gov</span></span>
                                <small>Portal de Emendas</small>
                            </span>
                        </a>
                        <button class="btn-close d-lg-none" type="button" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Fechar menu"></button>
                    </div>

                    <nav class="sidebar-nav" aria-label="Navegação principal">
                        <a class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                            <i data-lucide="layout-dashboard" aria-hidden="true"></i>
                            <span>Painel</span>
                        </a>
                        <a class="sidebar-link {{ request()->routeIs('emendas.*') ? 'active' : '' }}" href="{{ route('emendas.index') }}">
                            <i data-lucide="file-text" aria-hidden="true"></i>
                            <span>Emendas</span>
                        </a>
                        @if ($canEditAmendments)
                            <a class="sidebar-link {{ request()->routeIs('spreadsheet-imports.*') ? 'active' : '' }}" href="{{ route('spreadsheet-imports.index') }}">
                                <i data-lucide="file-spreadsheet" aria-hidden="true"></i>
                                <span>Importar</span>
                            </a>
                        @endif
                        <a class="sidebar-link {{ request()->routeIs('work-center.*') ? 'active' : '' }}" href="{{ route('work-center.index') }}">
                            <i data-lucide="clipboard-check" aria-hidden="true"></i>
                            <span>Trabalho</span>
                        </a>
                        <a class="sidebar-link {{ request()->routeIs('alerts.*') ? 'active' : '' }}" href="{{ route('alerts.index') }}">
                            <i data-lucide="shield-alert" aria-hidden="true"></i>
                            <span>Integridade</span>
                            @if ($unreadNotificationCount > 0)
                                <span class="sidebar-count">{{ min($unreadNotificationCount, 99) }}</span>
                            @endif
                        </a>
                        <a class="sidebar-link {{ request()->routeIs('integrations.*') ? 'active' : '' }}" href="{{ route('integrations.index') }}">
                            <i data-lucide="database-zap" aria-hidden="true"></i>
                            <span>Integrações</span>
                        </a>
                        <a class="sidebar-link {{ request()->routeIs('municipal-rules.*') ? 'active' : '' }}" href="{{ route('municipal-rules.index') }}">
                            <i data-lucide="landmark" aria-hidden="true"></i>
                            <span>Normas municipais</span>
                        </a>
                        @if ($canManageUsers)
                            <a class="sidebar-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}">
                                <i data-lucide="users" aria-hidden="true"></i>
                                <span>Usuários</span>
                            </a>
                            <a class="sidebar-link {{ request()->routeIs('document-types.*') ? 'active' : '' }}" href="{{ route('document-types.index') }}">
                                <i data-lucide="list-checks" aria-hidden="true"></i>
                                <span>Checklist</span>
                            </a>
                        @endif
                    </nav>

                    @if ($canEditAmendments)
                        <div class="sidebar-actions">
                            <a class="btn btn-primary w-100" href="{{ route('emendas.create') }}">
                                <i data-lucide="plus" aria-hidden="true"></i>
                                <span>Nova emenda</span>
                            </a>
                        </div>
                    @else
                        <div class="sidebar-actions"></div>
                    @endif

                    <div class="sidebar-footer">
                        <div class="user-summary">
                            <span class="user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                            <span class="user-summary-copy">
                                <strong>{{ auth()->user()->name }}</strong>
                                <small>{{ $activeRoleLabel }}</small>
                            </span>
                        </div>
                        <form method="POST" action="{{ route('application.refresh') }}">
                            @csrf
                            <button class="icon-button" type="submit" title="Atualizar sistema" aria-label="Atualizar sistema" data-icon-submit>
                                <i data-lucide="refresh-cw" aria-hidden="true"></i>
                            </button>
                        </form>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="icon-button" type="submit" title="Sair" aria-label="Sair">
                                <i data-lucide="log-out" aria-hidden="true"></i>
                            </button>
                        </form>
                    </div>
                </aside>

                <div class="app-workspace">
                    <header class="app-topbar">
                        <button class="icon-button d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Abrir menu" title="Abrir menu">
                            <i data-lucide="menu" aria-hidden="true"></i>
                        </button>
                        <form class="topbar-search" method="GET" action="{{ route('emendas.index') }}" role="search">
                            <i data-lucide="search" aria-hidden="true"></i>
                            <input name="search" type="search" value="{{ request('search') }}" placeholder="Pesquisar emendas, autores ou objetos" aria-label="Pesquisar emendas">
                        </form>
                        <a class="notification-button" href="{{ route('notifications.index') }}" title="Notificações" aria-label="Notificações{{ $unreadNotificationCount > 0 ? ': '.$unreadNotificationCount.' não lidas' : '' }}">
                            <i data-lucide="bell" aria-hidden="true"></i>
                            @if ($unreadNotificationCount > 0)
                                <span>{{ min($unreadNotificationCount, 99) }}</span>
                            @endif
                        </a>
                        <div class="topbar-user">
                            <span>
                                <strong>{{ auth()->user()->name }}</strong>
                                <small>TrilhaGov Emendas</small>
                            </span>
                            <span class="user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                        </div>
                    </header>

                    <main class="app-main">
                        <div class="content-container">
                            @if (session('status'))
                                <div class="alert alert-success app-alert" role="status">
                                    <i data-lucide="circle-check" aria-hidden="true"></i>
                                    <span>{{ session('status') }}</span>
                                </div>
                            @endif
                            @if (session('warning'))
                                <div class="alert alert-warning app-alert" role="alert">
                                    <i data-lucide="triangle-alert" aria-hidden="true"></i>
                                    <span>{{ session('warning') }}</span>
                                </div>
                            @endif
                            @yield('content')
                        </div>
                    </main>
                </div>
            </div>
        @else
            <header class="public-header">
                <a class="brand-lockup" href="{{ auth()->check() ? route('municipalities.select') : route('login') }}" aria-label="TrilhaGov">
                    <img class="brand-symbol" src="{{ asset('images/trilhagov-symbol.svg') }}" alt="">
                    <span class="brand-copy">
                        <span class="brand-name">Trilha<span>Gov</span></span>
                        <small>Portal de Emendas</small>
                    </span>
                </a>
                @auth
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <form method="POST" action="{{ route('application.refresh') }}">
                            @csrf
                            <button class="icon-button" type="submit" title="Atualizar sistema" aria-label="Atualizar sistema" data-icon-submit>
                                <i data-lucide="refresh-cw" aria-hidden="true"></i>
                            </button>
                        </form>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="btn btn-outline-secondary" type="submit"><i data-lucide="log-out" aria-hidden="true"></i>Sair</button>
                        </form>
                    </div>
                @endauth
            </header>
            <main class="public-main">
                <div class="container">
                    @if (session('status'))
                        <div class="alert alert-success app-alert" role="status">
                            <i data-lucide="circle-check" aria-hidden="true"></i>
                            <span>{{ session('status') }}</span>
                        </div>
                    @endif
                    @if (session('warning'))
                        <div class="alert alert-warning app-alert" role="alert">
                            <i data-lucide="triangle-alert" aria-hidden="true"></i>
                            <span>{{ session('warning') }}</span>
                        </div>
                    @endif
                    @yield('content')
                </div>
            </main>
        @endif
    </body>
</html>
