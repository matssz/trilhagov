<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\MunicipalSpecializedReport;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class MunicipalSpecializedReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_report_uses_active_local_rule_and_work_plan_classification(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $municipality->regulatoryProfiles()->create([
            'created_by' => $manager->id, 'activated_by' => $manager->id,
            'fiscal_year' => 2026, 'version' => 1, 'status' => 'active',
            'regime_status' => 'instituted', 'health_reserve_percentage' => 50,
            'health_reserve_method' => 'per_councilor', 'activated_at' => now(),
        ]);
        $health = $this->amendment($municipality, $manager, [
            'reference' => 'EM-2026-001', 'municipal_regulatory_profile_id' => $profile->id,
            'author_name' => 'Vereadora Ana', 'expected_amount' => 60000, 'received_amount' => 50000,
        ]);
        $this->workPlan($health, $manager, true, 60000);
        $other = $this->amendment($municipality, $manager, [
            'reference' => 'EM-2026-002', 'municipal_regulatory_profile_id' => $profile->id,
            'author_name' => 'Vereadora Ana', 'expected_amount' => 40000,
        ]);
        $this->workPlan($other, $manager, false, 40000);

        $response = $this->actingAs($manager)->post(route('specialized-reports.store'), [
            '_submission_token' => $this->token($municipality, 'specialized-report-create'),
            'report_type' => MunicipalSpecializedReport::TYPE_HEALTH,
            'fiscal_year' => 2026, 'reference_month' => 7,
        ]);

        $report = MunicipalSpecializedReport::firstOrFail();
        $response->assertRedirect(route('specialized-reports.show', $report));
        $this->assertSame('compliant', $report->snapshot['summary']['status']);
        $this->assertEquals(50000.0, $report->snapshot['summary']['required_health_reserve']);
        $this->assertEquals(60000.0, $report->snapshot['summary']['reserved_for_health']);
        $this->assertEquals(0.0, $report->snapshot['summary']['shortfall']);
        $this->assertEquals(0.0, $report->snapshot['summary']['asps_eligible_amount']);
        $this->assertSame(1, $report->snapshot['summary']['asps_pending_assessment']);
        $this->assertSame(64, strlen($report->snapshot_sha256));
        $this->assertDatabaseHas('audit_logs', ['action' => 'specialized_report_created']);
        $this->get(route('specialized-reports.show', $report))->assertOk()->assertSee('RMS-2026-07-V1')->assertSee('Reserva por autor')->assertSee('ASPS elegível');
        $this->get(route('specialized-reports.csv', $report))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get(route('specialized-reports.pdf', $report))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_divergence_report_flags_financial_and_physical_inconsistencies(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, [
            'status' => ParliamentaryAmendment::STATUS_COMPLETED,
            'received_amount' => 50000, 'received_at' => '2026-06-01',
        ]);
        $this->workPlan($amendment, $manager, false, 80000);
        $amendment->executionStages()->create([
            'municipality_id' => $municipality->id, 'created_by' => $manager->id,
            'title' => 'Entrega parcial', 'description' => 'Primeira medição',
            'status' => 'in_progress', 'progress_percentage' => 25,
        ]);

        $this->actingAs($manager)->post(route('specialized-reports.store'), [
            '_submission_token' => $this->token($municipality, 'specialized-report-create'),
            'report_type' => MunicipalSpecializedReport::TYPE_DIVERGENCES,
            'fiscal_year' => 2026, 'reference_month' => 7, 'difference_threshold' => 15,
        ])->assertRedirect();

        $snapshot = MunicipalSpecializedReport::firstOrFail()->snapshot;
        $this->assertSame(1, $snapshot['summary']['divergent_amendments']);
        $this->assertSame(1, $snapshot['summary']['critical_amendments']);
        $codes = collect($snapshot['rows'][0]['divergences'])->pluck('code');
        $this->assertTrue($codes->contains('planning_amount'));
        $this->assertTrue($codes->contains('completed_without_physical_delivery'));
    }

    public function test_manager_issues_immutable_report_and_next_preparation_is_a_new_version(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $this->amendment($municipality, $manager);
        $this->actingAs($manager)->post(route('specialized-reports.store'), [
            '_submission_token' => $this->token($municipality, 'specialized-report-create'),
            'report_type' => MunicipalSpecializedReport::TYPE_ANNUAL_DOSSIER,
            'fiscal_year' => 2026, 'reference_month' => 7,
        ]);
        $report = MunicipalSpecializedReport::firstOrFail();
        $this->post(route('specialized-reports.issue', $report), [
            '_submission_token' => $this->token($municipality, "specialized-report-issue-{$report->id}"),
            'confirm_snapshot' => 1,
        ])->assertSessionHas('status');

        $this->assertSame(MunicipalSpecializedReport::STATUS_ISSUED, $report->fresh()->status);
        $this->post(route('specialized-reports.store'), [
            '_submission_token' => $this->token($municipality, 'specialized-report-create'),
            'report_type' => MunicipalSpecializedReport::TYPE_ANNUAL_DOSSIER,
            'fiscal_year' => 2026, 'reference_month' => 7,
        ])->assertRedirect();
        $this->assertDatabaseHas('municipal_specialized_reports', ['report_type' => 'annual_dossier', 'version' => 2]);

        $this->expectException(LogicException::class);
        $report->fresh()->update(['management_notes' => 'Alteração posterior indevida.']);
    }

    public function test_permissions_and_tenant_scope_are_enforced(): void
    {
        [$editor, $municipality] = $this->member(User::ROLE_EDITOR);
        $this->amendment($municipality, $editor);
        $this->actingAs($editor)->post(route('specialized-reports.store'), [
            '_submission_token' => $this->token($municipality, 'specialized-report-create'),
            'report_type' => MunicipalSpecializedReport::TYPE_DIVERGENCES,
            'fiscal_year' => 2026, 'reference_month' => 7,
        ]);
        $report = MunicipalSpecializedReport::firstOrFail();
        $this->post(route('specialized-reports.issue', $report))->assertForbidden();

        [$viewer, $otherMunicipality] = $this->member(User::ROLE_VIEWER);
        $this->actingAs($viewer)->withSession(['active_municipality_id' => $otherMunicipality->id]);
        $this->get(route('specialized-reports.index'))->assertOk();
        $this->post(route('specialized-reports.store'))->assertForbidden();
        $this->get(route('specialized-reports.show', $report))->assertNotFound();
    }

    private function member(string $role): array
    {
        $user = User::factory()->create();
        $sequence = Municipality::count() + 1;
        $municipality = Municipality::factory()->create([
            'state' => 'SP', 'ibge_code' => (string) (3541000 + $sequence),
            'cnpj' => sprintf('22345678%06d', $sequence), 'transparency_enabled' => true,
        ]);
        $municipality->users()->attach($user->id, ['role' => $role]);

        return [$user, $municipality];
    }

    /** @param array<string, mixed> $attributes */
    private function amendment(Municipality $municipality, User $user, array $attributes = []): ParliamentaryAmendment
    {
        return ParliamentaryAmendment::factory()->create(array_merge([
            'municipality_id' => $municipality->id, 'created_by' => $user->id,
            'reference' => 'EM-2026-010', 'fiscal_year' => 2026,
            'government_sphere' => 'municipal', 'authorship_type' => 'individual',
            'expected_amount' => 100000, 'responsible_department' => 'Secretaria Municipal de Saúde',
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
        ], $attributes));
    }

    private function workPlan(ParliamentaryAmendment $amendment, User $user, bool $health, float $planned): void
    {
        $plan = $amendment->municipalWorkPlan()->create([
            'municipality_id' => $amendment->municipality_id, 'created_by' => $user->id,
            'updated_by' => $user->id, 'status' => 'approved', 'revision_number' => 1,
            'health_related' => $health, 'health_reserve_verified' => $health,
        ]);
        $plan->stages()->create([
            'municipality_id' => $amendment->municipality_id,
            'parliamentary_amendment_id' => $amendment->id, 'created_by' => $user->id,
            'title' => 'Execução', 'physical_delivery' => 'Entrega prevista', 'planned_amount' => $planned,
            'planned_start_at' => '2026-03-01', 'planned_end_at' => '2026-10-31', 'sort_order' => 1,
        ]);
    }

    private function token(Municipality $municipality, string $scope): string
    {
        $token = (string) Str::uuid();
        $tokens = session('form_submission_tokens', []);
        $tokens[$scope] = [$token => now()->timestamp];
        $this->withSession(['active_municipality_id' => $municipality->id, 'form_submission_tokens' => $tokens]);

        return $token;
    }
}
