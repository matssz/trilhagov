@extends('layouts.app')

@section('title', 'Notificações - TrilhaGov')

@section('content')
    <div class="page-heading d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3">
        <div>
            <span class="eyebrow">Preferências pessoais</span>
            <h1>Notificações</h1>
            <p>Avisos destinados a você no contexto de {{ $municipality->name }}.</p>
        </div>
        <form method="POST" action="{{ route('notifications.read-all') }}">
            @csrf
            <button class="btn btn-outline-secondary" type="submit"><i data-lucide="check-check" aria-hidden="true"></i>Marcar todas como lidas</button>
        </form>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <section class="content-panel">
                <div class="content-panel-header"><h2 class="h5 mb-0">Caixa de entrada</h2></div>
                <div class="notification-list">
                    @forelse ($notifications as $notification)
                        @php
                            $notificationSeverity = $notification->data['severity'] ?? 'info';
                            $notificationSeverityLabel = match ($notificationSeverity) {
                                'critical' => 'Crítico',
                                'warning' => 'Atenção',
                                default => 'Informativo',
                            };
                        @endphp
                        <article class="notification-item {{ $notification->read_at ? '' : 'is-unread' }}">
                            <span class="notification-dot" aria-hidden="true"></span>
                            <div>
                                <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                                    <strong>{{ $notification->data['title'] ?? 'Alerta do TrilhaGov' }}</strong>
                                    <span class="severity-badge severity-{{ $notificationSeverity }}">{{ $notificationSeverityLabel }}</span>
                                    @if (($notification->data['escalation_level'] ?? 0) > 0)
                                        <span class="escalation-badge escalation-{{ $notification->data['escalation_level'] }}">Escalonamento {{ $notification->data['escalation_level'] }}</span>
                                    @endif
                                </div>
                                <p>{{ $notification->data['message'] ?? '' }}</p>
                                <small>{{ $notification->data['amendment_reference'] ?? '' }} · {{ $notification->created_at->diffForHumans() }}</small>
                            </div>
                            <div class="notification-actions">
                                <a class="icon-button" href="{{ $notification->data['url'] ?? route('alerts.index') }}" title="Abrir emenda" aria-label="Abrir emenda"><i data-lucide="external-link" aria-hidden="true"></i></a>
                                @if (! $notification->read_at)
                                    <form method="POST" action="{{ route('notifications.read', $notification) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button class="icon-button" type="submit" title="Marcar como lida" aria-label="Marcar como lida"><i data-lucide="check" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="empty-state py-5">Nenhuma notificação recebida.</div>
                    @endforelse
                </div>
                @if ($notifications->hasPages())
                    <div class="content-panel-body border-top">{{ $notifications->links() }}</div>
                @endif
            </section>
        </div>

        <div class="col-xl-4">
            <section class="content-panel mb-4">
                <div class="content-panel-header"><h2 class="h5 mb-0">Meus avisos</h2></div>
                <div class="content-panel-body">
                    <form method="POST" action="{{ route('notifications.preferences.update') }}">
                        @csrf
                        @method('PATCH')
                        <div class="notification-preference">
                            <div><strong>Dentro do sistema</strong><small>Sino e caixa de entrada</small></div>
                            <div class="form-check form-switch"><input class="form-check-input" name="notify_in_app" type="checkbox" value="1" @checked($membership->notify_in_app) aria-label="Receber dentro do sistema"></div>
                        </div>
                        <div class="notification-preference">
                            <div><strong>E-mail</strong><small>Enviado ao e-mail da conta</small></div>
                            <div class="form-check form-switch"><input class="form-check-input" name="notify_email" type="checkbox" value="1" @checked($membership->notify_email) aria-label="Receber por e-mail"></div>
                        </div>
                        <hr>
                        <div class="notification-preference">
                            <div><strong>Prazos</strong><small>Próximos, críticos e vencidos</small></div>
                            <div class="form-check form-switch"><input class="form-check-input" name="notify_deadlines" type="checkbox" value="1" @checked($membership->notify_deadlines) aria-label="Receber alertas de prazos"></div>
                        </div>
                        <div class="notification-preference">
                            <div><strong>Integridade</strong><small>Documentos e divergências</small></div>
                            <div class="form-check form-switch"><input class="form-check-input" name="notify_integrity" type="checkbox" value="1" @checked($membership->notify_integrity) aria-label="Receber alertas de integridade"></div>
                        </div>
                        <button class="btn btn-primary w-100 mt-3" type="submit">Salvar preferências</button>
                    </form>
                </div>
            </section>

            <section class="content-panel">
                <div class="content-panel-header"><h2 class="h5 mb-0">Outros canais</h2></div>
                <div class="channel-list">
                    <div><i data-lucide="message-square" aria-hidden="true"></i><span><strong>WhatsApp</strong><small>Requer API oficial, telefone e consentimento</small></span><span class="badge text-bg-light">Planejado</span></div>
                    <div><i data-lucide="smartphone" aria-hidden="true"></i><span><strong>SMS</strong><small>Requer provedor, telefone e orçamento</small></span><span class="badge text-bg-light">Planejado</span></div>
                    <div><i data-lucide="bell-ring" aria-hidden="true"></i><span><strong>Push no navegador</strong><small>Requer HTTPS e permissão do usuário</small></span><span class="badge text-bg-light">Planejado</span></div>
                    <div><i data-lucide="webhook" aria-hidden="true"></i><span><strong>Teams, Slack e webhook</strong><small>Integração institucional configurável</small></span><span class="badge text-bg-light">Planejado</span></div>
                </div>
            </section>
        </div>
    </div>
@endsection
