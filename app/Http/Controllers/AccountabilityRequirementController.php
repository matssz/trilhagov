<?php

namespace App\Http\Controllers;

use App\Models\AccountabilityRequirement;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountabilityRequirementController extends Controller
{
    public function store(Request $request, int $emenda, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail, IntegrityAlertService $integrityAlertService): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $process = $amendment->accountabilityProcess()->firstOrFail();
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::in(array_keys(AccountabilityRequirement::categories()))],
            'is_required' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,65000'],
        ]);

        if (! $formSubmission->consume($request, "accountability-requirement-create-{$process->id}")) {
            return back()->with('warning', 'Este item do checklist já foi processado.');
        }

        DB::transaction(function () use ($request, $validated, $municipality, $amendment, $process, $auditTrail): void {
            $requirement = $process->requirements()->create([
                ...$validated,
                'municipality_id' => $municipality->id,
                'parliamentary_amendment_id' => $amendment->id,
                'created_by' => $request->user()->id,
                'status' => AccountabilityRequirement::STATUS_PENDING,
            ]);
            $auditTrail->recordOperation($request, $amendment, 'accountability_requirement_created', [
                'requirement' => $requirement->title,
                'requirement_category' => $requirement->categoryLabel(),
                'is_required' => $requirement->is_required,
            ]);
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Item adicionado ao checklist.');
    }

    public function update(Request $request, int $emenda, int $requisito, CurrentMunicipality $currentMunicipality, FormSubmission $formSubmission, AuditTrail $auditTrail, IntegrityAlertService $integrityAlertService): RedirectResponse
    {
        $municipality = $currentMunicipality->get($request);
        $amendment = $municipality->amendments()->findOrFail($emenda);
        $process = $amendment->accountabilityProcess()->firstOrFail();
        $requirement = $process->requirements()->findOrFail($requisito);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'status' => ['required', Rule::in(array_keys(AccountabilityRequirement::statuses()))],
            'amendment_document_id' => ['nullable', 'integer', Rule::exists('amendment_documents', 'id')->where(fn ($query) => $query
                ->where('municipality_id', $municipality->id)
                ->where('parliamentary_amendment_id', $amendment->id))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'amendment_document_id.exists' => 'O documento selecionado não pertence a esta emenda.',
        ]);

        if (! $formSubmission->consume($request, "accountability-requirement-update-{$requirement->id}")) {
            return back()->with('warning', 'Esta atualização do checklist já foi processada.');
        }

        if ($validated['status'] === AccountabilityRequirement::STATUS_NOT_APPLICABLE && blank($validated['notes'])) {
            throw ValidationException::withMessages([
                'notes' => 'Justifique por que este item não se aplica.',
            ]);
        }

        DB::transaction(function () use ($request, $validated, $amendment, $requirement, $auditTrail): void {
            $oldValues = $requirement->only(['status', 'amendment_document_id', 'notes']);
            $oldDocumentName = $requirement->document?->original_name;
            $resolved = in_array($validated['status'], [
                AccountabilityRequirement::STATUS_COMPLETED,
                AccountabilityRequirement::STATUS_NOT_APPLICABLE,
            ], true);
            $requirement->update([
                ...$validated,
                'completed_by' => $resolved ? $request->user()->id : null,
                'completed_at' => $resolved ? now() : null,
            ]);
            $auditTrail->recordOperation($request, $amendment, 'accountability_requirement_updated', [
                'requirement' => $requirement->title,
                'requirement_status' => $requirement->statusLabel(),
                'accountability_document' => $requirement->document?->original_name,
                'notes' => $requirement->notes,
            ], [
                'requirement' => $requirement->title,
                'requirement_status' => AccountabilityRequirement::statuses()[$oldValues['status']] ?? $oldValues['status'],
                'accountability_document' => $oldDocumentName,
                'notes' => $oldValues['notes'],
            ]);
        });

        $integrityAlertService->sync($municipality->fresh());

        return back()->with('status', 'Checklist atualizado.');
    }
}
