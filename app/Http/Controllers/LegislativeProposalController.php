<?php

namespace App\Http\Controllers;

use App\Models\LegislativeProposal;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\LegislativeNotificationService;
use App\Services\LegislativeProposalService;
use App\Services\MunicipalTransparencyTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LegislativeProposalController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        LegislativeProposalService $service,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $role = $request->user()->roleForMunicipality($municipality->id);
        $year = ctype_digit((string) $request->query('year')) ? (int) $request->query('year') : $service->defaultYear($municipality);
        $status = (string) $request->query('status');
        $search = trim((string) $request->query('search'));
        $membership = $request->user()->municipalities()->whereKey($municipality->id)->firstOrFail()->pivot;
        $query = $municipality->legislativeProposals()
            ->with(['submitter:id,name', 'amendment:id,reference,status'])
            ->where('fiscal_year', $year)
            ->when($role === User::ROLE_COUNCILOR, fn ($query) => $query->where('submitted_by', $request->user()->id))
            ->when(array_key_exists($status, LegislativeProposal::statuses()), fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('reference', 'like', "%{$search}%")
                    ->orWhere('author_name', 'like', "%{$search}%")
                    ->orWhere('object', 'like', "%{$search}%")
                    ->orWhere('beneficiary_name', 'like', "%{$search}%");
            }));
        $summaryQuery = clone $query;
        $profile = $service->profile($municipality, $year);
        $quota = $role === User::ROLE_COUNCILOR && $profile
            ? $service->quota($municipality, $profile, (string) ($membership->legislative_name ?: $request->user()->name))
            : null;

        return view('legislative.index', [
            'municipality' => $municipality,
            'role' => $role,
            'membership' => $membership,
            'year' => $year,
            'selectedStatus' => $status,
            'search' => $search,
            'statuses' => LegislativeProposal::statuses(),
            'activeYears' => $service->activeYears($municipality),
            'profile' => $profile,
            'quota' => $quota,
            'proposals' => $query->latest('id')->paginate(12)->withQueryString(),
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'amount' => (float) (clone $summaryQuery)->sum('estimated_amount'),
                'pending' => (clone $summaryQuery)->whereIn('status', [LegislativeProposal::STATUS_SUBMITTED, LegislativeProposal::STATUS_APPROVED])->count(),
                'sent' => (clone $summaryQuery)->whereIn('status', [LegislativeProposal::STATUS_SENT, LegislativeProposal::STATUS_RECEIVED, LegislativeProposal::STATUS_RESERVED])->count(),
            ],
        ]);
    }

    public function create(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
    ): View|RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        abort_unless($request->user()->roleForMunicipality($municipality->id) === User::ROLE_COUNCILOR, 403);
        $year = ctype_digit((string) $request->query('year')) ? (int) $request->query('year') : $service->defaultYear($municipality);

        if (! $service->profile($municipality, $year)) {
            return redirect()
                ->route('legislative.index', ['year' => $year])
                ->with('warning', 'O cadastro está indisponível porque não existe uma configuração normativa vigente para este exercício.');
        }

        return view('legislative.create', [
            ...$this->formOptions($municipality, $request, $service, $year),
            'submissionToken' => $formSubmission->issue($request, 'legislative-proposal-create'),
        ]);
    }

    public function store(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        abort_unless($request->user()->roleForMunicipality($municipality->id) === User::ROLE_COUNCILOR, 403);
        $validated = $this->validateProposal($request);
        if (! $formSubmission->consume($request, 'legislative-proposal-create')) {
            return redirect()->route('legislative.index')->with('warning', 'Esta proposta já foi cadastrada.');
        }
        $profile = $service->profile($municipality, (int) $validated['fiscal_year']);
        if (! $profile) {
            return back()->withInput()->withErrors(['fiscal_year' => 'Não existe configuração normativa vigente para este exercício.']);
        }
        $membership = $request->user()->municipalities()->whereKey($municipality->id)->firstOrFail()->pivot;

        $proposal = DB::transaction(function () use ($request, $municipality, $profile, $membership, $validated): LegislativeProposal {
            Municipality::query()->lockForUpdate()->findOrFail($municipality->id);
            $sequence = $municipality->legislativeProposals()->where('fiscal_year', $validated['fiscal_year'])->count() + 1;
            $reference = 'LEG-'.$validated['fiscal_year'].'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);

            $proposal = $municipality->legislativeProposals()->create([
                ...$validated,
                'municipal_regulatory_profile_id' => $profile->id,
                'submitted_by' => $request->user()->id,
                'author_name' => trim((string) ($membership->legislative_name ?: $request->user()->name)),
                'author_party' => trim((string) $membership->legislative_party),
                'reference' => $reference,
                'status' => LegislativeProposal::STATUS_DRAFT,
            ]);
            $this->event($proposal, $request->user()->id, 'created', null, LegislativeProposal::STATUS_DRAFT, 'Proposta legislativa iniciada.');

            return $proposal;
        });
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_created', [
            'proposal_id' => $proposal->id, 'reference' => $proposal->reference, 'fiscal_year' => $proposal->fiscal_year,
        ]);

        return redirect()->route('legislative.show', $proposal)->with('status', 'Proposta salva como rascunho.');
    }

    public function show(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal)->load([
            'regulatoryProfile', 'submitter:id,name,email', 'reviewer:id,name', 'receiver:id,name',
            'events.actor:id,name', 'amendment.municipalWorkPlan',
            'amendment.executionStages',
            'amendment.financialCommitments.liquidations', 'amendment.financialCommitments.payments',
            'amendment.accountabilityProcess',
        ]);
        $role = $request->user()->roleForMunicipality($municipality->id);

        return view('legislative.show', [
            ...$this->formOptions($municipality, $request, $service, $proposal->fiscal_year),
            'municipality' => $municipality,
            'proposal' => $proposal,
            'role' => $role,
            'quota' => $service->quota($municipality, $proposal->regulatoryProfile, $proposal->author_name, $proposal),
            'reviewChecklist' => $service->reviewChecklist(),
            'reviewBlockers' => $service->reviewBlockers($proposal),
            'canEdit' => $role === User::ROLE_COUNCILOR && $proposal->submitted_by === $request->user()->id && $proposal->isEditable(),
            'canReview' => in_array($role, [User::ROLE_MANAGER, User::ROLE_LEGISLATIVE_REVIEWER], true),
            'canReceive' => in_array($role, [User::ROLE_MANAGER, User::ROLE_EDITOR], true),
            'updateToken' => $formSubmission->issue($request, "legislative-proposal-update-{$proposal->id}"),
            'submitToken' => $formSubmission->issue($request, "legislative-proposal-submit-{$proposal->id}"),
            'reviewToken' => $formSubmission->issue($request, "legislative-proposal-review-{$proposal->id}"),
            'protocolToken' => $formSubmission->issue($request, "legislative-proposal-protocol-{$proposal->id}"),
            'receiveToken' => $formSubmission->issue($request, "legislative-proposal-receive-{$proposal->id}"),
            'reserveToken' => $formSubmission->issue($request, "legislative-proposal-reserve-{$proposal->id}"),
        ]);
    }

    public function update(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal);
        abort_unless($proposal->submitted_by === $request->user()->id && $proposal->isEditable(), 409, 'Esta proposta não pode mais ser editada.');
        $validated = $this->validateProposal($request, false);
        if (! $formSubmission->consume($request, "legislative-proposal-update-{$proposal->id}")) {
            return back()->with('warning', 'Esta alteração já foi processada.');
        }
        unset($validated['fiscal_year']);
        $before = $proposal->only(array_keys($validated));
        $proposal->update($validated);
        $this->event($proposal, $request->user()->id, 'updated', $proposal->status, $proposal->status, 'Conteúdo da proposta atualizado.', ['before' => $before]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_updated', [
            'proposal_id' => $proposal->id, 'reference' => $proposal->reference,
        ], $before);

        return back()->with('status', 'Rascunho atualizado.');
    }

    public function submit(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
        LegislativeNotificationService $notifications,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal);
        abort_unless($proposal->submitted_by === $request->user()->id, 404);
        $request->validate(['_submission_token' => ['required', 'string']]);
        if (! $formSubmission->consume($request, "legislative-proposal-submit-{$proposal->id}")) {
            return back()->with('warning', 'Este envio já foi processado.');
        }
        abort_unless($proposal->isEditable(), 409, 'Esta proposta não está disponível para envio.');
        $errors = $service->submissionErrors($proposal, $request->user());
        if ($errors !== []) {
            return back()->withErrors($errors);
        }
        $from = $proposal->status;
        $proposal->update(['status' => LegislativeProposal::STATUS_SUBMITTED, 'submitted_at' => now()]);
        $this->event($proposal, $request->user()->id, 'submitted', $from, $proposal->status, 'Encaminhada à conferência mínima da Câmara.');
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_submitted', ['proposal_id' => $proposal->id, 'reference' => $proposal->reference]);
        $notifications->roles($proposal, [User::ROLE_MANAGER, User::ROLE_LEGISLATIVE_REVIEWER], 'Nova indicação para conferência legislativa', "{$proposal->author_name} enviou a indicação {$proposal->reference}.");

        return back()->with('status', 'Indicação enviada para a conferência mínima da Câmara.');
    }

    public function review(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
        LegislativeNotificationService $notifications,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal);
        $rules = ['_submission_token' => ['required', 'string'], 'decision' => ['required', Rule::in(['approve', 'return', 'reject'])], 'review_notes' => ['required', 'string', 'min:20', 'max:5000']];
        foreach (array_keys($service->reviewChecklist()) as $field) {
            $rules[$field] = ['nullable', 'boolean'];
        }
        $validated = $request->validate($rules, ['review_notes.min' => 'Fundamente a análise com pelo menos 20 caracteres.']);
        if (! $formSubmission->consume($request, "legislative-proposal-review-{$proposal->id}")) {
            return back()->with('warning', 'Esta análise já foi processada.');
        }
        abort_unless($proposal->status === LegislativeProposal::STATUS_SUBMITTED, 409, 'A proposta não aguarda análise.');
        $checks = collect(array_keys($service->reviewChecklist()))->mapWithKeys(fn (string $field) => [$field => $request->boolean($field)])->all();
        $proposal->forceFill($checks);
        if ($validated['decision'] === 'approve' && $service->reviewBlockers($proposal) !== []) {
            return back()->withErrors(['review' => 'Para aprovar, conclua todos os pontos da análise técnica.']);
        }
        $to = match ($validated['decision']) {
            'approve' => LegislativeProposal::STATUS_APPROVED,
            'return' => LegislativeProposal::STATUS_RETURNED,
            default => LegislativeProposal::STATUS_REJECTED,
        };
        $from = $proposal->status;
        $proposal->forceFill([
            ...$checks, 'status' => $to, 'reviewed_by' => $request->user()->id,
            'review_notes' => trim($validated['review_notes']), 'reviewed_at' => now(),
        ])->save();
        $this->event($proposal, $request->user()->id, 'reviewed', $from, $to, $proposal->review_notes, ['checks' => $checks]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_reviewed', ['proposal_id' => $proposal->id, 'reference' => $proposal->reference, 'decision' => $to]);
        $notifications->submitter($proposal, 'Análise legislativa concluída', "A proposta {$proposal->reference} foi marcada como {$proposal->statusLabel()}.", $to === LegislativeProposal::STATUS_REJECTED ? 'critical' : ($to === LegislativeProposal::STATUS_RETURNED ? 'warning' : 'info'));

        return back()->with('status', 'Análise legislativa registrada e preservada.');
    }

    public function protocol(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
        LegislativeNotificationService $notifications,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'protocol_number' => ['required', 'string', 'min:3', 'max:180'],
        ]);
        if (! $formSubmission->consume($request, "legislative-proposal-protocol-{$proposal->id}")) {
            return back()->with('warning', 'Este protocolo já foi processado.');
        }
        $proposal->forceFill(['protocol_number' => trim($validated['protocol_number'])]);
        $blockers = $service->protocolBlockers($proposal);
        if ($blockers !== []) {
            return back()->withErrors(['protocol' => implode(' ', $blockers)]);
        }
        $snapshot = $service->protocolSnapshot($proposal);
        $from = $proposal->status;
        $proposal->forceFill([
            'status' => LegislativeProposal::STATUS_SENT, 'sent_at' => now(),
            'protocol_snapshot' => $snapshot, 'protocol_sha256' => $service->hash($snapshot),
        ])->save();
        $this->event($proposal, $request->user()->id, 'protocolled', $from, $proposal->status, 'Encaminhamento formal da Câmara ao Executivo.', ['protocol' => $proposal->protocol_number, 'sha256' => $proposal->protocol_sha256]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_protocolled', ['proposal_id' => $proposal->id, 'reference' => $proposal->reference, 'protocol' => $proposal->protocol_number, 'sha256' => $proposal->protocol_sha256]);
        $notifications->roles($proposal, [User::ROLE_MANAGER, User::ROLE_EDITOR], 'Proposta protocolada pela Câmara', "A proposta {$proposal->reference} aguarda recebimento formal pelo Executivo.", 'warning');

        return back()->with('status', 'Proposta protocolada no Executivo com fotografia e hash preservados.');
    }

    public function receive(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeNotificationService $notifications,
        MunicipalTransparencyTrail $transparencyTrail,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'executive_process_number' => ['required', 'string', 'min:3', 'max:180'],
            'executive_notes' => ['required', 'string', 'min:20', 'max:5000'],
        ], ['executive_notes.min' => 'Registre a conferência inicial do Executivo com pelo menos 20 caracteres.']);
        if (! $formSubmission->consume($request, "legislative-proposal-receive-{$proposal->id}")) {
            return back()->with('warning', 'Este recebimento já foi processado.');
        }
        abort_unless($proposal->status === LegislativeProposal::STATUS_SENT, 409, 'A proposta não aguarda recebimento.');

        $amendment = DB::transaction(function () use ($request, $municipality, $proposal, $validated, $transparencyTrail): ParliamentaryAmendment {
            $locked = LegislativeProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            abort_unless($locked->status === LegislativeProposal::STATUS_SENT && $locked->parliamentary_amendment_id === null, 409, 'A proposta já foi recebida.');
            $amendment = $municipality->amendments()->create([
                'municipal_regulatory_profile_id' => $locked->municipal_regulatory_profile_id,
                'created_by' => $request->user()->id,
                'reference' => $locked->reference,
                'fiscal_year' => $locked->fiscal_year,
                'government_sphere' => 'municipal',
                'authorship_type' => 'individual',
                'transfer_type' => $locked->transfer_type,
                'author_name' => $locked->author_name,
                'author_party' => $locked->author_party,
                'object' => $locked->object,
                'expense_destination' => $locked->expense_destination,
                'indicated_for_health' => $locked->health_related,
                'responsible_department' => $locked->responsible_department,
                'beneficiary_location' => $locked->beneficiary_location,
                'administrative_process' => trim($validated['executive_process_number']),
                'expected_amount' => $locked->estimated_amount,
                'status' => ParliamentaryAmendment::STATUS_IDENTIFIED,
                'indicated_at' => $locked->sent_at?->toDateString() ?? now()->toDateString(),
                'notes' => 'Recebida do Portal Legislativo. Beneficiário indicado: '.$locked->beneficiary_name.'. '.$validated['executive_notes'],
            ]);
            $transparencyTrail->recordCreation($amendment);
            $from = $locked->status;
            $locked->update([
                'status' => LegislativeProposal::STATUS_RECEIVED,
                'received_by' => $request->user()->id,
                'received_at' => now(),
                'executive_process_number' => trim($validated['executive_process_number']),
                'executive_notes' => trim($validated['executive_notes']),
                'parliamentary_amendment_id' => $amendment->id,
            ]);
            $this->event($locked, $request->user()->id, 'received', $from, $locked->status, $locked->executive_notes, ['amendment_id' => $amendment->id, 'process' => $locked->executive_process_number]);

            return $amendment;
        });
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_received', ['proposal_id' => $proposal->id, 'reference' => $proposal->reference, 'amendment_id' => $amendment->id]);
        $notifications->submitter($proposal->fresh(), 'Proposta recebida pelo Executivo', "A proposta {$proposal->reference} foi vinculada ao processo {$validated['executive_process_number']}.");

        return back()->with('status', 'Recebimento confirmado. A emenda foi aberta para reanálise do Executivo.');
    }

    public function reserve(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeNotificationService $notifications,
        MunicipalTransparencyTrail $transparencyTrail,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal)->load('amendment');
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'budget_reservation_number' => ['required', 'string', 'min:3', 'max:180'],
            'budget_reserved_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'budget_reserved_at' => ['required', 'date', 'before_or_equal:today'],
            'executive_notes' => ['required', 'string', 'min:20', 'max:5000'],
        ], ['executive_notes.min' => 'Registre a reanálise orçamentária com pelo menos 20 caracteres.']);
        if (abs((float) $validated['budget_reserved_amount'] - (float) $proposal->estimated_amount) > 0.01) {
            return back()->withErrors(['budget_reserved_amount' => 'A reserva deve corresponder integralmente ao valor protocolado. Divergências exigem devolução formal à Câmara.']);
        }
        if (! $formSubmission->consume($request, "legislative-proposal-reserve-{$proposal->id}")) {
            return back()->with('warning', 'Esta reserva já foi processada.');
        }
        abort_unless($proposal->status === LegislativeProposal::STATUS_RECEIVED && $proposal->amendment, 409, 'A proposta ainda não está disponível para reserva.');

        DB::transaction(function () use ($request, $proposal, $validated, $transparencyTrail): void {
            $from = $proposal->status;
            $proposal->update([
                'status' => LegislativeProposal::STATUS_RESERVED,
                'budget_reservation_number' => trim($validated['budget_reservation_number']),
                'budget_reserved_amount' => $validated['budget_reserved_amount'],
                'budget_reserved_at' => $validated['budget_reserved_at'],
                'executive_notes' => trim($validated['executive_notes']),
            ]);
            $oldAmendment = $proposal->amendment->getOriginal();
            $proposal->amendment->update(['status' => ParliamentaryAmendment::STATUS_PLAN_PENDING]);
            $transparencyTrail->recordAmendmentChanges($proposal->amendment, $oldAmendment);
            $this->event($proposal, $request->user()->id, 'budget_reserved', $from, $proposal->status, $proposal->executive_notes, [
                'reservation' => $proposal->budget_reservation_number, 'amount' => (float) $proposal->budget_reserved_amount,
            ]);
        });
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_budget_reserved', ['proposal_id' => $proposal->id, 'reference' => $proposal->reference, 'reservation' => $proposal->budget_reservation_number]);
        $notifications->submitter($proposal, 'Reserva orçamentária registrada', "A proposta {$proposal->reference} avançou para a solicitação e análise do Plano de Trabalho.");

        return back()->with('status', 'Reserva registrada. O fluxo executivo avançou para o Plano de Trabalho.');
    }

    /** @return array<string, mixed> */
    private function validateProposal(Request $request, bool $includeYear = true): array
    {
        $rules = [
            '_submission_token' => ['required', 'string'],
            'object' => ['required', 'string', 'min:20', 'max:5000'],
            'justification' => ['required', 'string', 'min:30', 'max:5000'],
            'priority' => ['required', Rule::in(array_keys(LegislativeProposal::priorities()))],
            'beneficiary_type' => ['required', Rule::in(array_keys(LegislativeProposal::beneficiaryTypes()))],
            'beneficiary_name' => ['required', 'string', 'min:3', 'max:255'],
            'beneficiary_cnpj' => ['nullable', 'required_if:beneficiary_type,third_sector', 'string', 'max:20'],
            'beneficiary_location' => ['required', 'string', 'min:3', 'max:255'],
            'expense_destination' => ['required', Rule::in(array_keys(ParliamentaryAmendment::expenseDestinations()))],
            'transfer_type' => ['required', Rule::in(array_keys(ParliamentaryAmendment::transferTypes()))],
            'health_related' => ['nullable', 'boolean'],
            'responsible_department' => ['required', 'string', 'min:3', 'max:255'],
            'program_reference' => ['nullable', 'string', 'max:255'],
            'action_reference' => ['nullable', 'string', 'max:255'],
            'public_need' => ['required', 'string', 'min:30', 'max:5000'],
            'target_population' => ['nullable', 'string', 'max:255'],
            'estimated_quantity' => ['nullable', 'string', 'max:255'],
            'estimated_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'estimate_source' => ['required', 'string', 'min:5', 'max:255'],
            'desired_contract_at' => ['nullable', 'date'],
            'third_sector_conflict_declaration' => ['nullable', 'boolean'],
        ];
        if ($includeYear) {
            $rules['fiscal_year'] = ['required', 'integer', 'between:'.now()->year.','.(now()->year + 2)];
        }
        $validated = $request->validate($rules, [
            'object.min' => 'Descreva uma entrega específica; evite objetos genéricos.',
            'justification.min' => 'Explique com mais detalhes por que a proposta atende ao interesse público.',
            'public_need.min' => 'Identifique a necessidade pública e quem será atendido.',
            'beneficiary_cnpj.required_if' => 'Informe o CNPJ da organização da sociedade civil.',
        ]);
        unset($validated['_submission_token']);
        foreach (['beneficiary_cnpj', 'program_reference', 'action_reference', 'target_population', 'estimated_quantity', 'desired_contract_at'] as $field) {
            $validated[$field] = blank($validated[$field] ?? null) ? null : trim((string) $validated[$field]);
        }
        foreach (['object', 'justification', 'beneficiary_name', 'beneficiary_location', 'responsible_department', 'public_need', 'estimate_source'] as $field) {
            $validated[$field] = trim((string) $validated[$field]);
        }
        $validated['health_related'] = $request->boolean('health_related');
        $validated['third_sector_conflict_declaration'] = $request->boolean('third_sector_conflict_declaration');

        return $validated;
    }

    /** @return array<string, mixed> */
    private function formOptions(
        Municipality $municipality,
        Request $request,
        LegislativeProposalService $service,
        int $year,
    ): array {
        $membership = $request->user()->municipalities()->whereKey($municipality->id)->firstOrFail()->pivot;
        $profile = $service->profile($municipality, $year);

        return [
            'municipality' => $municipality,
            'profile' => $profile,
            'membership' => $membership,
            'year' => $year,
            'priorities' => LegislativeProposal::priorities(),
            'beneficiaryTypes' => LegislativeProposal::beneficiaryTypes(),
            'expenseDestinations' => ParliamentaryAmendment::expenseDestinations(),
            'transferTypes' => collect(ParliamentaryAmendment::transferTypes())->only(['direct_execution', 'defined_purpose', 'fund_to_fund', 'other'])->all(),
            'quota' => $service->quota($municipality, $profile, (string) ($membership->legislative_name ?: $request->user()->name)),
        ];
    }

    private function proposal(Request $request, Municipality $municipality, int $proposal): LegislativeProposal
    {
        $item = $municipality->legislativeProposals()->findOrFail($proposal);
        if ($request->user()->roleForMunicipality($municipality->id) === User::ROLE_COUNCILOR) {
            abort_unless($item->submitted_by === $request->user()->id, 404);
        }

        return $item;
    }

    /** @param array<string, mixed>|null $snapshot */
    private function event(
        LegislativeProposal $proposal,
        ?int $actorId,
        string $type,
        ?string $from,
        ?string $to,
        ?string $notes,
        ?array $snapshot = null,
    ): void {
        $proposal->events()->create([
            'municipality_id' => $proposal->municipality_id,
            'actor_id' => $actorId,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $notes,
            'snapshot' => $snapshot,
        ]);
    }
}
