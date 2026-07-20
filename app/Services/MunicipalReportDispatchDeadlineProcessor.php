<?php

namespace App\Services;

use App\Models\Municipality;
use App\Models\MunicipalReportDispatch;
use App\Models\MunicipalReportDispatchDelivery;
use App\Models\User;
use App\Notifications\MunicipalReportDispatchDeadlineNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class MunicipalReportDispatchDeadlineProcessor
{
    /** @return array{dispatches: int, sent: int, failed: int} */
    public function process(?Municipality $onlyMunicipality = null): array
    {
        $query = MunicipalReportDispatch::query()
            ->where('status', MunicipalReportDispatch::STATUS_PREPARED)
            ->with(['municipality.users', 'municipality.alertSetting', 'report']);
        if ($onlyMunicipality) {
            $query->where('municipality_id', $onlyMunicipality->id);
        }

        $stats = ['dispatches' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($query->get() as $dispatch) {
            $settings = $dispatch->municipality->alertSetting()->firstOrCreate([]);
            $daysUntil = (int) today()->diffInDays($dispatch->due_at, false);
            if ($daysUntil > $settings->deadline_warning_days) {
                continue;
            }

            $stats['dispatches']++;
            $severity = $daysUntil <= $settings->deadline_critical_days ? 'critical' : 'info';
            $message = match (true) {
                $daysUntil < 0 => 'A remessa para '.$dispatch->recipient_name.' está vencida há '.abs($daysUntil).' dia(s).',
                $daysUntil === 0 => 'A remessa para '.$dispatch->recipient_name.' vence hoje.',
                default => 'A remessa para '.$dispatch->recipient_name.' vence em '.$daysUntil.' dia(s).',
            };
            $cycleKey = $this->cycleKey($dispatch, $daysUntil, $settings->overdue_repeat_days);

            foreach ($this->recipients($dispatch) as $user) {
                $channels = array_filter([
                    $user->pivot->notify_in_app ? 'database' : null,
                    $user->pivot->notify_email ? 'mail' : null,
                ]);
                if (! $user->pivot->notify_deadlines) {
                    $channels = [];
                }

                foreach ($channels as $channel) {
                    $delivery = MunicipalReportDispatchDelivery::query()->firstOrCreate([
                        'municipal_report_dispatch_id' => $dispatch->id,
                        'user_id' => $user->id,
                        'channel' => $channel,
                        'cycle_key' => $cycleKey,
                    ], ['delivered_at' => now()]);
                    if (! $delivery->wasRecentlyCreated) {
                        continue;
                    }

                    try {
                        $user->notify(new MunicipalReportDispatchDeadlineNotification($dispatch, [$channel], $severity, $message));
                        $stats['sent']++;
                    } catch (Throwable $exception) {
                        $delivery->delete();
                        $stats['failed']++;
                        Log::error('Falha ao enviar prazo de remessa institucional.', [
                            'dispatch_id' => $dispatch->id,
                            'user_id' => $user->id,
                            'channel' => $channel,
                            'exception' => $exception,
                        ]);
                    }
                }
            }
        }

        return $stats;
    }

    /** @return Collection<int, User> */
    private function recipients(MunicipalReportDispatch $dispatch): Collection
    {
        return $dispatch->municipality->users
            ->filter(fn ($user) => $user->pivot->role === 'manager' || $user->id === $dispatch->responsible_user_id)
            ->unique('id')
            ->values();
    }

    private function cycleKey(MunicipalReportDispatch $dispatch, int $daysUntil, int $repeatDays): string
    {
        if ($daysUntil < 0) {
            return 'overdue:'.intdiv(abs($daysUntil), max(1, $repeatDays)).':'.$dispatch->due_at->format('Ymd');
        }

        return match ($daysUntil) {
            0 => 'due:today:'.$dispatch->due_at->format('Ymd'),
            1 => 'due:1:'.$dispatch->due_at->format('Ymd'),
            default => 'due:warning:'.$dispatch->due_at->format('Ymd'),
        };
    }
}
