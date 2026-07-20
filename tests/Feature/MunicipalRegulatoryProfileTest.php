<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\MunicipalNormativeInstrument;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class MunicipalRegulatoryProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_member_can_consult_but_only_manager_can_change_municipal_rules(): void
    {
        [$editor, $municipality] = $this->member(User::ROLE_EDITOR);
        $profile = $this->profile($municipality, $editor);

        $this->actingAs($editor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-rules.index'))
            ->assertOk()
            ->assertSee('Normas municipais')
            ->assertSee((string) $profile->fiscal_year);

        $this->post(route('municipal-rules.store'), [
            '_submission_token' => (string) Str::uuid(),
            'fiscal_year' => 2027,
        ])->assertForbidden();
    }

    public function test_manager_starts_only_one_draft_per_year_even_when_request_is_repeated(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $token = $this->submissionSession($municipality, 'municipal-rules-create');

        $this->actingAs($manager)->post(route('municipal-rules.store'), [
            '_submission_token' => $token,
            'fiscal_year' => 2027,
        ])->assertRedirect();

        $this->post(route('municipal-rules.store'), [
            '_submission_token' => $token,
            'fiscal_year' => 2027,
        ])->assertSessionHas('warning');

        $this->assertDatabaseCount('municipal_regulatory_profiles', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'municipal_rules_created']);
    }

    public function test_incomplete_configuration_cannot_be_activated(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('municipal-rules.activate', $profile))
            ->assertSessionHasErrors('activation');

        $this->assertSame(MunicipalRegulatoryProfile::STATUS_DRAFT, $profile->fresh()->status);
    }

    public function test_manager_can_activate_a_complete_review_and_it_becomes_immutable(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->completeProfile($municipality, $manager);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('municipal-rules.activate', $profile))
            ->assertSessionHas('status');

        $profile->refresh();
        $this->assertSame(MunicipalRegulatoryProfile::STATUS_ACTIVE, $profile->status);
        $this->assertNotNull($profile->activated_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'municipal_rules_activated']);

        $this->expectException(LogicException::class);
        $profile->update(['notes' => 'Tentativa de alteração indevida']);
    }

    public function test_revision_copies_parameters_and_instruments_without_changing_active_version(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $source = $this->completeProfile($municipality, $manager);
        $source->update(['status' => MunicipalRegulatoryProfile::STATUS_ACTIVE, 'activated_at' => now()]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('municipal-rules.revise', $source))
            ->assertRedirect();

        $copy = MunicipalRegulatoryProfile::query()->where('status', MunicipalRegulatoryProfile::STATUS_DRAFT)->firstOrFail();
        $this->assertSame(2, $copy->version);
        $this->assertSame($source->individual_limit_percentage, $copy->individual_limit_percentage);
        $this->assertSame($source->instruments()->count(), $copy->instruments()->count());
        $this->assertSame(MunicipalRegulatoryProfile::STATUS_ACTIVE, $source->fresh()->status);
    }

    public function test_profile_and_instrument_ids_from_another_municipality_are_not_accessible(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        [$otherManager, $otherMunicipality] = $this->member(User::ROLE_MANAGER);
        $foreignProfile = $this->profile($otherMunicipality, $otherManager);
        $foreignInstrument = $this->instrument($foreignProfile, $otherManager, 'organic_law');

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('municipal-rules.activate', $foreignProfile))
            ->assertNotFound();

        $this->delete(route('municipal-rules.instruments.destroy', [$foreignProfile, $foreignInstrument]))
            ->assertNotFound();
    }

    /** @return array{User, Municipality} */
    private function member(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP']);
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }

    private function profile(Municipality $municipality, User $user): MunicipalRegulatoryProfile
    {
        return $municipality->regulatoryProfiles()->create([
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'fiscal_year' => 2026,
            'version' => 1,
        ]);
    }

    private function completeProfile(Municipality $municipality, User $user): MunicipalRegulatoryProfile
    {
        $profile = $this->profile($municipality, $user);
        $profile->update([
            'regime_status' => MunicipalRegulatoryProfile::REGIME_INSTITUTED,
            'previous_year_rcl' => 100000000,
            'individual_limit_percentage' => 1.55,
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
            'legal_review_reference' => 'Parecer Jurídico 12/2026',
            'legal_reviewed_at' => today(),
        ]);
        foreach (['organic_law', 'internal_rules', 'ppa', 'ldo', 'loa', 'regulation'] as $type) {
            $this->instrument($profile, $user, $type);
        }

        return $profile->fresh('instruments');
    }

    private function instrument(MunicipalRegulatoryProfile $profile, User $user, string $type): MunicipalNormativeInstrument
    {
        return $profile->instruments()->create([
            'municipality_id' => $profile->municipality_id,
            'created_by' => $user->id,
            'type' => $type,
            'title' => MunicipalNormativeInstrument::types()[$type],
            'reference' => strtoupper($type).'/2026',
        ]);
    }

    private function submissionSession(Municipality $municipality, string $scope): string
    {
        $token = (string) Str::uuid();
        $this->withSession([
            'active_municipality_id' => $municipality->id,
            'form_submission_tokens' => [$scope => [$token => now()->timestamp]],
        ]);

        return $token;
    }
}
