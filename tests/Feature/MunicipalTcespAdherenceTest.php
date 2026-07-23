<?php

namespace Tests\Feature;

use App\Models\AmendmentComplianceReview;
use App\Models\Municipality;
use App\Models\MunicipalNormativeInstrument;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\TcespComplianceFramework;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MunicipalTcespAdherenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_consults_municipal_tcesp_adherence_map(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $this->activeProfile($municipality, $manager);
        $amendment = $this->amendment($municipality, $manager);
        $amendment->complianceReviews()->create([
            'municipality_id' => $municipality->id,
            'framework_version' => TcespComplianceFramework::VERSION,
            'rule_code' => 'NORM-01',
            'status' => AmendmentComplianceReview::STATUS_COMPLIANT,
            'evidence_notes' => 'Lei Orgânica e LDO conferidas pela Procuradoria.',
            'reviewed_by' => $manager->id,
            'reviewed_at' => now(),
        ]);
        $amendment->complianceReviews()->create([
            'municipality_id' => $municipality->id,
            'framework_version' => TcespComplianceFramework::VERSION,
            'rule_code' => 'ORC-01',
            'status' => AmendmentComplianceReview::STATUS_NON_COMPLIANT,
            'evidence_notes' => 'Objeto ainda genérico para aferição de entrega.',
            'reviewed_by' => $manager->id,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-tcesp-adherence.index', ['ano' => 2027]))
            ->assertOk()
            ->assertSee('Aderência Municipal')
            ->assertSee('Norma local rastreável')
            ->assertSee('Emendas municipais do exercício')
            ->assertSee('NORM-01')
            ->assertSee('ORC-01')
            ->assertSee('Objeto preciso e não genérico')
            ->assertSee('Revisar itens pendentes do manual')
            ->assertSee($amendment->reference);
    }

    public function test_viewer_can_consult_without_write_action_and_non_tcesp_municipality_is_blocked(): void
    {
        [$viewer, $municipality] = $this->member(User::ROLE_VIEWER);

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-tcesp-adherence.index', ['ano' => 2027]))
            ->assertOk()
            ->assertSee('Aderência Municipal')
            ->assertDontSee('Nova emenda');

        $municipality->update(['state' => 'MG']);

        $this->get(route('municipal-tcesp-adherence.index'))->assertNotFound();
    }

    public function test_sidebar_exposes_adherence_only_for_tcesp_scope(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Aderência TCESP');

        $municipality->update(['state' => 'MG']);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Aderência TCESP');
    }

    /** @return array{User, Municipality} */
    private function member(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP', 'ibge_code' => '3522307']);
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }

    private function activeProfile(Municipality $municipality, User $user): MunicipalRegulatoryProfile
    {
        $profile = $municipality->regulatoryProfiles()->create([
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'activated_by' => $user->id,
            'fiscal_year' => 2027,
            'version' => 1,
            'status' => MunicipalRegulatoryProfile::STATUS_ACTIVE,
            'regime_status' => MunicipalRegulatoryProfile::REGIME_INSTITUTED,
            'previous_year_rcl' => 100000000,
            'individual_limit_percentage' => 1.55,
            'councilor_seats' => 10,
            'health_reserve_percentage' => 50,
            'health_reserve_method' => 'global',
            'generic_amendments_prohibited' => true,
            'prior_technical_review_required' => true,
            'work_plan_required' => true,
            'pca_check_required' => true,
            'impediment_notice_days' => 30,
            'impediment_correction_days' => 30,
            'publication_business_days' => 1,
            'document_retention_years' => 5,
            'bank_traceability_rule' => 'individual_account',
            'audesp_registration_status' => 'ready',
            'audesp_responsible_user_id' => $user->id,
            'legal_review_responsible' => 'Procuradoria Municipal',
            'legal_review_reference' => 'Parecer Jurídico 12/2027',
            'legal_reviewed_at' => today(),
            'activated_at' => now(),
        ]);

        foreach (['organic_law', 'internal_rules', 'ppa', 'ldo', 'loa', 'regulation'] as $type) {
            $profile->instruments()->create([
                'municipality_id' => $municipality->id,
                'created_by' => $user->id,
                'type' => $type,
                'title' => MunicipalNormativeInstrument::types()[$type],
                'reference' => strtoupper($type).'/2027',
            ]);
        }

        return $profile->fresh('instruments');
    }

    private function amendment(Municipality $municipality, User $user): ParliamentaryAmendment
    {
        return ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($user, 'creator')
            ->create([
                'reference' => 'EM-2027-MUN-001',
                'fiscal_year' => 2027,
                'government_sphere' => 'municipal',
                'transfer_type' => 'direct_execution',
                'object' => 'Modernização de unidade municipal de saúde com equipamentos permanentes.',
            ]);
    }
}
