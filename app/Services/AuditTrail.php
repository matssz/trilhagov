<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ParliamentaryAmendment;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AuditTrail
{
    private const IGNORED_FIELDS = [
        'id',
        'municipality_id',
        'created_by',
        'created_at',
        'updated_at',
    ];

    public function recordCreation(Request $request, ParliamentaryAmendment $amendment): AuditLog
    {
        return $this->record(
            $request,
            $amendment,
            'created',
            null,
            Arr::except($amendment->getAttributes(), self::IGNORED_FIELDS),
        );
    }

    /** @param array<string, mixed> $oldValues @param array<string, mixed> $newValues */
    public function recordUpdate(
        Request $request,
        ParliamentaryAmendment $amendment,
        array $oldValues,
        array $newValues,
    ): ?AuditLog {
        $newValues = Arr::except($newValues, self::IGNORED_FIELDS);

        if ($newValues === []) {
            return null;
        }

        return $this->record(
            $request,
            $amendment,
            'updated',
            Arr::only($oldValues, array_keys($newValues)),
            $newValues,
        );
    }

    /** @param array<string, mixed>|null $oldValues @param array<string, mixed> $newValues */
    private function record(
        Request $request,
        ParliamentaryAmendment $amendment,
        string $action,
        ?array $oldValues,
        array $newValues,
    ): AuditLog {
        return $amendment->auditLogs()->create([
            'municipality_id' => $amendment->municipality_id,
            'user_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
