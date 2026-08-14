<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Municipality;
use App\Models\SupportOccurrence;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class OccurrenceCenterService
{
    public function recordException(Throwable $exception, ?Request $request = null): void
    {
        if (! $this->tableReady()) {
            return;
        }

        try {
            $municipalityId = $request?->attributes->get('active_municipality')?->id
                ?? ($request?->hasSession() ? (int) ($request->session()->get('active_municipality_id') ?? 0) : 0)
                ?: null;
            $route = $request?->route();
            $routeName = is_object($route) ? $route->getName() : null;
            $message = $exception::class.': '.$exception->getMessage();
            $fingerprint = $this->fingerprint('exception', $exception::class, $routeName ?? '', $exception->getMessage());

            $this->upsertOccurrence([
                'municipality_id' => $municipalityId,
                'user_id' => $request?->user()?->id,
                'fingerprint' => $fingerprint,
                'source' => 'exception',
                'level' => 'error',
                'title' => class_basename($exception),
                'message' => Str::limit($message, 4000),
                'route_name' => $routeName,
                'method' => $request?->method(),
                'url' => $request?->fullUrl(),
                'ip_address' => $request?->ip(),
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500),
                'context' => [
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ],
            ]);
        } catch (Throwable) {
            // Monitoring must never break the application response.
        }
    }

    /** @return array<string, mixed> */
    public function dashboard(Municipality $municipality, array $filters = []): array
    {
        $this->syncRecentLogErrors();

        $query = SupportOccurrence::query()
            ->with(['user:id,name', 'resolver:id,name'])
            ->where(function ($builder) use ($municipality) {
                $builder->where('municipality_id', $municipality->id)
                    ->orWhereNull('municipality_id');
            })
            ->latest('last_seen_at');

        if (in_array($filters['status'] ?? null, array_keys(SupportOccurrence::statuses()), true)) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', '!=', SupportOccurrence::STATUS_RESOLVED);
        }

        if (filled($filters['source'] ?? null)) {
            $query->where('source', $filters['source']);
        }

        if (filled($filters['level'] ?? null)) {
            $query->where('level', $filters['level']);
        }

        $base = SupportOccurrence::query()
            ->where(function ($builder) use ($municipality) {
                $builder->where('municipality_id', $municipality->id)
                    ->orWhereNull('municipality_id');
            });

        return [
            'occurrences' => $query->paginate(12)->withQueryString(),
            'summary' => [
                'open' => (clone $base)->where('status', SupportOccurrence::STATUS_OPEN)->count(),
                'monitoring' => (clone $base)->where('status', SupportOccurrence::STATUS_MONITORING)->count(),
                'resolved' => (clone $base)->where('status', SupportOccurrence::STATUS_RESOLVED)->count(),
                'critical' => (clone $base)->whereIn('level', ['critical', 'alert', 'emergency'])->where('status', '!=', SupportOccurrence::STATUS_RESOLVED)->count(),
                'last_24h' => (clone $base)->where('last_seen_at', '>=', now()->subDay())->count(),
            ],
            'sensitiveActions' => $this->sensitiveActions($municipality),
        ];
    }

    public function syncRecentLogErrors(): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $path = storage_path('logs/laravel.log');

        if (! is_file($path) || ! is_readable($path)) {
            return;
        }

        $content = $this->tail($path, 220000);
        preg_match_all('/^\[(?<date>.+?)\]\s+\w+\.(?<level>ERROR|CRITICAL|ALERT|EMERGENCY|WARNING):\s+(?<message>.+)$/m', $content, $matches, PREG_SET_ORDER);

        foreach (collect($matches)->take(-20) as $match) {
            try {
                $level = mb_strtolower($match['level']);
                $message = Str::limit($match['message'], 4000);

                $this->upsertOccurrence([
                    'municipality_id' => null,
                    'user_id' => null,
                    'fingerprint' => $this->fingerprint('log', $level, $message),
                    'source' => 'log',
                    'level' => $level,
                    'title' => 'Registro '.$match['level'].' no log',
                    'message' => $message,
                    'route_name' => null,
                    'method' => null,
                    'url' => null,
                    'ip_address' => null,
                    'user_agent' => null,
                    'context' => ['logged_at' => $match['date']],
                ]);
            } catch (Throwable) {
                //
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function upsertOccurrence(array $data): void
    {
        try {
            $occurrence = SupportOccurrence::query()->firstOrNew([
                'fingerprint' => $data['fingerprint'],
            ]);

            $occurrence->forceFill($data + [
                'first_seen_at' => $occurrence->exists ? $occurrence->first_seen_at : now(),
            ]);

            $occurrence->forceFill([
                'municipality_id' => $occurrence->municipality_id ?? $data['municipality_id'],
                'status' => SupportOccurrence::STATUS_OPEN,
                'resolved_by' => null,
                'resolved_at' => null,
                'occurrence_count' => $occurrence->exists ? $occurrence->occurrence_count + 1 : 1,
                'last_seen_at' => now(),
            ])->save();
        } catch (QueryException) {
            //
        }
    }

    /** @return array<int, AuditLog> */
    private function sensitiveActions(Municipality $municipality): array
    {
        return AuditLog::query()
            ->where('municipality_id', $municipality->id)
            ->whereIn('action', [
                'role_updated',
                'municipal_modules_updated',
                'municipal_rules_activated',
                'municipal_rules_updated',
                'document_uploaded',
                'amendments_spreadsheet_imported',
                'audesp_homologation_created',
                'accountability_updated',
                'external_sync_finished',
            ])
            ->latest()
            ->limit(10)
            ->get()
            ->all();
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('support_occurrences');
        } catch (Throwable) {
            return false;
        }
    }

    private function fingerprint(string ...$parts): string
    {
        return hash('sha256', collect($parts)->map(fn (string $part) => Str::limit($part, 700, ''))->implode('|'));
    }

    private function tail(string $path, int $bytes): string
    {
        $size = filesize($path) ?: 0;
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        fseek($handle, max(0, $size - $bytes));
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }
}
