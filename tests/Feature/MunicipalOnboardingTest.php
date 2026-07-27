<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MunicipalOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_municipal_onboarding_checklist(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-onboarding.index'))
            ->assertOk()
            ->assertSee('Implantação municipal')
            ->assertSee('Ativar normas do exercício')
            ->assertSee('Convidar Câmara');
    }

    public function test_only_manager_can_activate_exercise_from_onboarding(): void
    {
        [$editor, $municipality] = $this->member(User::ROLE_EDITOR);

        $this->actingAs($editor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-onboarding.index'))
            ->assertForbidden();
    }

    public function test_manager_activates_organic_law_exercise_and_releases_legislative_portal(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $councilor = User::factory()->create();
        $municipality->users()->attach($councilor, [
            'role' => User::ROLE_COUNCILOR,
            'legislative_name' => 'Vereadora Teste',
            'legislative_party' => 'PSD',
            'legislative_term_start' => '2025-01-01',
            'legislative_term_end' => '2028-12-31',
        ]);
        $token = $this->submissionSession($municipality, "municipal-onboarding-activate-{$municipality->id}");

        $this->actingAs($manager)->post(route('municipal-onboarding.activate'), [
            '_submission_token' => $token,
            'fiscal_year' => 2027,
            'previous_year_rcl' => 200000000,
            'councilor_seats' => 13,
            'legal_review_responsible' => 'Procuradoria Municipal',
            'legal_review_reference' => 'Parecer 001/2027',
            'legal_reviewed_at' => today()->toDateString(),
        ])->assertRedirect(route('municipal-onboarding.index'))
            ->assertSessionHas('status');

        $profile = MunicipalRegulatoryProfile::query()->firstOrFail();
        $this->assertSame(MunicipalRegulatoryProfile::STATUS_ACTIVE, $profile->status);
        $this->assertSame(2027, $profile->fiscal_year);
        $this->assertSame('1.5500', $profile->individual_limit_percentage);
        $this->assertSame('50.0000', $profile->health_reserve_percentage);
        $this->assertSame(6, $profile->instruments()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'municipal_onboarding_exercise_activated']);

        $this->actingAs($councilor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.index'))
            ->assertOk()
            ->assertSee('R$ 238.461,54');
    }

    public function test_manager_invites_councilor_from_onboarding(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-onboarding.index'))
            ->assertOk()
            ->assertSee('Liberação dos vereadores')
            ->assertSee('Convidar vereador');

        $token = $this->submissionSession($municipality, 'municipality-invitation-create');

        $this->post(route('users.invitations.store'), [
            '_submission_token' => $token,
            'redirect_to' => 'onboarding',
            'email' => 'vereador@camara.test',
            'role' => User::ROLE_COUNCILOR,
            'legislative_name' => 'Vereador Câmara',
            'legislative_party' => 'PSD',
            'legislative_term_start' => '2025-01-01',
            'legislative_term_end' => '2028-12-31',
        ])->assertRedirect(route('municipal-onboarding.index'))
            ->assertSessionHas('invitation_link');

        $this->assertDatabaseHas('municipality_invitations', [
            'municipality_id' => $municipality->id,
            'email' => 'vereador@camara.test',
            'role' => User::ROLE_COUNCILOR,
            'legislative_name' => 'Vereador Câmara',
            'legislative_party' => 'PSD',
        ]);

        $this->get(route('municipal-onboarding.index'))
            ->assertOk()
            ->assertSee('Vereador Câmara');
    }

    /** @return array{User, Municipality} */
    private function member(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP']);
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
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
