<?php

namespace App\Http\Controllers;

use App\Models\HealthAspsAssessment;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\HealthAspsFramework;
use App\Services\IntegrityAlertService;
use App\Services\MunicipalWorkItemService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class HealthAspsController extends Controller
{
    public function index(Request $request, CurrentMunicipality $currentMunicipality): View
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $year = (int) $request->query('year', 2026);
        abort_unless($year === 2026, 422, 'A metodologia atual foi validada para o exercício de 2026.');
        $query = $municipality->amendments()
            ->where('fiscal_year', $year)
            ->where('government_sphere', 'municipal')
            ->where(function ($query): void {
                $query->whereHas('municipalWorkPlan', fn ($plan) => $plan->where('health_related', true))
                    ->orWhereHas('healthAspsAssessments');
            })
            ->with([
                'municipalWorkPlan',
                'healthAspsAssessments.reviewer:id,name',
                'responsibleUser:id,name',
            ])
            ->withSum('financialPayments as paid_amount', 'amount')
            ->orderBy('reference');
        $all = (clone $query)->get();
        $amendments = $query->paginate(24)->withQueryString();
        $profile = $municipality->regulatoryProfiles()
            ->where('fiscal_year', $year)->where('status', 'active')->latest('version')->first();
        $expected = (float) $all->sum('expected_amount');
        $individualPortfolio = (float) $municipality->amendments()
            ->where('fiscal_year', $year)
            ->where('government_sphere', 'municipal')
            ->where('authorship_type', 'individual')
            ->sum('expected_amount');
        $issued = $all->map(fn (ParliamentaryAmendment $amendment) => $this->issuedAssessment($amendment))->filter();
        $eligibleIds = $all->filter(fn (ParliamentaryAmendment $amendment) => $this->issuedAssessment($amendment)?->conclusion === HealthAspsAssessment::CONCLUSION_ELIGIBLE)->pluck('id');

        return view('health-asps.index', [
            'municipality' => $municipality,
            'amendments' => $amendments,
            'year' => $year,
            'profile' => $profile,
            'metrics' => [
                'designated_amount' => $expected,
                'required_amount' => $profile?->health_reserve_percentage !== null
                    ? round($individualPortfolio * ((float) $profile->health_reserve_percentage / 100), 2)
                    : null,
                'eligible_amount' => (float) $all->whereIn('id', $eligibleIds)->sum('expected_amount'),
                'eligible_paid' => (float) $all->whereIn('id', $eligibleIds)->sum('paid_amount'),
                'pending' => $all->filter(fn (ParliamentaryAmendment $amendment) => $this->issuedAssessment($amendment) === null)->count(),
                'ineligible' => $issued->where('conclusion', HealthAspsAssessment::CONCLUSION_INELIGIBLE)->count(),
            ],
        ]);
    }

    public function show(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        HealthAspsFramework $framework,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $amendment = $this->amendment($municipality, $emenda);
        $assessment = $amendment->healthAspsAssessments->first();
        $role = $request->user()->roleForMunicipality($municipality->id);
        $canPrepare = in_array($role, ['manager', 'editor', 'auditor'], true);
        $canReview = in_array($role, ['manager', 'auditor'], true);

        return view('health-asps.show', [
            'municipality' => $municipality,
            'amendment' => $amendment,
            'assessment' => $assessment,
            'history' => $amendment->healthAspsAssessments,
            'issuedAssessment' => $this->issuedAssessment($amendment),
            'diagnostic' => $assessment ? $framework->evaluate($assessment, $amendment) : null,
            'criteria' => $framework->criteria(),
            'categories' => $framework->categories(),
            'exclusions' => $framework->exclusions(),
            'documents' => $amendment->documents->sortByDesc('created_at'),
            'canPrepare' => $canPrepare && ($assessment === null || $assessment->isEditable()),
            'canSubmit' => $canPrepare && $assessment?->isEditable(),
            'canReview' => $canReview && $assessment?->status === HealthAspsAssessment::STATUS_UNDER_REVIEW,
            'canRevise' => $canPrepare && $assessment?->status === HealthAspsAssessment::STATUS_ISSUED,
            'saveToken' => $canPrepare ? $formSubmission->issue($request, "health-asps-save-{$amendment->id}") : null,
            'submitToken' => $canPrepare && $assessment ? $formSubmission->issue($request, "health-asps-submit-{$assessment->id}") : null,
            'decisionToken' => $canReview && $assessment ? $formSubmission->issue($request, "health-asps-decision-{$assessment->id}") : null,
            'reviseToken' => $canPrepare && $assessment ? $formSubmission->issue($request, "health-asps-revise-{$assessment->id}") : null,
        ]);
    }

    public function save(
        Request $request,
        int $emenda,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        HealthAspsFramework $framework,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $amendment = $this->amendment($municipality, $emenda);
        $validated = $this->validateAssessment($request, $amendment, $framework);
        if (! $formSubmission->consume($request, "health-asps-save-{$amendment->id}")) {
            return back()->with('warning', 'Esta atualização já foi processada.');
        }

        $assessment = $amendment->healthAspsAssessments->first();
        if ($assessment && ! $assessment->isEditable()) {
            abort(409, 'O parecer atual está em revisão ou já foi emitido.');
        }
        $attributes = $this->assessmentAttributes($request, $validated, $framework);
        if ($assessment) {
            $assessment->update([...$attributes, 'updated_by' => $request->user()->id]);
            $action = 'health_asps_assessment_updated';
        } else {
            $assessment = DB::transaction(function () use ($request, $municipality, $amendment, $attributes): HealthAspsAssessment {
                $latestVersion = $municipality->healthAspsAssessments()
                    ->where('parliamentary_amendment_id', $amendment->id)
                    ->latest('version')->lockForUpdate()->value('version');

                return $amendment->healthAspsAssessments()->create([
                    ...$attributes,
                    'municipality_id' => $municipality->id,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                    'reference' => (string) Str::uuid(),
                    'fiscal_year' => $amendment->fiscal_year,
                    'version' => ((int) $latestVersion) + 1,
                    'status' => HealthAspsAssessment::STATUS_DRAFT,
                ]);
            });
            $action = 'health_asps_assessment_created';
        }
        $auditTrail->recordMunicipalityOperation($request, $municipality, $action, [
            'assessment' => $assessment->code(), 'amendment' => $amendment->reference,
            'diagnostic' => $framework->evaluate($assessment, $amendment)['recommendation'],
        ]);

        return back()->with('status', 'Enquadramento ASPS salvo. Confira o diagnóstico antes de enviar para revisão.');
    }

    public function submit(
        Request $request,
        int $assessment,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        HealthAspsFramework $framework,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $assessment = $this->assessment($municipality, $assessment);
        abort_unless($assessment->isEditable(), 409, 'Este parecer não pode ser enviado novamente.');
        $request->validate(['_submission_token' => ['required', 'string']]);
        if (! $formSubmission->consume($request, "health-asps-submit-{$assessment->id}")) {
            return back()->with('warning', 'O envio já foi processado.');
        }
        $diagnostic = $framework->evaluate($assessment, $assessment->amendment);
        if ($diagnostic['blockers'] !== [] && $diagnostic['recommendation'] !== HealthAspsAssessment::CONCLUSION_INELIGIBLE) {
            return back()->withErrors(['assessment' => 'Resolva os bloqueios ou identifique formalmente uma hipótese de exclusão antes de enviar.']);
        }
        $assessment->update([
            'status' => HealthAspsAssessment::STATUS_UNDER_REVIEW,
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
        ]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'health_asps_assessment_submitted', [
            'assessment' => $assessment->code(), 'amendment' => $assessment->amendment->reference,
        ]);

        return back()->with('status', 'Enquadramento enviado para decisão do Controle Interno.');
    }

    public function decision(
        Request $request,
        int $assessment,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        HealthAspsFramework $framework,
        AuditTrail $auditTrail,
        IntegrityAlertService $alerts,
        MunicipalWorkItemService $workItems,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $assessment = $this->assessment($municipality, $assessment);
        abort_unless($assessment->status === HealthAspsAssessment::STATUS_UNDER_REVIEW, 409, 'Este parecer não aguarda decisão.');
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'action' => ['required', Rule::in(['return', 'eligible', 'ineligible'])],
            'reviewer_notes' => ['required', 'string', 'min:20', 'max:4000'],
        ], ['reviewer_notes.min' => 'Fundamente a decisão com pelo menos 20 caracteres.']);
        if (! $formSubmission->consume($request, "health-asps-decision-{$assessment->id}")) {
            return back()->with('warning', 'A decisão já foi processada.');
        }
        if ($validated['action'] === 'return') {
            $assessment->update([
                'status' => HealthAspsAssessment::STATUS_RETURNED,
                'reviewed_by' => $request->user()->id,
                'reviewer_notes' => trim($validated['reviewer_notes']),
                'reviewed_at' => now(),
            ]);
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'health_asps_assessment_returned', [
                'assessment' => $assessment->code(), 'reviewer_notes' => $assessment->reviewer_notes,
            ]);

            return back()->with('warning', 'Parecer devolvido para ajustes, preservando a versão e a fundamentação.');
        }

        $diagnostic = $framework->evaluate($assessment, $assessment->amendment);
        if ($validated['action'] === HealthAspsAssessment::CONCLUSION_ELIGIBLE && ! $diagnostic['ready']) {
            return back()->withErrors(['decision' => 'Não é possível concluir pelo cômputo em ASPS enquanto houver bloqueios no diagnóstico.']);
        }
        $assessment->forceFill([
            'conclusion' => $validated['action'],
            'reviewed_by' => $request->user()->id,
            'reviewer_notes' => trim($validated['reviewer_notes']),
            'reviewed_at' => now(),
        ]);
        $snapshot = $framework->snapshot($assessment, $assessment->amendment);
        $assessment->forceFill([
            'status' => HealthAspsAssessment::STATUS_ISSUED,
            'snapshot' => $snapshot,
            'snapshot_sha256' => $framework->hash($snapshot),
        ])->save();
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'health_asps_assessment_issued', [
            'assessment' => $assessment->code(), 'conclusion' => $assessment->conclusionLabel(),
            'snapshot_sha256' => $assessment->snapshot_sha256,
        ]);
        $alerts->sync($municipality);
        $workItems->synchronize($municipality);

        return back()->with('status', 'Parecer ASPS emitido. A fotografia foi fechada e integrada aos controles municipais.');
    }

    public function revise(
        Request $request,
        int $assessment,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $assessment = $this->assessment($municipality, $assessment);
        abort_unless($assessment->status === HealthAspsAssessment::STATUS_ISSUED, 409, 'Somente parecer emitido pode originar revisão.');
        $request->validate(['_submission_token' => ['required', 'string']]);
        if (! $formSubmission->consume($request, "health-asps-revise-{$assessment->id}")) {
            return back()->with('warning', 'A revisão já foi aberta.');
        }
        $draft = DB::transaction(function () use ($request, $municipality, $assessment): HealthAspsAssessment {
            $locked = $municipality->healthAspsAssessments()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
            $existing = $municipality->healthAspsAssessments()
                ->where('parliamentary_amendment_id', $locked->parliamentary_amendment_id)
                ->whereIn('status', [HealthAspsAssessment::STATUS_DRAFT, HealthAspsAssessment::STATUS_RETURNED, HealthAspsAssessment::STATUS_UNDER_REVIEW])
                ->first();
            if ($existing) {
                return $existing;
            }
            $copy = $locked->replicate([
                'submitted_by', 'reviewed_by', 'status', 'conclusion', 'reviewer_notes',
                'snapshot', 'snapshot_sha256', 'submitted_at', 'reviewed_at',
            ]);
            $copy->fill([
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'supersedes_id' => $locked->id,
                'reference' => (string) Str::uuid(),
                'version' => $locked->version + 1,
                'status' => HealthAspsAssessment::STATUS_DRAFT,
                'conclusion' => null,
            ]);
            $copy->save();

            return $copy;
        });
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'health_asps_assessment_revised', [
            'assessment' => $draft->code(), 'supersedes' => $assessment->code(),
        ]);

        return back()->with('status', 'Nova versão aberta sem alterar o parecer anteriormente emitido.');
    }

    public function pdf(Request $request, int $assessment, CurrentMunicipality $currentMunicipality, AuditTrail $auditTrail): Response
    {
        $municipality = $currentMunicipality->get($request);
        $this->ensureScope($municipality);
        $assessment = $this->assessment($municipality, $assessment);
        abort_unless($assessment->status === HealthAspsAssessment::STATUS_ISSUED, 409, 'Emita o parecer antes de gerar o documento final.');
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'health_asps_assessment_downloaded', [
            'assessment' => $assessment->code(), 'snapshot_sha256' => $assessment->snapshot_sha256,
        ]);

        return Pdf::loadView('health-asps.pdf', [
            'assessment' => $assessment,
            'snapshot' => $assessment->snapshot,
        ])->setPaper('a4')->download(Str::lower($assessment->code()).'.pdf');
    }

    /** @return array<string, mixed> */
    private function validateAssessment(Request $request, ParliamentaryAmendment $amendment, HealthAspsFramework $framework): array
    {
        return $request->validate([
            '_submission_token' => ['required', 'string'],
            'asps_category' => ['nullable', Rule::in(array_keys($framework->categories()))],
            'budget_function' => ['nullable', 'string', 'regex:/^[0-9]{2}$/'],
            'budget_subfunction' => ['nullable', 'string', 'regex:/^[0-9]{3}$/'],
            'funding_source_code' => ['nullable', 'string', 'max:100'],
            'application_code' => ['nullable', 'string', 'max:100'],
            'health_fund_reference' => ['nullable', 'string', 'max:180'],
            'health_plan_reference' => ['nullable', 'string', 'max:500'],
            'technical_justification' => ['nullable', 'string', 'max:5000'],
            'evidence_document_id' => ['nullable', Rule::exists('amendment_documents', 'id')->where(fn ($query) => $query
                ->where('municipality_id', $amendment->municipality_id)
                ->where('parliamentary_amendment_id', $amendment->id))],
            'criteria' => ['nullable', 'array'],
            'exclusion_reasons' => ['nullable', 'array'],
            'exclusion_reasons.*' => [Rule::in(array_keys($framework->exclusions()))],
        ], [
            'budget_function.regex' => 'Informe a função com dois dígitos, por exemplo 10.',
            'budget_subfunction.regex' => 'Informe a subfunção com três dígitos, por exemplo 301.',
            'evidence_document_id.exists' => 'O documento selecionado não pertence a esta emenda.',
        ]);
    }

    /** @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function assessmentAttributes(Request $request, array $validated, HealthAspsFramework $framework): array
    {
        return [
            ...collect($validated)->except(['_submission_token', 'criteria', 'exclusion_reasons'])->all(),
            'criteria' => collect($framework->criteria())->mapWithKeys(fn ($label, $key) => [$key => $request->boolean("criteria.{$key}")])->all(),
            'exclusion_reasons' => array_values(array_unique($validated['exclusion_reasons'] ?? [])),
        ];
    }

    private function amendment(Municipality $municipality, int $id): ParliamentaryAmendment
    {
        $amendment = $municipality->amendments()->with([
            'municipality:id,state,ibge_code', 'municipalWorkPlan', 'audespRegistration',
            'documents.documentType', 'healthAspsAssessments.creator:id,name',
            'healthAspsAssessments.reviewer:id,name', 'healthAspsAssessments.evidenceDocument.documentType',
        ])->findOrFail($id);
        abort_unless($amendment->government_sphere === 'municipal', 404);

        return $amendment;
    }

    private function assessment(Municipality $municipality, int $id): HealthAspsAssessment
    {
        return $municipality->healthAspsAssessments()->with([
            'amendment.municipalWorkPlan', 'amendment.audespRegistration',
            'creator:id,name', 'submitter:id,name', 'reviewer:id,name', 'evidenceDocument.documentType',
        ])->findOrFail($id);
    }

    private function issuedAssessment(ParliamentaryAmendment $amendment): ?HealthAspsAssessment
    {
        return $amendment->healthAspsAssessments
            ->first(fn (HealthAspsAssessment $assessment) => $assessment->status === HealthAspsAssessment::STATUS_ISSUED);
    }

    private function ensureScope(Municipality $municipality): void
    {
        abort_unless($municipality->supportsTcespAudesp(), 404);
    }
}
