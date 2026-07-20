<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\IntegrityAlertService;
use App\Services\MunicipalRuleApplicationService;
use App\Services\MunicipalWorkItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MunicipalRuleApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_municipal_amendment_is_bound_to_active_rules(): void
    {
        [$manager, $municipality] = $this->context();
        $profile = $this->activeProfile($municipality, $manager);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.store'), $this->payload('amendment-create'))
            ->assertSessionHasNoErrors();

        $amendment = $municipality->amendments()->firstOrFail();
        $this->assertSame($profile->id, $amendment->municipal_regulatory_profile_id);
        $this->get(route('emendas.show', $amendment))
            ->assertOk()
            ->assertSee('Regra municipal')
            ->assertSee('2026/v1');
    }

    public function test_minimum_count_and_author_ceiling_are_enforced_with_human_messages(): void
    {
        [$manager, $municipality] = $this->context();
        $profile = $this->activeProfile($municipality, $manager, [
            'amendments_per_councilor_limit' => 1,
        ]);
        ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($manager, 'creator')
            ->create([
                'municipal_regulatory_profile_id' => $profile->id,
                'fiscal_year' => 2026,
                'government_sphere' => 'municipal',
                'authorship_type' => 'individual',
                'author_name' => 'Vereadora Ana Souza',
                'expected_amount' => 60000,
            ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.store'), $this->payload('amendment-create', [
                'reference' => 'EM-2026-002',
                'expected_amount' => 50000,
            ]))
            ->assertSessionHasErrors(['author_name', 'expected_amount']);

        $this->assertDatabaseCount('parliamentary_amendments', 1);

        $this->post(route('emendas.store'), $this->payload('amendment-create', [
            'reference' => 'EM-2026-003',
            'author_name' => 'Vereador Bruno Lima',
            'expected_amount' => 40000,
        ]))->assertSessionHasErrors('expected_amount');
    }

    public function test_imported_violation_generates_integrity_alert_and_work_item(): void
    {
        [$manager, $municipality] = $this->context();
        $profile = $this->activeProfile($municipality, $manager);
        $amendment = ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($manager, 'creator')
            ->create([
                'municipal_regulatory_profile_id' => $profile->id,
                'fiscal_year' => 2026,
                'government_sphere' => 'municipal',
                'authorship_type' => 'individual',
                'expected_amount' => 40000,
            ]);

        app(IntegrityAlertService::class)->sync($municipality);
        app(MunicipalWorkItemService::class)->synchronize($municipality);

        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => 'normative:minimum_amount',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'parliamentary_amendment_id' => $amendment->id,
            'source_key' => "amendment:{$amendment->id}:normative:minimum_amount",
            'category' => 'normative',
        ]);
    }

    public function test_impediment_uses_local_correction_deadline_and_same_rules_version(): void
    {
        [$manager, $municipality] = $this->context();
        $profile = $this->activeProfile($municipality, $manager, [
            'impediment_notice_days' => 6,
            'impediment_correction_days' => 18,
        ]);
        $amendment = ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($manager, 'creator')
            ->create([
                'municipal_regulatory_profile_id' => $profile->id,
                'fiscal_year' => 2026,
                'government_sphere' => 'municipal',
                'expected_amount' => 60000,
            ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.impediments.store', $amendment), $this->impedimentPayload($amendment))
            ->assertSessionHasNoErrors();

        $impediment = $amendment->technicalImpediments()->firstOrFail();
        $this->assertSame($profile->id, $impediment->municipal_regulatory_profile_id);
        $this->assertSame(today()->addDays(6)->toDateString(), $impediment->communication_due_at->toDateString());
        $this->assertSame(today()->addDays(18)->toDateString(), $impediment->resolution_due_at->toDateString());

        app(IntegrityAlertService::class)->sync($municipality);
        app(MunicipalWorkItemService::class)->synchronize($municipality);
        $this->assertDatabaseHas('integrity_alerts', [
            'alert_key' => "deadline:impediment-communication:{$impediment->id}",
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:technical-impediment:{$impediment->id}:communication",
        ]);

        $updateToken = (string) Str::uuid();
        $this->withSession([
            'form_submission_tokens' => ["technical-impediment-update-{$impediment->id}" => [$updateToken => now()->timestamp]],
        ]);
        $this->patch(route('emendas.impediments.update', [$amendment, $impediment]), [
            '_submission_token' => $updateToken,
            'nature' => 'under_analysis',
            'status' => 'identified',
            'assigned_user_id' => $manager->id,
            'identified_at' => today()->toDateString(),
            'resolution_due_at' => today()->addDays(18)->toDateString(),
            'communicated_at' => today()->toDateString(),
            'communication_reference' => 'OFÍCIO 42/2026',
        ])->assertSessionHasNoErrors();
        $this->assertSame('OFÍCIO 42/2026', $impediment->fresh()->communication_reference);
        app(IntegrityAlertService::class)->sync($municipality->fresh());
        app(MunicipalWorkItemService::class)->synchronize($municipality->fresh());
        $this->assertDatabaseHas('integrity_alerts', [
            'alert_key' => "deadline:impediment-communication:{$impediment->id}",
            'status' => 'resolved',
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:technical-impediment:{$impediment->id}:communication",
            'status' => 'completed',
        ]);
    }

    public function test_bound_amendment_cannot_change_year_or_sphere(): void
    {
        [$manager, $municipality] = $this->context();
        $profile = $this->activeProfile($municipality, $manager);
        $amendment = ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($manager, 'creator')
            ->create([
                'municipal_regulatory_profile_id' => $profile->id,
                'fiscal_year' => 2026,
                'government_sphere' => 'municipal',
                'expected_amount' => 60000,
            ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->put(route('emendas.update', $amendment), $this->payload("amendment-update-{$amendment->id}", [
                'fiscal_year' => 2027,
            ]))
            ->assertSessionHasErrors('fiscal_year');

        $this->assertSame(2026, $amendment->fresh()->fiscal_year);
        $this->assertSame($profile->id, $amendment->fresh()->municipal_regulatory_profile_id);
    }

    public function test_health_reserve_is_consolidated_from_classified_work_plans(): void
    {
        [$manager, $municipality] = $this->context();
        $profile = $this->activeProfile($municipality, $manager, [
            'health_reserve_percentage' => 50,
            'health_reserve_method' => 'global',
        ]);
        foreach ([60000 => true, 40000 => false] as $amount => $healthRelated) {
            $amendment = ParliamentaryAmendment::factory()
                ->for($municipality)
                ->for($manager, 'creator')
                ->create([
                    'municipal_regulatory_profile_id' => $profile->id,
                    'fiscal_year' => 2026,
                    'government_sphere' => 'municipal',
                    'expected_amount' => $amount,
                ]);
            $amendment->municipalWorkPlan()->create([
                'municipality_id' => $municipality->id,
                'created_by' => $manager->id,
                'health_related' => $healthRelated,
                'health_reserve_verified' => $healthRelated,
            ]);
        }

        $portfolio = app(MunicipalRuleApplicationService::class)->portfolioAssessment($profile);

        $this->assertSame(100000.0, $portfolio['total']);
        $this->assertSame(60000.0, $portfolio['health_total']);
        $this->assertSame(50000.0, $portfolio['health_required']);
        $this->assertSame(0.0, $portfolio['shortfall']);
        $this->assertSame('compliant', $portfolio['status']);
    }

    public function test_bank_account_exception_requires_direct_execution_and_accounting_codes(): void
    {
        [$manager, $municipality] = $this->context();
        $this->activeProfile($municipality, $manager);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.store'), $this->payload('amendment-create', [
                'transfer_type' => 'special',
                'bank_tracking_type' => 'municipal_direct_codes',
            ]))
            ->assertSessionHasErrors('bank_tracking_type');

        $this->post(route('emendas.store'), $this->payload('amendment-create', [
            'reference' => 'EM-2026-CONTA',
            'bank_tracking_type' => 'specific_account',
            'bank_account_number' => null,
        ]))->assertSessionHasErrors('bank_account_number');
    }

    /** @return array{User, Municipality} */
    private function context(): array
    {
        $manager = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP']);
        $municipality->users()->attach($manager, ['role' => User::ROLE_MANAGER]);

        return [$manager, $municipality];
    }

    private function activeProfile(Municipality $municipality, User $manager, array $overrides = []): MunicipalRegulatoryProfile
    {
        return $municipality->regulatoryProfiles()->create([
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
            'activated_by' => $manager->id,
            'fiscal_year' => 2026,
            'version' => 1,
            'status' => MunicipalRegulatoryProfile::STATUS_ACTIVE,
            'regime_status' => MunicipalRegulatoryProfile::REGIME_INSTITUTED,
            'previous_year_rcl' => 10000000,
            'individual_limit_percentage' => 1,
            'minimum_amendment_amount' => 50000,
            'amendments_per_councilor_limit' => 5,
            'health_reserve_percentage' => 50,
            'health_reserve_method' => 'global',
            'impediment_correction_days' => 15,
            'impediment_notice_days' => 5,
            'activated_at' => now(),
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $scope, array $overrides = []): array
    {
        $token = (string) Str::uuid();
        $this->withSession(['form_submission_tokens' => [$scope => [$token => now()->timestamp]]]);

        return [
            '_submission_token' => $token,
            'reference' => 'EM-2026-001',
            'fiscal_year' => 2026,
            'government_sphere' => 'municipal',
            'authorship_type' => 'individual',
            'transfer_type' => 'direct_execution',
            'author_name' => 'Vereadora Ana Souza',
            'author_party' => 'PSD',
            'object' => 'Aquisição de equipamentos para unidade municipal de saúde',
            'expense_destination' => 'investment',
            'responsible_department' => 'Secretaria Municipal de Saúde',
            'beneficiary_location' => 'Município de Teste',
            'responsible_user_id' => auth()->id(),
            'transferegov_code' => null,
            'legal_instrument' => null,
            'administrative_process' => 'PROC-2026-001',
            'bank_tracking_type' => 'municipal_direct_codes',
            'bank_account_number' => null,
            'funding_source_code' => '08',
            'application_code_fixed' => '100.0000',
            'application_code_variable' => '001',
            'expected_amount' => 60000,
            'received_amount' => null,
            'status' => ParliamentaryAmendment::STATUS_IDENTIFIED,
            'indicated_at' => '2026-06-01',
            'received_at' => null,
            'communication_deadline' => '2026-08-01',
            'communication_completed_at' => null,
            'execution_deadline' => '2027-06-30',
            'application_deadline' => '2027-06-30',
            'execution_completed_at' => null,
            'accountability_deadline' => '2027-12-31',
            'accountability_completed_at' => null,
            'notes' => null,
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function impedimentPayload(ParliamentaryAmendment $amendment): array
    {
        $token = (string) Str::uuid();
        $this->withSession([
            'form_submission_tokens' => ["technical-impediment-create-{$amendment->id}" => [$token => now()->timestamp]],
        ]);

        return [
            '_submission_token' => $token,
            'category' => 'engineering',
            'nature' => 'under_analysis',
            'title' => 'Projeto básico incompleto',
            'description' => 'O projeto não contém todos os elementos técnicos.',
            'impact' => 'A contratação não pode prosseguir até o saneamento.',
            'assigned_user_id' => auth()->id(),
            'identified_at' => today()->toDateString(),
            'resolution_due_at' => null,
        ];
    }
}
