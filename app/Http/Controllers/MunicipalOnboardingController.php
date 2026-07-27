<?php

namespace App\Http\Controllers;

use App\Models\MunicipalNormativeInstrument;
use App\Models\MunicipalRegulatoryProfile;
use App\Services\AuditTrail;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\MunicipalOnboardingService;
use App\Services\MunicipalOrganicLawAutomation;
use App\Services\MunicipalRegulatoryReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MunicipalOnboardingController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalOnboardingService $onboarding,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $summary = $onboarding->summary($municipality);

        return view('municipal-onboarding.index', [
            'municipality' => $municipality,
            'summary' => $summary,
            'activationToken' => $formSubmission->issue($request, "municipal-onboarding-activate-{$municipality->id}"),
            'councilInvitationToken' => $formSubmission->issue($request, 'municipality-invitation-create'),
        ]);
    }

    public function activate(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        MunicipalOrganicLawAutomation $organicLawAutomation,
        MunicipalRegulatoryReadiness $readiness,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $validated = $request->validate([
            '_submission_token' => ['required', 'string'],
            'fiscal_year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'previous_year_rcl' => ['required', 'numeric', 'min:1', 'max:9999999999999999.99'],
            'councilor_seats' => ['required', 'integer', 'min:1', 'max:1000'],
            'legal_review_responsible' => ['required', 'string', 'min:3', 'max:255'],
            'legal_review_reference' => ['required', 'string', 'min:3', 'max:255'],
            'legal_reviewed_at' => ['required', 'date', 'before_or_equal:today'],
        ], [
            'previous_year_rcl.required' => 'Informe a RCL do exercício anterior para calcular a cota dos vereadores.',
            'councilor_seats.required' => 'Informe o número de cadeiras da Câmara para dividir a cota automaticamente.',
            'legal_review_responsible.required' => 'Informe quem validou juridicamente a parametrização municipal.',
            'legal_review_reference.required' => 'Informe o parecer, despacho ou referência interna da validação jurídica.',
        ]);

        if (! $formSubmission->consume($request, "municipal-onboarding-activate-{$municipality->id}")) {
            return back()->with('warning', 'Esta ativação já foi processada.');
        }

        if ($municipality->regulatoryProfiles()
            ->where('fiscal_year', $validated['fiscal_year'])
            ->where('status', MunicipalRegulatoryProfile::STATUS_ACTIVE)
            ->exists()) {
            return back()->with('warning', 'Este exercício já possui norma ativa. Crie uma revisão em Normas municipais se precisar alterar.');
        }

        $profile = DB::transaction(function () use ($request, $municipality, $validated, $organicLawAutomation, $readiness, $auditTrail): MunicipalRegulatoryProfile {
            $profile = $municipality->regulatoryProfiles()
                ->where('fiscal_year', $validated['fiscal_year'])
                ->where('status', MunicipalRegulatoryProfile::STATUS_DRAFT)
                ->orderByDesc('version')
                ->first();

            $values = [
                'updated_by' => $request->user()->id,
                ...$organicLawAutomation->mergeInto([
                    'previous_year_rcl' => $validated['previous_year_rcl'],
                    'councilor_seats' => $validated['councilor_seats'],
                    'legal_review_responsible' => $validated['legal_review_responsible'],
                    'legal_review_reference' => $validated['legal_review_reference'],
                    'legal_reviewed_at' => $validated['legal_reviewed_at'],
                    'audesp_responsible_user_id' => $request->user()->id,
                ]),
            ];

            if ($profile) {
                $profile->update($values);
            } else {
                $version = (int) $municipality->regulatoryProfiles()
                    ->where('fiscal_year', $validated['fiscal_year'])
                    ->max('version') + 1;

                $profile = $municipality->regulatoryProfiles()->create([
                    'created_by' => $request->user()->id,
                    'fiscal_year' => $validated['fiscal_year'],
                    'version' => max(1, $version),
                    ...$values,
                ]);
            }

            foreach ($this->defaultInstruments((int) $validated['fiscal_year']) as $type => $instrument) {
                $profile->instruments()->firstOrCreate(
                    ['type' => $type],
                    [
                        'municipality_id' => $municipality->id,
                        'created_by' => $request->user()->id,
                        ...$instrument,
                    ],
                );
            }

            $diagnostic = $readiness->evaluate($profile->fresh('instruments'));
            if ($diagnostic['blockers'] !== []) {
                throw new \RuntimeException('A configuração gerada ainda possui pendências obrigatórias.');
            }

            $municipality->regulatoryProfiles()
                ->where('fiscal_year', $profile->fiscal_year)
                ->where('status', MunicipalRegulatoryProfile::STATUS_ACTIVE)
                ->update(['status' => MunicipalRegulatoryProfile::STATUS_ARCHIVED, 'updated_at' => now()]);
            $profile->update([
                'status' => MunicipalRegulatoryProfile::STATUS_ACTIVE,
                'activated_by' => $request->user()->id,
                'activated_at' => now(),
                'updated_by' => $request->user()->id,
            ]);

            $auditTrail->recordMunicipalityOperation($request, $municipality, 'municipal_onboarding_exercise_activated', [
                'profile_id' => $profile->id,
                'fiscal_year' => $profile->fiscal_year,
                'regulatory_version' => $profile->version,
                'previous_year_rcl' => $validated['previous_year_rcl'],
                'councilor_seats' => $validated['councilor_seats'],
            ]);

            return $profile;
        });

        $quota = ((float) $profile->previous_year_rcl * (float) $profile->individual_limit_percentage / 100) / max(1, (int) $profile->councilor_seats);

        return redirect()->route('municipal-onboarding.index')
            ->with('status', 'Exercício '.$profile->fiscal_year.' ativado. Cota estimada por vereador: R$ '.number_format($quota, 2, ',', '.').'.');
    }

    /** @return array<string, array<string, string>> */
    private function defaultInstruments(int $year): array
    {
        return collect([
            'organic_law',
            'internal_rules',
            'ppa',
            'ldo',
            'loa',
            'regulation',
        ])->mapWithKeys(fn (string $type) => [
            $type => [
                'title' => MunicipalNormativeInstrument::types()[$type],
                'reference' => 'A informar/'.$year,
                'effective_from' => $year.'-01-01',
                'effective_until' => $year.'-12-31',
                'notes' => 'Criado pelo assistente de implantação como pendência de conferência. Substitua a referência pelo ato municipal oficial antes da revisão final.',
            ],
        ])->all();
    }
}
