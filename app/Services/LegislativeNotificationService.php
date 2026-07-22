<?php

namespace App\Services;

use App\Models\LegislativeProposal;
use App\Models\User;
use App\Notifications\LegislativeProposalNotification;
use Illuminate\Support\Collection;
use Throwable;

class LegislativeNotificationService
{
    /** @param array<int, string> $roles */
    public function roles(
        LegislativeProposal $proposal,
        array $roles,
        string $title,
        string $message,
        string $severity = 'info',
    ): void {
        $users = $proposal->municipality->users()->wherePivotIn('role', $roles)->get();
        $this->deliver($proposal, $users, $title, $message, $severity);
    }

    public function submitter(
        LegislativeProposal $proposal,
        string $title,
        string $message,
        string $severity = 'info',
    ): void {
        $user = $proposal->municipality->users()->whereKey($proposal->submitted_by)->first();
        if ($user) {
            $this->deliver($proposal, collect([$user]), $title, $message, $severity);
        }
    }

    /** @param Collection<int, User> $users */
    private function deliver(
        LegislativeProposal $proposal,
        Collection $users,
        string $title,
        string $message,
        string $severity,
    ): void {
        foreach ($users as $user) {
            $channels = [];
            if ((bool) $user->pivot->notify_in_app) {
                $channels[] = 'database';
            }
            if ((bool) $user->pivot->notify_email) {
                $channels[] = 'mail';
            }
            if ($channels === []) {
                continue;
            }

            try {
                $user->notify(new LegislativeProposalNotification($proposal, $title, $message, $severity, $channels));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
