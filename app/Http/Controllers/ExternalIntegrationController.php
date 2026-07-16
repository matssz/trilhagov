<?php

namespace App\Http\Controllers;

use App\Models\ExternalAmendmentCandidate;
use App\Models\ExternalDataSync;
use App\Models\ParliamentaryAmendment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\ExternalAmendmentReconciliationService;
use App\Services\FormSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExternalIntegrationController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $selectedStatus = (string) $request->query('status');
        $canEdit = $request->user()->canEditMunicipality($municipality->id);
        $query = $municipality->externalAmendmentCandidates()
            ->with(['amendment', 'reviewer', 'sync'])
            ->when(array_key_exists($selectedStatus, ExternalAmendmentCandidate::statuses()), fn ($query) => $query->where('match_status', $selectedStatus))
            ->orderByRaw("CASE match_status WHEN 'divergent' THEN 0 WHEN 'new' THEN 1 WHEN 'linked' THEN 2 WHEN 'matched' THEN 3 WHEN 'imported' THEN 4 ELSE 5 END")
            ->latest('last_seen_at');
        $candidates = $query->paginate(15)->withQueryString();

        return view('integrations.index', [
            'municipality' => $municipality,
            'latestSync' => $municipality->externalDataSyncs()->latest()->first(),
            'candidates' => $candidates,
            'counts' => $municipality->externalAmendmentCandidates()
                ->selectRaw('match_status, count(*) as total')
                ->groupBy('match_status')
                ->pluck('total', 'match_status'),
            'statuses' => ExternalAmendmentCandidate::statuses(),
            'selectedStatus' => $selectedStatus,
            'canEdit' => $canEdit,
            'amendments' => $canEdit
                ? $municipality->amendments()->orderByDesc('fiscal_year')->orderBy('reference')->get(['id', 'reference', 'fiscal_year', 'transferegov_code'])
                : collect(),
            'responsibleUsers' => $canEdit
                ? $municipality->users()->wherePivotIn('role', ['manager', 'editor'])->orderBy('name')->get()
                : collect(),
            'departments' => $municipality->amendments()->whereNotNull('responsible_department')->distinct()->orderBy('responsible_department')->pluck('responsible_department'),
            'syncToken' => $canEdit ? $formSubmission->issue($request, "external-sync-{$municipality->id}") : null,
            'actionTokens' => $canEdit ? $candidates->getCollection()->mapWithKeys(fn ($candidate) => [
                $candidate->id => [
                    'link' => $formSubmission->issue($request, "external-link-{$candidate->id}"),
                    'apply' => $formSubmission->issue($request, "external-apply-{$candidate->id}"),
                    'import' => $formSubmission->issue($request, "external-import-{$candidate->id}"),
                    'ignore' => $formSubmission->issue($request, "external-ignore-{$candidate->id}"),
                ],
            ]) : collect(),
        ]);
    }

    public function sync(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        ExternalAmendmentReconciliationService $reconciliationService,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $request->validate(['_submission_token' => ['required', 'string']]);

        if (! $formSubmission->consume($request, "external-sync-{$municipality->id}")) {
            return back()->with('warning', 'Esta sincronização já foi solicitada.');
        }

        $sync = $reconciliationService->sync($municipality, $request->user());
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'external_sync_finished', [
            'source' => 'Transferegov - Transferências Especiais',
            'sync_status' => $sync->status,
            'records' => $sync->items_fetched,
            'divergences' => $sync->divergences_found,
        ]);

        if ($sync->status === ExternalDataSync::STATUS_FAILED) {
            return back()->with('warning', 'Não foi possível consultar a fonte oficial agora. A falha foi registrada para nova tentativa.');
        }

        if (! ($sync->metadata['beneficiary_found'] ?? false)) {
            return back()->with('warning', 'O CNPJ do município não foi localizado na base de transferências especiais.');
        }

        return back()->with('status', "Sincronização concluída: {$sync->items_fetched} plano(s) consultado(s) e {$sync->divergences_found} divergência(s) encontrada(s).");
    }

    public function link(
        Request $request,
        int $candidate,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        ExternalAmendmentReconciliationService $reconciliationService,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $candidateModel = $municipality->externalAmendmentCandidates()->findOrFail($candidate);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'parliamentary_amendment_id' => ['required', Rule::exists('parliamentary_amendments', 'id')->where('municipality_id', $municipality->id)],
        ]);

        if (! $formSubmission->consume($request, "external-link-{$candidateModel->id}")) {
            return back()->with('warning', 'Este vínculo já foi processado.');
        }

        $amendment = $municipality->amendments()->findOrFail($validated['parliamentary_amendment_id']);
        $candidateModel->update([
            'parliamentary_amendment_id' => $amendment->id,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        $candidateModel = $reconciliationService->refreshMatch($candidateModel->fresh('amendment'));
        $auditTrail->recordOperation($request, $amendment, 'external_candidate_linked', [
            'external_source' => 'Transferegov',
            'external_code' => $candidateModel->external_code,
            'external_match_status' => $candidateModel->statusLabel(),
        ]);

        return back()->with('status', 'Plano oficial vinculado. As diferenças foram recalculadas sem alterar a emenda.');
    }

    public function apply(
        Request $request,
        int $candidate,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        ExternalAmendmentReconciliationService $reconciliationService,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $candidateModel = $municipality->externalAmendmentCandidates()->with('amendment')->findOrFail($candidate);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', Rule::in(['fiscal_year', 'author_name', 'object', 'expected_amount', 'transferegov_code'])],
        ]);

        if (! $formSubmission->consume($request, "external-apply-{$candidateModel->id}")) {
            return back()->with('warning', 'Esta aplicação já foi processada.');
        }

        $amendment = $candidateModel->amendment ?? abort(422, 'Vincule uma emenda antes de aplicar dados oficiais.');
        $available = $candidateModel->differences ?? [];
        $updates = [];
        foreach ($validated['fields'] as $field) {
            if (isset($available[$field]) && $available[$field]['external'] !== null) {
                $updates[$field] = $available[$field]['external'];
            }
        }

        if ($updates === []) {
            throw ValidationException::withMessages(['fields' => 'Os campos escolhidos não possuem divergências aplicáveis.']);
        }
        if (isset($updates['expected_amount']) && (float) $amendment->received_amount > (float) $updates['expected_amount']) {
            throw ValidationException::withMessages(['fields' => 'O valor oficial é menor que o valor já recebido. Revise a conciliação antes de aplicá-lo.']);
        }

        $oldValues = $amendment->only(array_keys($updates));
        $amendment->update($updates);
        $candidateModel->update(['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        $auditTrail->recordOperation($request, $amendment, 'external_fields_applied', [
            ...$updates,
            'external_source' => 'Transferegov',
            'external_code' => $candidateModel->external_code,
        ], $oldValues);
        $reconciliationService->refreshMatch($candidateModel->fresh('amendment'));

        return back()->with('status', 'Campos oficiais selecionados foram aplicados e registrados na auditoria.');
    }

    public function import(
        Request $request,
        int $candidate,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $candidateModel = $municipality->externalAmendmentCandidates()->findOrFail($candidate);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'reference' => ['required', 'string', 'max:100', Rule::unique('parliamentary_amendments', 'reference')->where(fn ($query) => $query
                ->where('municipality_id', $municipality->id)
                ->where('government_sphere', 'federal')
                ->where('fiscal_year', $candidateModel->fiscal_year))],
            'author_party' => ['nullable', 'string', 'max:20'],
            'object' => ['required', 'string', 'max:5000'],
            'responsible_department' => ['required', 'string', 'max:255'],
            'responsible_user_id' => ['nullable', Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query
                ->where('municipality_id', $municipality->id)
                ->whereIn('role', ['manager', 'editor']))],
            'indicated_at' => ['required', 'date', 'before_or_equal:today'],
            'communication_deadline' => ['required', 'date', 'after_or_equal:indicated_at'],
            'execution_deadline' => ['required', 'date', 'after_or_equal:communication_deadline'],
            'accountability_deadline' => ['required', 'date', 'after_or_equal:execution_deadline'],
        ]);

        if (! $formSubmission->consume($request, "external-import-{$candidateModel->id}")) {
            return back()->with('warning', 'Esta importação já foi processada.');
        }
        if ($candidateModel->parliamentary_amendment_id !== null) {
            return back()->with('warning', 'Este plano oficial já está vinculado a uma emenda.');
        }

        $amendment = DB::transaction(function () use ($request, $municipality, $candidateModel, $validated, $auditTrail): ParliamentaryAmendment {
            $amendment = $municipality->amendments()->create([
                'created_by' => $request->user()->id,
                'reference' => $validated['reference'],
                'fiscal_year' => $candidateModel->fiscal_year ?? now()->year,
                'government_sphere' => 'federal',
                'authorship_type' => 'individual',
                'transfer_type' => 'special',
                'author_name' => $candidateModel->author_name ?: 'Não informado pela fonte oficial',
                'author_party' => $validated['author_party'] ?? null,
                'object' => $validated['object'],
                'responsible_department' => $validated['responsible_department'],
                'responsible_user_id' => $validated['responsible_user_id'] ?? null,
                'transferegov_code' => $candidateModel->external_code,
                'expected_amount' => $candidateModel->expected_amount ?? 0,
                'status' => ParliamentaryAmendment::STATUS_IDENTIFIED,
                'indicated_at' => $validated['indicated_at'],
                'communication_deadline' => $validated['communication_deadline'],
                'execution_deadline' => $validated['execution_deadline'],
                'accountability_deadline' => $validated['accountability_deadline'],
                'notes' => 'Registro importado da API pública do Transferegov e sujeito à conferência municipal.',
            ]);
            $candidateModel->update([
                'parliamentary_amendment_id' => $amendment->id,
                'match_status' => ExternalAmendmentCandidate::STATUS_IMPORTED,
                'differences' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            $auditTrail->recordOperation($request, $amendment, 'external_candidate_imported', [
                'external_source' => 'Transferegov',
                'external_code' => $candidateModel->external_code,
                'external_id' => $candidateModel->external_id,
            ]);

            return $amendment;
        });

        return redirect()->route('emendas.show', $amendment)->with('status', 'Emenda importada como identificada. Complete e confira os dados municipais.');
    }

    public function ignore(
        Request $request,
        int $candidate,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $candidateModel = $municipality->externalAmendmentCandidates()->findOrFail($candidate);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'review_notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        if (! $formSubmission->consume($request, "external-ignore-{$candidateModel->id}")) {
            return back()->with('warning', 'Esta revisão já foi processada.');
        }

        $candidateModel->update([
            'match_status' => ExternalAmendmentCandidate::STATUS_IGNORED,
            'review_notes' => $validated['review_notes'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'external_candidate_ignored', [
            'external_code' => $candidateModel->external_code,
            'review_notes' => $validated['review_notes'],
        ]);

        return back()->with('status', 'Candidato ignorado com justificativa registrada.');
    }
}
