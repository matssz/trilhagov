<?php

namespace App\Notifications;

use App\Models\LegislativeProposal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LegislativeProposalNotification extends Notification
{
    use Queueable;

    /** @param array<int, string> $channels */
    public function __construct(
        private readonly LegislativeProposal $proposal,
        private readonly string $title,
        private readonly string $message,
        private readonly string $severity,
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
            ->subject("TrilhaGov: {$this->title}")
            ->greeting("Olá, {$notifiable->name}.")
            ->line($this->message)
            ->line("Proposta: {$this->proposal->reference} · {$this->proposal->author_name}")
            ->action('Abrir proposta', route('legislative.show', $this->proposal));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'municipality_id' => $this->proposal->municipality_id,
            'legislative_proposal_id' => $this->proposal->id,
            'amendment_reference' => $this->proposal->reference,
            'severity' => $this->severity,
            'title' => $this->title,
            'message' => $this->message,
            'url' => route('legislative.show', $this->proposal),
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'legislative-proposal';
    }
}
