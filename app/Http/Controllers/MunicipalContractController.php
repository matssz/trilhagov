<?php

namespace App\Http\Controllers;

use App\Models\ContractAddendum;
use App\Models\ContractMeasurement;
use App\Models\MunicipalContract;
use App\Models\Municipality;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use App\Services\MunicipalContractFramework;
use App\Services\MunicipalWorkItemService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class MunicipalContractController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalContractFramework $framework,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $year = (int) $request->query('year', now()->year);
        abort_unless($year >= 2021 && $year <= now()->year + 1, 422, 'Informe um exercício válido para as contratações municipais.');
        $all = $municipality->municipalContracts()
            ->whereHas('amendment', fn ($query) => $query->where('fiscal_year', $year))
            ->with(['amendment:id,reference,fiscal_year,object', 'measurements', 'addenda'])
            ->get();
        $query = $municipality->municipalContracts()
            ->whereHas('amendment', fn ($builder) => $builder->where('fiscal_year', $year))
            ->with(['amendment:id,reference,fiscal_year,object', 'manager:id,name', 'inspector:id,name', 'measurements', 'addenda'])
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->query('status')))
            ->when($request->filled('search'), function ($builder) use ($request): void {
                $search = trim((string) $request->query('search'));
                $builder->where(fn ($nested) => $nested
                    ->where('process_number', 'like', "%{$search}%")
                    ->orWhere('contract_number', 'like', "%{$search}%")
                    ->orWhere('supplier_name', 'like', "%{$search}%")
                    ->orWhere('object', 'like', "%{$search}%"));
            })
            ->latest('created_at');
        $contracts = $query->paginate(20)->withQueryString();
        $canEdit = $request->user()->canEditMunicipality($municipality->id);

        return view('municipal-contracts.index', [
            'municipality' => $municipality,
            'contracts' => $contracts,
            'year' => $year,
            'statuses' => MunicipalContract::statuses(),
            'objectTypes' => $framework->objectTypes(),
            'procurementMethods' => $framework->procurementMethods(),
            'amendments' => $canEdit ? $municipality->amendments()->where('government_sphere', 'municipal')->where('fiscal_year', $year)->orderBy('reference')->get(['id', 'reference', 'object', 'expected_amount']) : collect(),
            'canEdit' => $canEdit,
            'createToken' => $canEdit ? $formSubmission->issue($request, 'municipal-contract-create') : null,
            'metrics' => [
                'total' => $all->count(),
                'contracted_amount' => (float) $all->sum(fn (MunicipalContract $contract) => $contract->current_amount ?? 0),
                'executing' => $all->where('status', MunicipalContract::STATUS_EXECUTING)->count(),
                'suspended' => $all->where('status', MunicipalContract::STATUS_SUSPENDED)->count(),
                'pending_measurements' => $all->sum(fn (MunicipalContract $contract) => $contract->measurements->where('status', ContractMeasurement::STATUS_DRAFT)->count()),
                'risk' => $all->filter(fn (MunicipalContract $contract) => $framework->evaluate($contract)['blockers'] !== [] || $framework->evaluate($contract)['variance'] > 15)->count(),
            ],
        ]);
    }

    public function store(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalContractFramework $framework,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $validated = $this->validateContract($request, $municipality, $framework);
        if (! $formSubmission->consume($request, 'municipal-contract-create')) {
            return back()->with('warning', 'Este processo de contratação já foi cadastrado.');
        }
        if ($municipality->municipalContracts()->where('process_number', $validated['process_number'])->exists()) {
            return back()->with('warning', 'Este processo de contratação já está cadastrado no Município.');
        }
        $contract = $municipality->municipalContracts()->create([
            ...$this->contractAttributes($request, $validated, $framework),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
            'reference' => (string) Str::uuid(),
            'status' => MunicipalContract::STATUS_PLANNING,
        ]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_contract_created', [
            'contract' => $contract->code(), 'process_number' => $contract->process_number,
        ]);

        return redirect()->route('municipal-contracts.show', $contract)->with('status', 'Processo criado. Complete o planejamento antes de iniciar a seleção.');
    }

    public function show(
        Request $request,
        int $contract,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalContractFramework $framework,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $contract = $this->contract($municipality, $contract);
        $role = $request->user()->roleForMunicipality($municipality->id);
        $canEdit = in_array($role, ['manager', 'editor'], true) && ! in_array($contract->status, [MunicipalContract::STATUS_COMPLETED, MunicipalContract::STATUS_CANCELLED], true);
        $canReview = in_array($role, ['manager', 'auditor'], true) || $contract->contract_inspector_id === $request->user()->id;

        return view('municipal-contracts.show', [
            'municipality' => $municipality,
            'contract' => $contract,
            'diagnostic' => $framework->evaluate($contract),
            'objectTypes' => $framework->objectTypes(),
            'procurementMethods' => $framework->procurementMethods(),
            'executionRegimes' => $framework->executionRegimes(),
            'publicationTypes' => $framework->publicationTypes(),
            'planningChecklist' => $framework->planningChecklist(),
            'addendumTypes' => $framework->addendumTypes(),
            'members' => $municipality->users()->wherePivotIn('role', ['manager', 'editor', 'auditor'])->orderBy('name')->get(['users.id', 'users.name']),
            'documents' => $contract->amendment->documents->sortByDesc('created_at'),
            'canEdit' => $canEdit,
            'canReview' => $canReview,
            'updateToken' => $canEdit ? $formSubmission->issue($request, "municipal-contract-update-{$contract->id}") : null,
            'transitionToken' => $canEdit ? $formSubmission->issue($request, "municipal-contract-transition-{$contract->id}") : null,
            'measurementToken' => $canEdit ? $formSubmission->issue($request, "contract-measurement-create-{$contract->id}") : null,
            'measurementDecisionTokens' => $canReview ? $contract->measurements->where('status', ContractMeasurement::STATUS_DRAFT)->mapWithKeys(fn ($item) => [$item->id => $formSubmission->issue($request, "contract-measurement-decision-{$item->id}")]) : collect(),
            'addendumToken' => $canEdit ? $formSubmission->issue($request, "contract-addendum-create-{$contract->id}") : null,
            'addendumDecisionTokens' => in_array($role, ['manager', 'auditor'], true) ? $contract->addenda->where('status', ContractAddendum::STATUS_DRAFT)->mapWithKeys(fn ($item) => [$item->id => $formSubmission->issue($request, "contract-addendum-decision-{$item->id}")]) : collect(),
            'canDecideAddendum' => in_array($role, ['manager', 'auditor'], true),
        ]);
    }

    public function update(
        Request $request,
        int $contract,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalContractFramework $framework,
        AuditTrail $auditTrail,
        IntegrityAlertService $alerts,
        MunicipalWorkItemService $workItems,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $contract = $this->contract($municipality, $contract);
        abort_if(in_array($contract->status, [MunicipalContract::STATUS_COMPLETED, MunicipalContract::STATUS_CANCELLED], true), 409, 'Contrato encerrado não pode ser alterado.');
        $validated = $this->validateContract($request, $municipality, $framework, $contract);
        if (! $formSubmission->consume($request, "municipal-contract-update-{$contract->id}")) {
            return back()->with('warning', 'Esta atualização já foi processada.');
        }
        $old = $contract->only(['status', 'contract_number', 'original_amount', 'effective_end_at', 'contract_inspector_id']);
        $contract->update([...$this->contractAttributes($request, $validated, $framework), 'updated_by' => $request->user()->id]);
        if ($contract->original_amount !== null && $contract->addenda()->where('status', ContractAddendum::STATUS_APPROVED)->doesntExist()) {
            $contract->update(['current_amount' => $contract->original_amount]);
        } else {
            $framework->recalculateCurrentAmount($contract);
        }
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_contract_updated', [
            'contract' => $contract->code(), 'diagnostic_ready' => $framework->evaluate($contract)['ready'],
        ], $old);
        $this->syncControls($municipality, $alerts, $workItems);

        return back()->with('status', 'Contrato atualizado. O diagnóstico foi recalculado.');
    }

    public function transition(
        Request $request,
        int $contract,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalContractFramework $framework,
        AuditTrail $auditTrail,
        IntegrityAlertService $alerts,
        MunicipalWorkItemService $workItems,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $contract = $this->contract($municipality, $contract);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'action' => ['required', Rule::in(['selection', 'contracted', 'executing', 'suspend', 'resume', 'complete', 'cancel'])],
            'event_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:3000'],
        ]);
        if (! $formSubmission->consume($request, "municipal-contract-transition-{$contract->id}")) {
            return back()->with('warning', 'Esta mudança de etapa já foi processada.');
        }

        $transitions = [
            'selection' => [[MunicipalContract::STATUS_PLANNING], MunicipalContract::STATUS_SELECTION],
            'contracted' => [[MunicipalContract::STATUS_SELECTION], MunicipalContract::STATUS_CONTRACTED],
            'executing' => [[MunicipalContract::STATUS_CONTRACTED], MunicipalContract::STATUS_EXECUTING],
            'suspend' => [[MunicipalContract::STATUS_EXECUTING], MunicipalContract::STATUS_SUSPENDED],
            'resume' => [[MunicipalContract::STATUS_SUSPENDED], MunicipalContract::STATUS_EXECUTING],
            'complete' => [[MunicipalContract::STATUS_EXECUTING], MunicipalContract::STATUS_COMPLETED],
            'cancel' => [[MunicipalContract::STATUS_PLANNING, MunicipalContract::STATUS_SELECTION, MunicipalContract::STATUS_CONTRACTED], MunicipalContract::STATUS_CANCELLED],
        ];
        [$from, $to] = $transitions[$validated['action']];
        abort_unless(in_array($contract->status, $from, true), 409, 'Esta mudança não é permitida na etapa atual.');
        if (in_array($validated['action'], ['suspend', 'cancel'], true) && mb_strlen(trim((string) ($validated['reason'] ?? ''))) < 20) {
            return back()->withErrors(['reason' => 'Fundamente a decisão com pelo menos 20 caracteres.']);
        }
        if (in_array($validated['action'], ['suspend', 'resume'], true) && empty($validated['event_date'])) {
            return back()->withErrors(['event_date' => 'Informe a data do evento.']);
        }

        $originalStatus = $contract->status;
        $contract->forceFill(['status' => $to]);
        $diagnostic = $framework->evaluate($contract);
        $contract->forceFill(['status' => $originalStatus]);
        if (in_array($validated['action'], ['selection', 'contracted', 'executing', 'complete'], true) && $diagnostic['blockers'] !== []) {
            return back()->withErrors(['transition' => 'Resolva antes de avançar: '.implode(' ', array_slice($diagnostic['blockers'], 0, 3))]);
        }

        $attributes = ['status' => $to, 'updated_by' => $request->user()->id];
        if ($validated['action'] === 'suspend') {
            $attributes += ['suspended_at' => $validated['event_date'], 'suspension_reason' => trim($validated['reason'])];
        } elseif ($validated['action'] === 'resume') {
            $attributes += ['resumed_at' => $validated['event_date']];
        } elseif ($validated['action'] === 'complete') {
            $attributes += ['completed_at' => now()];
        } elseif ($validated['action'] === 'cancel') {
            $attributes += ['cancellation_reason' => trim($validated['reason'])];
        }
        $contract->update($attributes);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_contract_transitioned', [
            'contract' => $contract->code(), 'from' => $originalStatus, 'to' => $to, 'reason' => $validated['reason'] ?? null,
        ]);
        $this->syncControls($municipality, $alerts, $workItems);

        return back()->with('status', 'Etapa atualizada para '.$contract->statusLabel().'.');
    }

    public function storeMeasurement(
        Request $request,
        int $contract,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $contract = $this->contract($municipality, $contract);
        abort_unless(in_array($contract->status, [MunicipalContract::STATUS_EXECUTING, MunicipalContract::STATUS_SUSPENDED], true), 409, 'Medições somente podem ser registradas durante a execução.');
        $validated = $this->validateMeasurement($request, $contract);
        if (! $formSubmission->consume($request, "contract-measurement-create-{$contract->id}")) {
            return back()->with('warning', 'Esta medição já foi cadastrada.');
        }
        $measurement = DB::transaction(function () use ($request, $municipality, $contract, $validated): ContractMeasurement {
            $sequence = ((int) $contract->measurements()->lockForUpdate()->max('sequence')) + 1;

            return $contract->measurements()->create([
                ...$validated, 'municipality_id' => $municipality->id,
                'parliamentary_amendment_id' => $contract->parliamentary_amendment_id,
                'created_by' => $request->user()->id, 'sequence' => $sequence,
                'status' => ContractMeasurement::STATUS_DRAFT,
            ]);
        });
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'contract_measurement_created', [
            'contract' => $contract->code(), 'measurement' => $measurement->sequence, 'amount' => (float) $measurement->amount,
        ]);

        return back()->with('status', 'Medição registrada e encaminhada para ateste.');
    }

    public function decideMeasurement(
        Request $request,
        int $measurement,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalContractFramework $framework,
        AuditTrail $auditTrail,
        IntegrityAlertService $alerts,
        MunicipalWorkItemService $workItems,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $measurement = $this->measurement($municipality, $measurement);
        abort_unless($measurement->status === ContractMeasurement::STATUS_DRAFT, 409, 'Esta medição já foi decidida.');
        $contract = $measurement->contract;
        $role = $request->user()->roleForMunicipality($municipality->id);
        abort_unless(in_array($role, ['manager', 'auditor'], true) || $contract->contract_inspector_id === $request->user()->id, 403);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'review_notes' => ['required', 'string', 'min:20', 'max:3000'],
        ], ['review_notes.min' => 'Fundamente o ateste ou a rejeição com pelo menos 20 caracteres.']);
        if (! $formSubmission->consume($request, "contract-measurement-decision-{$measurement->id}")) {
            return back()->with('warning', 'Esta decisão já foi processada.');
        }

        if ($validated['action'] === 'approve') {
            if ($measurement->evidence_document_id === null) {
                return back()->withErrors(['measurement' => 'Vincule a planilha, o boletim ou a evidência da medição antes do ateste.']);
            }
            $approved = $contract->measurements()->where('status', ContractMeasurement::STATUS_APPROVED)->get();
            $previousPhysical = (float) ($approved->sortByDesc('sequence')->first()?->cumulative_physical_percentage ?? 0);
            if ((float) $measurement->cumulative_physical_percentage < $previousPhysical) {
                return back()->withErrors(['measurement' => 'O avanço físico acumulado não pode ser menor que a medição anterior.']);
            }
            if ((float) $approved->sum('amount') + (float) $measurement->amount > (float) $contract->current_amount + 0.01) {
                return back()->withErrors(['measurement' => 'O total das medições ultrapassaria o valor contratual atualizado.']);
            }
        }

        $measurement->forceFill([
            'status' => $validated['action'] === 'approve' ? ContractMeasurement::STATUS_APPROVED : ContractMeasurement::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'review_notes' => trim($validated['review_notes']),
            'reviewed_at' => now(),
        ]);
        $snapshot = $framework->measurementSnapshot($measurement);
        $measurement->forceFill(['snapshot' => $snapshot, 'snapshot_sha256' => $framework->hash($snapshot)])->save();
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'contract_measurement_decided', [
            'contract' => $contract->code(), 'measurement' => $measurement->sequence,
            'status' => $measurement->status, 'snapshot_sha256' => $measurement->snapshot_sha256,
        ]);
        $this->syncControls($municipality, $alerts, $workItems);

        return back()->with('status', $measurement->status === ContractMeasurement::STATUS_APPROVED ? 'Medição atestada e fechada.' : 'Medição rejeitada com fundamentação preservada.');
    }

    public function storeAddendum(
        Request $request,
        int $contract,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalContractFramework $framework,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $contract = $this->contract($municipality, $contract);
        abort_if(in_array($contract->status, [MunicipalContract::STATUS_PLANNING, MunicipalContract::STATUS_SELECTION, MunicipalContract::STATUS_COMPLETED, MunicipalContract::STATUS_CANCELLED], true), 409, 'A etapa atual não admite termo aditivo.');
        $validated = $this->validateAddendum($request, $contract, $framework);
        if (! $formSubmission->consume($request, "contract-addendum-create-{$contract->id}")) {
            return back()->with('warning', 'Este termo aditivo já foi cadastrado.');
        }
        $addendum = DB::transaction(function () use ($request, $municipality, $contract, $validated): ContractAddendum {
            $sequence = ((int) $contract->addenda()->lockForUpdate()->max('sequence')) + 1;

            return $contract->addenda()->create([
                ...$validated, 'municipality_id' => $municipality->id,
                'parliamentary_amendment_id' => $contract->parliamentary_amendment_id,
                'created_by' => $request->user()->id, 'sequence' => $sequence,
                'status' => ContractAddendum::STATUS_DRAFT,
            ]);
        });
        $diagnostic = $framework->evaluateAddendum($addendum);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'contract_addendum_created', [
            'contract' => $contract->code(), 'addendum' => $addendum->sequence,
            'projected_percentage' => $diagnostic['projected_percentage'],
        ]);

        return back()->with('status', 'Termo aditivo registrado para análise.');
    }

    public function decideAddendum(
        Request $request,
        int $addendum,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalContractFramework $framework,
        AuditTrail $auditTrail,
        IntegrityAlertService $alerts,
        MunicipalWorkItemService $workItems,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $addendum = $this->addendum($municipality, $addendum);
        abort_unless($addendum->status === ContractAddendum::STATUS_DRAFT, 409, 'Este termo aditivo já foi decidido.');
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'review_notes' => ['required', 'string', 'min:20', 'max:3000'],
        ], ['review_notes.min' => 'Fundamente a decisão com pelo menos 20 caracteres.']);
        if (! $formSubmission->consume($request, "contract-addendum-decision-{$addendum->id}")) {
            return back()->with('warning', 'Esta decisão já foi processada.');
        }
        $diagnostic = $framework->evaluateAddendum($addendum);
        if ($validated['action'] === 'approve' && ! $diagnostic['ready']) {
            return back()->withErrors(['addendum' => implode(' ', $diagnostic['blockers'])]);
        }

        $addendum->forceFill([
            'status' => $validated['action'] === 'approve' ? ContractAddendum::STATUS_APPROVED : ContractAddendum::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'review_notes' => trim($validated['review_notes']),
            'reviewed_at' => now(),
        ]);
        $snapshot = $framework->addendumSnapshot($addendum);
        $addendum->forceFill(['snapshot' => $snapshot, 'snapshot_sha256' => $framework->hash($snapshot)])->save();
        if ($addendum->status === ContractAddendum::STATUS_APPROVED) {
            $framework->recalculateCurrentAmount($addendum->contract);
            if ($addendum->days_change !== 0 && $addendum->contract->effective_end_at) {
                $addendum->contract->update(['effective_end_at' => $addendum->contract->effective_end_at->copy()->addDays($addendum->days_change)]);
            }
        }
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'contract_addendum_decided', [
            'contract' => $addendum->contract->code(), 'addendum' => $addendum->sequence,
            'status' => $addendum->status, 'snapshot_sha256' => $addendum->snapshot_sha256,
        ]);
        $this->syncControls($municipality, $alerts, $workItems);

        return back()->with('status', $addendum->status === ContractAddendum::STATUS_APPROVED ? 'Termo aditivo formalizado e valor contratual recalculado.' : 'Termo aditivo rejeitado.');
    }

    public function pdf(Request $request, int $contract, CurrentMunicipality $currentMunicipality, AuditTrail $auditTrail, MunicipalContractFramework $framework): Response
    {
        $municipality = $currentMunicipality->get($request);
        $contract = $this->contract($municipality, $contract);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_contract_dossier_downloaded', ['contract' => $contract->code()]);

        return Pdf::loadView('municipal-contracts.pdf', [
            'contract' => $contract, 'diagnostic' => $framework->evaluate($contract),
        ])->setPaper('a4')->download(Str::lower($contract->code()).'-dossie.pdf');
    }

    /** @return array<string, mixed> */
    private function validateContract(Request $request, Municipality $municipality, MunicipalContractFramework $framework, ?MunicipalContract $contract = null): array
    {
        $memberRule = Rule::exists('municipality_user', 'user_id')->where(fn ($query) => $query->where('municipality_id', $municipality->id));
        $processRules = ['required', 'string', 'max:100'];
        if ($contract) {
            $processRules[] = Rule::unique('municipal_contracts')->where('municipality_id', $municipality->id)->ignore($contract->id);
        }

        return $request->validate([
            '_submission_token' => ['required', 'string'],
            'parliamentary_amendment_id' => ['required', Rule::exists('parliamentary_amendments', 'id')->where(fn ($query) => $query->where('municipality_id', $municipality->id)->where('government_sphere', 'municipal'))],
            'process_number' => $processRules,
            'contract_number' => ['nullable', 'string', 'max:100'],
            'object_type' => ['required', Rule::in(array_keys($framework->objectTypes()))],
            'procurement_method' => ['required', Rule::in(array_keys($framework->procurementMethods()))],
            'execution_regime' => ['nullable', Rule::in(array_keys($framework->executionRegimes()))],
            'judgment_criterion' => ['nullable', 'string', 'max:80'],
            'object' => ['required', 'string', 'min:20', 'max:5000'],
            'site_location' => ['nullable', 'string', 'max:255'],
            'estimated_amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'supplier_name' => ['nullable', 'string', 'max:180'],
            'supplier_document' => ['nullable', 'string', 'max:20'],
            'original_amount' => ['nullable', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'signed_at' => ['nullable', 'date'],
            'effective_start_at' => ['nullable', 'date'],
            'effective_end_at' => ['nullable', 'date', 'after_or_equal:effective_start_at'],
            'work_order_at' => ['nullable', 'date'],
            'contract_manager_id' => ['nullable', $memberRule],
            'contract_inspector_id' => ['nullable', $memberRule],
            'measurement_criteria' => ['nullable', 'string', 'max:3000'],
            'payment_terms' => ['nullable', 'string', 'max:3000'],
            'warranty_months' => ['nullable', 'integer', 'between:0,600'],
            'technical_responsible' => ['nullable', 'string', 'max:180'],
            'technical_registration' => ['nullable', 'string', 'max:100'],
            'publication_type' => ['nullable', Rule::in(array_keys($framework->publicationTypes()))],
            'publication_reference' => ['nullable', 'string', 'max:500'],
            'published_at' => ['nullable', 'date'],
            'provisional_acceptance_reference' => ['nullable', 'string', 'max:255'],
            'provisional_accepted_at' => ['nullable', 'date'],
            'definitive_acceptance_reference' => ['nullable', 'string', 'max:255'],
            'definitive_accepted_at' => ['nullable', 'date', 'after_or_equal:provisional_accepted_at'],
            'planning_checklist' => ['nullable', 'array'],
        ], [
            'process_number.unique' => 'Este número de processo já está cadastrado no Município.',
            'parliamentary_amendment_id.exists' => 'Selecione uma emenda municipal deste Município.',
            'object.min' => 'Descreva o objeto com pelo menos 20 caracteres.',
            'effective_end_at.after_or_equal' => 'O fim da vigência não pode ser anterior ao início.',
        ]);
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    private function contractAttributes(Request $request, array $validated, MunicipalContractFramework $framework): array
    {
        return [
            ...collect($validated)->except(['_submission_token', 'planning_checklist'])->all(),
            'is_renovation' => $validated['object_type'] === 'renovation',
            'planning_checklist' => collect($framework->planningChecklist())->mapWithKeys(fn ($label, $key) => [$key => $request->boolean("planning_checklist.{$key}")])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function validateMeasurement(Request $request, MunicipalContract $contract): array
    {
        return $request->validate([
            '_submission_token' => ['required', 'string'],
            'period_start_at' => ['required', 'date'],
            'period_end_at' => ['required', 'date', 'after_or_equal:period_start_at'],
            'measured_at' => ['required', 'date', 'after_or_equal:period_end_at'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'cumulative_physical_percentage' => ['required', 'numeric', 'between:0.01,100'],
            'evidence_document_id' => ['nullable', Rule::exists('amendment_documents', 'id')->where(fn ($query) => $query->where('municipality_id', $contract->municipality_id)->where('parliamentary_amendment_id', $contract->parliamentary_amendment_id))],
            'notes' => ['required', 'string', 'min:20', 'max:3000'],
        ], [
            'period_end_at.after_or_equal' => 'O fim do período não pode ser anterior ao início.',
            'measured_at.after_or_equal' => 'A medição deve ocorrer no fim do período ou depois dele.',
            'notes.min' => 'Descreva os serviços medidos com pelo menos 20 caracteres.',
            'evidence_document_id.exists' => 'A evidência selecionada não pertence à emenda deste contrato.',
        ]);
    }

    /** @return array<string, mixed> */
    private function validateAddendum(Request $request, MunicipalContract $contract, MunicipalContractFramework $framework): array
    {
        return $request->validate([
            '_submission_token' => ['required', 'string'],
            'type' => ['required', Rule::in(array_keys($framework->addendumTypes()))],
            'value_change' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'days_change' => ['required', 'integer', 'between:-3650,3650'],
            'justification' => ['required', 'string', 'min:30', 'max:5000'],
            'technical_basis' => ['required', 'string', 'min:30', 'max:5000'],
            'effective_at' => ['required', 'date'],
            'signed_at' => ['nullable', 'date'],
            'publication_reference' => ['nullable', 'string', 'max:500'],
            'published_at' => ['nullable', 'date'],
            'advance_effects_justification' => ['nullable', 'string', 'min:30', 'max:3000'],
            'evidence_document_id' => ['nullable', Rule::exists('amendment_documents', 'id')->where(fn ($query) => $query->where('municipality_id', $contract->municipality_id)->where('parliamentary_amendment_id', $contract->parliamentary_amendment_id))],
        ], [
            'justification.min' => 'Fundamente a necessidade do aditivo com pelo menos 30 caracteres.',
            'technical_basis.min' => 'Registre a análise técnica com pelo menos 30 caracteres.',
            'advance_effects_justification.min' => 'A antecipação excepcional exige justificativa com pelo menos 30 caracteres.',
        ]);
    }

    private function contract(Municipality $municipality, int $id): MunicipalContract
    {
        return $municipality->municipalContracts()->with([
            'amendment.documents.documentType', 'manager:id,name', 'inspector:id,name',
            'measurements.evidenceDocument.documentType', 'measurements.reviewer:id,name',
            'addenda.evidenceDocument.documentType', 'addenda.reviewer:id,name',
        ])->findOrFail($id);
    }

    private function measurement(Municipality $municipality, int $id): ContractMeasurement
    {
        return $municipality->contractMeasurements()->with(['contract.measurements', 'contract.addenda'])->findOrFail($id);
    }

    private function addendum(Municipality $municipality, int $id): ContractAddendum
    {
        return $municipality->contractAddenda()->with(['contract.addenda'])->findOrFail($id);
    }

    private function syncControls(Municipality $municipality, IntegrityAlertService $alerts, MunicipalWorkItemService $workItems): void
    {
        $alerts->sync($municipality);
        $workItems->synchronize($municipality);
    }
}
