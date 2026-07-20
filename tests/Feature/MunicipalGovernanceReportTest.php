<?php

namespace Tests\Feature;

use App\Models\AmendmentComplianceReview;
use App\Models\MunicipalGovernanceReport;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\TcespComplianceFramework;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class MunicipalGovernanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_prepares_monthly_snapshot_with_financial_and_control_data(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager);
        $commitment = $amendment->financialCommitments()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $manager->id,
            'commitment_number' => '2026NE0042',
            'supplier_name' => 'Fornecedor Municipal Ltda',
            'procurement_process' => 'PE 14/2026',
            'object_description' => 'Equipamentos da unidade de saúde.',
            'committed_amount' => 60000,
            'committed_at' => '2026-07-05',
            'status' => 'active',
        ]);
        $liquidation = $commitment->liquidations()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'created_by' => $manager->id,
            'liquidation_reference' => '2026NL0018',
            'amount' => 45000,
            'liquidated_at' => '2026-07-12',
            'supporting_document' => 'NF 991',
            'acceptance_reference' => 'Ateste 31/2026',
        ]);
        $commitment->payments()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'financial_liquidation_id' => $liquidation->id,
            'created_by' => $manager->id,
            'payment_reference' => '2026OB0033',
            'amount' => 40000,
            'paid_at' => '2026-07-18',
        ]);
        foreach (['ORC-02', 'CON-02', 'TRA-01', 'TRA-02', 'TRA-03'] as $code) {
            $amendment->complianceReviews()->create([
                'municipality_id' => $municipality->id,
                'framework_version' => TcespComplianceFramework::VERSION,
                'rule_code' => $code,
                'status' => AmendmentComplianceReview::STATUS_COMPLIANT,
                'evidence_notes' => 'Evidência registrada no processo municipal.',
                'reviewed_by' => $manager->id,
                'reviewed_at' => now(),
            ]);
        }
        $amendment->municipalWorkPlan()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
            'status' => 'approved',
            'revision_number' => 1,
            'beneficiary_type' => 'municipal_body',
            'beneficiary_name' => 'Secretaria Municipal de Saúde',
            'object_description' => $amendment->object,
            'public_need' => 'Ampliar o atendimento municipal.',
            'physical_target' => 'Uma unidade modernizada.',
            'finalistic_target' => 'Reduzir a fila da atenção básica.',
            'budget_program' => 'Saúde perto de você',
            'budget_action' => 'Modernização das UBS',
            'application_plan' => 'Aquisição dos equipamentos.',
            'cost_memory' => 'Pesquisa de preços juntada.',
            'approved_at' => now(),
        ]);
        $amendment->audespRegistration()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $manager->id,
            'scope' => 'M', 'amendment_type' => 2, 'legal_basis' => 'Lei',
            'proponent_name' => $amendment->author_name, 'amendment_number' => $amendment->reference,
            'amendment_year' => 2026, 'object' => $amendment->object, 'purpose' => 'Atenção básica.',
            'government_function' => '10', 'government_subfunctions' => ['301'],
            'destination' => 'C', 'bank_account_opened' => false, 'application_code' => '8001',
            'prepared_at' => now(),
        ]);

        $token = $this->token($municipality, 'governance-report-create');
        $response = $this->actingAs($manager)->post(route('governance-reports.store'), [
            '_submission_token' => $token,
            'fiscal_year' => 2026,
            'reference_month' => 7,
        ]);

        $report = MunicipalGovernanceReport::firstOrFail();
        $response->assertRedirect(route('governance-reports.show', $report));
        $this->assertEquals(100000.0, $report->snapshot['totals']['received']);
        $this->assertEquals(60000.0, $report->snapshot['totals']['committed']);
        $this->assertEquals(45000.0, $report->snapshot['totals']['liquidated']);
        $this->assertEquals(40000.0, $report->snapshot['totals']['paid']);
        $this->assertEquals(60000.0, $report->snapshot['totals']['balance']);
        $this->assertSame(64, strlen($report->snapshot_sha256));
        $this->assertSame('controlled', collect($report->snapshot['control_matrix'])->firstWhere('key', 'budget')['status']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'governance_report_created']);

        $this->get(route('governance-reports.show', $report))->assertOk()->assertSee('RGM-2026-07-V1')->assertSee('Matriz de acompanhamento');
        $this->get(route('governance-reports.csv', $report))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get(route('governance-reports.pdf', $report))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_only_manager_issues_an_immutable_version_and_next_report_becomes_revision(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $this->amendment($municipality, $manager);
        $createToken = $this->token($municipality, 'governance-report-create');
        $this->actingAs($manager)->post(route('governance-reports.store'), [
            '_submission_token' => $createToken, 'fiscal_year' => 2026, 'reference_month' => 7,
        ]);
        $report = MunicipalGovernanceReport::firstOrFail();
        $issueToken = $this->token($municipality, "governance-report-issue-{$report->id}");
        $this->post(route('governance-reports.issue', $report), [
            '_submission_token' => $issueToken, 'confirm_snapshot' => 1,
        ])->assertSessionHas('status');

        $this->assertSame(MunicipalGovernanceReport::STATUS_ISSUED, $report->fresh()->status);
        $this->assertNotNull($report->fresh()->issued_at);
        $revisionToken = $this->token($municipality, 'governance-report-create');
        $this->post(route('governance-reports.store'), [
            '_submission_token' => $revisionToken, 'fiscal_year' => 2026, 'reference_month' => 7,
        ])->assertRedirect();
        $this->assertDatabaseHas('municipal_governance_reports', [
            'municipality_id' => $municipality->id, 'fiscal_year' => 2026, 'reference_month' => 7, 'version' => 2,
        ]);

        $this->expectException(LogicException::class);
        $report->fresh()->update(['management_notes' => 'Tentativa posterior.']);
    }

    public function test_editor_can_refresh_but_cannot_issue_and_viewer_cannot_write(): void
    {
        [$editor, $municipality] = $this->member(User::ROLE_EDITOR);
        $this->amendment($municipality, $editor);
        $createToken = $this->token($municipality, 'governance-report-create');
        $this->actingAs($editor)->post(route('governance-reports.store'), [
            '_submission_token' => $createToken, 'fiscal_year' => 2026, 'reference_month' => 7,
        ]);
        $report = MunicipalGovernanceReport::firstOrFail();
        $refreshToken = $this->token($municipality, "governance-report-refresh-{$report->id}");
        $this->patch(route('governance-reports.refresh', $report), [
            '_submission_token' => $refreshToken, 'management_notes' => 'Revisão técnica de julho.',
        ])->assertSessionHas('status');
        $this->assertSame('Revisão técnica de julho.', $report->fresh()->management_notes);
        $this->post(route('governance-reports.issue', $report))->assertForbidden();

        [$viewer, $viewerMunicipality] = $this->member(User::ROLE_VIEWER);
        $this->actingAs($viewer)->withSession(['active_municipality_id' => $viewerMunicipality->id]);
        $this->get(route('governance-reports.index'))->assertOk();
        $this->post(route('governance-reports.store'))->assertForbidden();
        $this->get(route('governance-reports.show', $report))->assertNotFound();
    }

    public function test_module_is_not_exposed_outside_tcesp_municipal_scope(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $municipality->update(['state' => 'MG']);

        $this->actingAs($manager)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('governance-reports.index'))->assertNotFound();
    }

    private function member(string $role): array
    {
        $user = User::factory()->create();
        $sequence = Municipality::count() + 1;
        $municipality = Municipality::factory()->create([
            'state' => 'SP', 'ibge_code' => (string) (3540000 + $sequence), 'cnpj' => sprintf('12345678%06d', $sequence),
            'transparency_enabled' => true, 'transparency_updated_at' => now(),
        ]);
        $municipality->users()->attach($user->id, ['role' => $role]);

        return [$user, $municipality];
    }

    private function amendment(Municipality $municipality, User $user): ParliamentaryAmendment
    {
        return ParliamentaryAmendment::factory()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $user->id,
            'reference' => 'EM-2026-010',
            'fiscal_year' => 2026,
            'government_sphere' => 'municipal',
            'expected_amount' => 120000,
            'received_amount' => 100000,
            'received_at' => '2026-07-02',
            'responsible_department' => 'Secretaria Municipal de Saúde',
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
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
