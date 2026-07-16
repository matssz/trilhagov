<?php

namespace App\Services;

use App\Models\AlertDelivery;
use App\Models\IntegrityAlert;
use App\Models\Municipality;
use App\Notifications\IntegrityAlertNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class IntegrityAlertProcessor
{
    public function __construct(private readonly IntegrityAlertService $alertService) {}

    /** @return array{municipalities: int, open: int, sent: int, failed: int} */
    public function process(?Municipality $onlyMunicipality = null): array
    {
        $municipalities = $onlyMunicipality !== null
            ? collect([$onlyMunicipality])
            : Municipality::query()->complete()->get();
        $stats = ['municipalities' => 0, 'open' => 0, 'sent' => 0, 'failed' => 0];

        foreach ($municipalities as $municipality) {
            $sync = $this->alertService->sync($municipality);
            $stats['municipalities']++;
            $stats['open'] += $sync['open'];

            $municipality->load('users');
            $settings = $municipality->alertSetting()->firstOrCreate([]);
            $alerts = $municipality->integrityAlerts()
                ->where('status', IntegrityAlert::STATUS_OPEN)
                ->with(['amendment', 'municipality'])
                ->get();

            foreach ($alerts as $alert) {
                $cycleKey = $this->cycleKey($alert, $settings->overdue_repeat_days);

                foreach ($municipality->users as $user) {
                    if (! $this->categoryEnabled($alert, $user->pivot)
                        || ! $this->shouldReceiveAlert($alert, $user->id, $user->pivot->role, $settings)) {
                        continue;
                    }

                    $channels = array_filter([
                        $user->pivot->notify_in_app ? 'database' : null,
                        $user->pivot->notify_email ? 'mail' : null,
                    ]);

                    foreach ($channels as $channel) {
                        $delivery = AlertDelivery::query()->firstOrCreate(
                            [
                                'integrity_alert_id' => $alert->id,
                                'user_id' => $user->id,
                                'channel' => $channel,
                                'cycle_key' => $cycleKey,
                            ],
                            ['delivered_at' => now()],
                        );

                        if (! $delivery->wasRecentlyCreated) {
                            continue;
                        }

                        try {
                            $user->notify(new IntegrityAlertNotification($alert, [$channel]));
                            $stats['sent']++;
                        } catch (Throwable $exception) {
                            $delivery->delete();
                            $stats['failed']++;
                            Log::error('Falha ao enviar alerta de integridade.', [
                                'alert_id' => $alert->id,
                                'user_id' => $user->id,
                                'channel' => $channel,
                                'exception' => $exception,
                            ]);
                        }
                    }
                }
            }
        }

        return $stats;
    }

    private function categoryEnabled(IntegrityAlert $alert, object $membership): bool
    {
        return $alert->category === IntegrityAlert::CATEGORY_DEADLINE
            ? (bool) $membership->notify_deadlines
            : (bool) $membership->notify_integrity;
    }

    private function shouldReceiveAlert(
        IntegrityAlert $alert,
        int $userId,
        string $role,
        object $settings,
    ): bool {
        if ($alert->assigned_user_id === $userId) {
            return true;
        }

        if ($alert->amendment->responsible_user_id === $userId) {
            return true;
        }

        if ($role === 'manager') {
            return $alert->amendment->responsible_user_id === null
                || $alert->severity === IntegrityAlert::SEVERITY_CRITICAL
                || ($alert->severity === IntegrityAlert::SEVERITY_WARNING && $settings->notify_managers_on_warning)
                || $alert->escalation_level > 0;
        }

        return $role === 'editor'
            && $alert->escalation_level >= 2
            && $settings->notify_editors_on_level_two;
    }

    private function cycleKey(IntegrityAlert $alert, int $overdueRepeatDays): string
    {
        if ($alert->category !== IntegrityAlert::CATEGORY_DEADLINE || $alert->due_at === null) {
            return 'open:'.$alert->detected_at->format('YmdHis');
        }

        $daysUntil = (int) today()->diffInDays($alert->due_at, false);

        if ($daysUntil < 0) {
            $cycle = intdiv(abs($daysUntil), max(1, $overdueRepeatDays));

            return "overdue:{$alert->escalation_level}:{$cycle}:".$alert->due_at->format('Ymd');
        }

        if ($daysUntil === 0) {
            return 'due:today:'.$alert->due_at->format('Ymd');
        }

        if ($daysUntil === 1) {
            return 'due:1:'.$alert->due_at->format('Ymd');
        }

        return 'due:'.$alert->severity.':'.$alert->due_at->format('Ymd');
    }
}
