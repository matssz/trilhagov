<?php

namespace App\Notifications;

use App\Models\MunicipalityInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MunicipalityInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly MunicipalityInvitation $invitation,
        private readonly string $acceptUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Convite para acessar a TrilhaGov')
            ->greeting('Você recebeu um convite para a TrilhaGov')
            ->line("Município: {$this->invitation->municipality->name} / {$this->invitation->municipality->state}")
            ->line("Perfil de acesso: {$this->invitation->roleLabel()}")
            ->action('Aceitar convite', $this->acceptUrl)
            ->line('Este convite expira em 7 dias e só pode ser utilizado uma vez.');
    }
}
