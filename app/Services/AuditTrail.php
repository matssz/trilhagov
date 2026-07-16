<?php

namespace App\Services;

use App\Models\AmendmentDocument;
use App\Models\AuditLog;
use App\Models\DocumentType;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
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

    public function recordRoleUpdate(
        Request $request,
        Municipality $municipality,
        User $member,
        string $oldRole,
        string $newRole,
    ): AuditLog {
        return $member->auditLogs()->create([
            'municipality_id' => $municipality->id,
            'user_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'action' => 'role_updated',
            'old_values' => ['role' => $oldRole],
            'new_values' => ['role' => $newRole],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);
    }

    public function recordDocumentUpload(
        Request $request,
        ParliamentaryAmendment $amendment,
        AmendmentDocument $document,
    ): AuditLog {
        return $amendment->auditLogs()->create([
            'municipality_id' => $amendment->municipality_id,
            'user_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'action' => 'document_uploaded',
            'old_values' => null,
            'new_values' => [
                'document_type' => $document->documentType->name,
                'document_name' => $document->original_name,
                'document_version' => $document->version,
                'document_size' => $document->formattedSize(),
                'execution_stage' => $document->executionStage?->title,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);
    }

    public function recordDocumentTypeCreation(
        Request $request,
        Municipality $municipality,
        DocumentType $documentType,
    ): AuditLog {
        return $documentType->auditLogs()->create([
            'municipality_id' => $municipality->id,
            'user_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'action' => 'document_type_created',
            'old_values' => null,
            'new_values' => $documentType->only([
                'name',
                'description',
                'is_required',
                'is_active',
                'sort_order',
            ]),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);
    }

    /** @param array<string, mixed> $oldValues @param array<string, mixed> $newValues */
    public function recordDocumentTypeUpdate(
        Request $request,
        Municipality $municipality,
        DocumentType $documentType,
        array $oldValues,
        array $newValues,
    ): AuditLog {
        return $documentType->auditLogs()->create([
            'municipality_id' => $municipality->id,
            'user_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'action' => 'document_type_updated',
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);
    }

    /** @param array<string, mixed> $newValues @param array<string, mixed>|null $oldValues */
    public function recordOperation(
        Request $request,
        ParliamentaryAmendment $amendment,
        string $action,
        array $newValues,
        ?array $oldValues = null,
    ): AuditLog {
        return $this->record($request, $amendment, $action, $oldValues, $newValues);
    }

    /** @param array<string, mixed> $newValues @param array<string, mixed>|null $oldValues */
    public function recordMunicipalityOperation(
        Request $request,
        Municipality $municipality,
        string $action,
        array $newValues,
        ?array $oldValues = null,
    ): AuditLog {
        return $municipality->auditLogs()->create([
            'municipality_id' => $municipality->id,
            'user_id' => $request->user()?->id,
            'actor_name' => $request->user()?->name ?? 'Portal público',
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);
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
