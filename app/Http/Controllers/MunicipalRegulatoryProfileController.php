<?php

namespace App\Http\Controllers;

use App\Models\MunicipalNormativeInstrument;
use App\Models\MunicipalRegulatoryProfile;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\MunicipalRegulatoryReadiness;
use App\Services\MunicipalRuleApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MunicipalRegulatoryProfileController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalRegulatoryReadiness $readiness,
        MunicipalRuleApplicationService $municipalRules,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $profiles = $municipality->regulatoryProfiles()
            ->with(['instruments', 'activator', 'audespResponsible'])
            ->orderByDesc('fiscal_year')
            ->orderByDesc('version')
            ->get();
        $selected = $request->integer('perfil')
            ? $profiles->firstWhere('id', $request->integer('perfil'))
            : $profiles->first(fn ($profile) => $profile->isDraft()) ?? $profiles->first();

        return view('municipal-rules.index', [
            'municipality' => $municipality,
            'profiles' => $profiles,
            'profile' => $selected,
            'diagnostic' => $selected ? $readiness->evaluate($selected) : null,
            'portfolio' => $selected ? $municipalRules->portfolioAssessment($selected) : null,
            'members' => $municipality->users()->orderBy('name')->get(),
            'canManage' => $request->user()->roleForMunicipality($municipality->id) === 'manager',
            'profileToken' => $formSubmission->issue($request, 'municipal-rules-create'),
            'instrumentToken' => $selected ? $formSubmission->issue($request, "municipal-rules-instrument-{$selected->id}") : null,
        ]);
    }

    public function store(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'fiscal_year' => ['required', 'integer', 'min:2020', 'max:2100'],
        ]);

        if (! $formSubmission->consume($request, 'municipal-rules-create')) {
            return back()->with('warning', 'Esta solicitação já foi processada.');
        }

        if ($municipality->regulatoryProfiles()->where('fiscal_year', $validated['fiscal_year'])->where('status', MunicipalRegulatoryProfile::STATUS_DRAFT)->exists()) {
            return back()->with('warning', 'Já existe uma revisão em preparação para este exercício.');
        }

        $profile = DB::transaction(function () use ($request, $municipality, $validated, $auditTrail): MunicipalRegulatoryProfile {
            $version = (int) $municipality->regulatoryProfiles()->where('fiscal_year', $validated['fiscal_year'])->max('version') + 1;
            $profile = $municipality->regulatoryProfiles()->create([
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'fiscal_year' => $validated['fiscal_year'],
                'version' => max(1, $version),
            ]);
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_rules_created', [
                'fiscal_year' => $profile->fiscal_year,
                'regulatory_version' => $profile->version,
            ]);

            return $profile;
        });

        return redirect()->route('municipal-rules.index', ['perfil' => $profile->id])
            ->with('status', 'Configuração municipal iniciada. Agora registre as normas e decisões locais.');
    }

    public function update(
        Request $request,
        int $profile,
        CurrentMunicipality $currentMunicipality,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $rules = $municipality->regulatoryProfiles()->findOrFail($profile);
        abort_unless($rules->isDraft(), 409, 'Crie uma nova revisão para alterar uma configuração vigente.');

        $validated = $request->validate($this->profileRules($municipality->id));
        $values = [
            ...Arr::except($validated, ['generic_amendments_prohibited', 'prior_technical_review_required', 'work_plan_required', 'pca_check_required']),
            'generic_amendments_prohibited' => $this->nullableBoolean($request->input('generic_amendments_prohibited')),
            'prior_technical_review_required' => $this->nullableBoolean($request->input('prior_technical_review_required')),
            'work_plan_required' => $this->nullableBoolean($request->input('work_plan_required')),
            'pca_check_required' => $this->nullableBoolean($request->input('pca_check_required')),
            'updated_by' => $request->user()->id,
        ];
        $values = array_map(fn ($value) => $value === '' ? null : $value, $values);
        $oldValues = $rules->only(array_keys($values));
        $changed = collect($values)->filter(fn ($value, $field) => $oldValues[$field] != $value)->all();

        if ($changed === []) {
            return back()->with('warning', 'Nenhum parâmetro foi alterado.');
        }

        DB::transaction(function () use ($request, $municipality, $rules, $oldValues, $changed, $auditTrail): void {
            $rules->update($changed);
            $auditTrail->recordMunicipalityOperation(
                $request,
                $municipality,
                'municipal_rules_updated',
                ['profile_id' => $rules->id, ...$changed],
                array_intersect_key($oldValues, $changed),
            );
        });

        return back()->with('status', 'Parâmetros municipais atualizados.');
    }

    public function addInstrument(
        Request $request,
        int $profile,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $rules = $municipality->regulatoryProfiles()->findOrFail($profile);
        abort_unless($rules->isDraft(), 409, 'Instrumentos de uma configuração vigente são imutáveis.');
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'type' => ['required', Rule::in(array_keys(MunicipalNormativeInstrument::types()))],
            'title' => ['required', 'string', 'max:255'],
            'reference' => ['required', 'string', 'max:180'],
            'url' => ['nullable', 'url:http,https', 'max:1200'],
            'enacted_at' => ['nullable', 'date'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $formSubmission->consume($request, "municipal-rules-instrument-{$rules->id}")) {
            return back()->with('warning', 'Este instrumento já foi processado.');
        }

        DB::transaction(function () use ($request, $municipality, $rules, $validated, $auditTrail): void {
            $instrument = $rules->instruments()->create([
                ...Arr::except($validated, '_submission_token'),
                'municipality_id' => $municipality->id,
                'created_by' => $request->user()->id,
            ]);
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_instrument_created', [
                'profile_id' => $rules->id,
                'normative_instrument' => $instrument->title,
                'instrument_type' => $instrument->type,
                'reference' => $instrument->reference,
            ]);
        });

        return back()->with('status', 'Instrumento normativo vinculado à revisão.');
    }

    public function removeInstrument(
        Request $request,
        int $profile,
        int $instrument,
        CurrentMunicipality $currentMunicipality,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $rules = $municipality->regulatoryProfiles()->findOrFail($profile);
        abort_unless($rules->isDraft(), 409, 'Instrumentos de uma configuração vigente são imutáveis.');
        $norm = $rules->instruments()->where('municipality_id', $municipality->id)->findOrFail($instrument);

        DB::transaction(function () use ($request, $municipality, $rules, $norm, $auditTrail): void {
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_instrument_removed', [
                'profile_id' => $rules->id,
                'normative_instrument' => $norm->title,
                'reference' => $norm->reference,
            ]);
            $norm->delete();
        });

        return back()->with('status', 'Instrumento removido da revisão em preparação.');
    }

    public function activate(
        Request $request,
        int $profile,
        CurrentMunicipality $currentMunicipality,
        MunicipalRegulatoryReadiness $readiness,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $rules = $municipality->regulatoryProfiles()->with('instruments')->findOrFail($profile);
        abort_unless($rules->isDraft(), 409, 'Esta revisão não está em preparação.');
        $diagnostic = $readiness->evaluate($rules);

        if ($diagnostic['blockers'] !== []) {
            return back()->withErrors(['activation' => 'A configuração ainda possui requisitos obrigatórios pendentes.']);
        }

        DB::transaction(function () use ($request, $municipality, $rules, $auditTrail): void {
            $municipality->regulatoryProfiles()
                ->where('fiscal_year', $rules->fiscal_year)
                ->where('status', MunicipalRegulatoryProfile::STATUS_ACTIVE)
                ->update(['status' => MunicipalRegulatoryProfile::STATUS_ARCHIVED, 'updated_at' => now()]);
            $rules->update([
                'status' => MunicipalRegulatoryProfile::STATUS_ACTIVE,
                'activated_by' => $request->user()->id,
                'activated_at' => now(),
                'updated_by' => $request->user()->id,
            ]);
            $boundAmendments = $municipality->amendments()
                ->where('fiscal_year', $rules->fiscal_year)
                ->where('government_sphere', 'municipal')
                ->whereNull('municipal_regulatory_profile_id')
                ->update(['municipal_regulatory_profile_id' => $rules->id]);
            $boundImpediments = $municipality->technicalImpediments()
                ->whereNull('municipal_regulatory_profile_id')
                ->whereHas('amendment', fn ($query) => $query
                    ->where('municipal_regulatory_profile_id', $rules->id))
                ->update(['municipal_regulatory_profile_id' => $rules->id]);
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_rules_activated', [
                'profile_id' => $rules->id,
                'fiscal_year' => $rules->fiscal_year,
                'regulatory_version' => $rules->version,
                'amendments_bound' => $boundAmendments,
                'impediments_bound' => $boundImpediments,
            ]);
        });

        return back()->with('status', 'Configuração ativada e congelada como referência vigente do exercício.');
    }

    public function revise(
        Request $request,
        int $profile,
        CurrentMunicipality $currentMunicipality,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $source = $municipality->regulatoryProfiles()->with('instruments')->findOrFail($profile);

        if ($municipality->regulatoryProfiles()->where('fiscal_year', $source->fiscal_year)->where('status', MunicipalRegulatoryProfile::STATUS_DRAFT)->exists()) {
            return back()->with('warning', 'Já existe uma revisão em preparação para este exercício.');
        }

        $copy = DB::transaction(function () use ($request, $municipality, $source, $auditTrail): MunicipalRegulatoryProfile {
            $attributes = Arr::except($source->getAttributes(), [
                'id', 'created_at', 'updated_at', 'status', 'created_by', 'updated_by',
                'activated_by', 'activated_at',
            ]);
            $copy = $municipality->regulatoryProfiles()->create([
                ...$attributes,
                'version' => (int) $municipality->regulatoryProfiles()->where('fiscal_year', $source->fiscal_year)->max('version') + 1,
                'status' => MunicipalRegulatoryProfile::STATUS_DRAFT,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            foreach ($source->instruments as $instrument) {
                $copy->instruments()->create([
                    ...Arr::except($instrument->getAttributes(), ['id', 'municipal_regulatory_profile_id', 'created_at', 'updated_at', 'created_by']),
                    'created_by' => $request->user()->id,
                ]);
            }
            $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_rules_revised', [
                'source_profile_id' => $source->id,
                'profile_id' => $copy->id,
                'fiscal_year' => $copy->fiscal_year,
                'regulatory_version' => $copy->version,
            ]);

            return $copy;
        });

        return redirect()->route('municipal-rules.index', ['perfil' => $copy->id])
            ->with('status', 'Nova revisão criada sem alterar a versão vigente.');
    }

    /** @return array<string, mixed> */
    private function profileRules(int $municipalityId): array
    {
        return [
            'regime_status' => ['required', Rule::in(array_keys(MunicipalRegulatoryProfile::regimeStatuses()))],
            'previous_year_rcl' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.99'],
            'individual_limit_percentage' => ['nullable', 'numeric', 'gt:0', 'max:10'],
            'health_reserve_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'health_reserve_method' => ['nullable', Rule::in(array_keys(MunicipalRegulatoryProfile::healthReserveMethods()))],
            'amendments_per_councilor_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'minimum_amendment_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'generic_amendments_prohibited' => ['nullable', Rule::in(['0', '1'])],
            'prior_technical_review_required' => ['nullable', Rule::in(['0', '1'])],
            'work_plan_required' => ['nullable', Rule::in(['0', '1'])],
            'pca_check_required' => ['nullable', Rule::in(['0', '1'])],
            'impediment_notice_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'impediment_correction_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'publication_business_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'document_retention_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'bank_traceability_rule' => ['nullable', Rule::in(array_keys(MunicipalRegulatoryProfile::bankTraceabilityRules()))],
            'audesp_registration_status' => ['required', Rule::in(array_keys(MunicipalRegulatoryProfile::audespStatuses()))],
            'audesp_responsible_user_id' => ['nullable', Rule::exists('municipality_user', 'user_id')->where('municipality_id', $municipalityId)],
            'legal_review_responsible' => ['nullable', 'string', 'max:255'],
            'legal_review_reference' => ['nullable', 'string', 'max:255'],
            'legal_reviewed_at' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        return $value === null || $value === '' ? null : (bool) (int) $value;
    }
}
