<?php

namespace App\Notifications;

use App\Models\MunicipalReportDispatch;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MunicipalReportDispatchDeadlineNotification extends Notification
{
    use Queueable;

    /** @param array<int, string> $channels */
    public function __construct(
        private readonly MunicipalReportDispatch $dispatch,
        private readonly array $channels,
        private readonly string $severity,
        private readonly string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('TrilhaGov: prazo de remessa institucional')
            ->greeting("Olá, {$notifiable->name}.")
            ->line($this->message)
            ->line("Relatório: {$this->dispatch->report->code()}")
            ->line("Destinatário: {$this->dispatch->recipient_name}")
            ->action('Abrir remessa', route('report-dispatches.show', $this->dispatch))
            ->line('O prazo segue a referência informada pelo próprio Município.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'municipality_id' => $this->dispatch->municipality_id,
            'municipality_name' => $this->dispatch->municipality->name,
            'dispatch_id' => $this->dispatch->id,
            'report_code' => $this->dispatch->report->code(),
            'category' => 'deadline',
            'severity' => $this->severity,
            'title' => 'Prazo de remessa institucional',
            'message' => $this->message,
            'due_at' => $this->dispatch->due_at->toDateString(),
            'url' => route('report-dispatches.show', $this->dispatch),
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'municipal-report-dispatch-deadline';
    }
}
