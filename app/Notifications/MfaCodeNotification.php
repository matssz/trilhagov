<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MfaCodeNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $code)
    {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Codigo de acesso TrilhaGov')
            ->greeting('Verificacao em duas etapas')
            ->line('Use o codigo abaixo para concluir seu login no TrilhaGov.')
            ->line($this->code)
            ->line('Este codigo expira em 10 minutos. Se voce nao tentou entrar, troque sua senha e avise o gestor municipal.');
    }
}
