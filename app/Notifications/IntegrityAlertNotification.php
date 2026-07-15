<?php

namespace App\Notifications;

use App\Models\IntegrityAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IntegrityAlertNotification extends Notification
{
    use Queueable;

    /** @param array<int, string> $channels */
    public function __construct(
        private readonly IntegrityAlert $alert,
        private readonly array $channels,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("TrilhaGov: {$this->alert->title}")
            ->greeting("Olá, {$notifiable->name}.")
            ->line($this->alert->message)
            ->line("Emenda: {$this->alert->amendment->reference}")
            ->action('Ver emenda', route('emendas.show', $this->alert->amendment))
            ->line('Este aviso foi gerado pelas preferências de notificação do seu município.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'alert_id' => $this->alert->id,
            'municipality_id' => $this->alert->municipality_id,
            'municipality_name' => $this->alert->municipality->name,
            'amendment_id' => $this->alert->parliamentary_amendment_id,
            'amendment_reference' => $this->alert->amendment->reference,
            'category' => $this->alert->category,
            'severity' => $this->alert->severity,
            'title' => $this->alert->title,
            'message' => $this->alert->message,
            'due_at' => $this->alert->due_at?->toDateString(),
            'url' => route('emendas.show', $this->alert->amendment),
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'integrity-alert';
    }
}
