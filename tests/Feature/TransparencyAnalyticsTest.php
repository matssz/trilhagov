<?php

namespace Tests\Feature;

use App\Models\ExecutionStage;
use App\Models\FinancialCommitment;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransparencyAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_recalculates_financial_and_operational_indicators_with_filters(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, [
            'reference' => 'EM-ANALITICA-2026',
            'fiscal_year' => 2026,
            'responsible_user_id' => $manager->id,
            'expected_amount' => 100000,
            'received_amount' => 80000,
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
            'accountability_deadline' => '2026-12-20',
        ]);
        $this->execution($municipality, $amendment, $manager, 60, 70000, 40000);
        $this->amendment($municipality, $manager, [
            'reference' => 'EM-FORA-2025',
            'fiscal_year' => 2025,
            'expected_amount' => 900000,
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('dashboard', ['year' => 2026]))
            ->assertOk()
            ->assertSee('Inteligência gerencial')
            ->assertSee('R$ 100.000,00')
            ->assertSee('R$ 80.000,00')
            ->assertSee('R$ 40.000,00')
            ->assertSee('60%')
            ->assertDontSee('EM-FORA-2025');
    }

    public function test_only_manager_can_publish_and_repeated_request_is_ignored(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $token = $this->sessionFor($municipality, "transparency-settings-{$municipality->id}");
        $payload = ['_submission_token' => $token, 'transparency_enabled' => 1];

        $this->actingAs($manager)
            ->patch(route('transparency.settings.update'), $payload)
            ->assertSessionHas('status');

        $municipality->refresh();
        $slug = $municipality->transparency_slug;
        $this->assertTrue($municipality->transparency_enabled);
        $this->assertNotEmpty($slug);
        $this->assertDatabaseHas('audit_logs', [
            'municipality_id' => $municipality->id,
            'action' => 'transparency_updated',
        ]);

        $this->patch(route('transparency.settings.update'), $payload)->assertSessionHas('warning');
        $this->assertSame($slug, $municipality->fresh()->transparency_slug);

        [$editor, $editorMunicipality] = $this->memberWithMunicipality(User::ROLE_EDITOR);
        $editorToken = $this->sessionFor($editorMunicipality, "transparency-settings-{$editorMunicipality->id}");
        $this->actingAs($editor)->patch(route('transparency.settings.update'), [
            '_submission_token' => $editorToken,
            'transparency_enabled' => 1,
        ])->assertForbidden();
    }

    public function test_public_portal_is_unavailable_until_enabled_and_hides_internal_data(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $municipality->update(['transparency_slug' => 'cidade-segura-abc123']);
        $amendment = $this->amendment($municipality, $manager, [
            'reference' => 'EM-PUBLICA-001',
            'responsible_user_id' => $manager->id,
            'expected_amount' => 500000,
            'received_amount' => 300000,
            'notes' => 'Observação estritamente interna.',
            'risk_level' => ParliamentaryAmendment::RISK_CRITICAL,
        ]);
        $this->execution($municipality, $amendment, $manager, 50, 250000, 100000, 'Fornecedor Sigiloso');

        $this->get(route('transparency.show', ['municipality' => $municipality->transparency_slug]))
            ->assertNotFound();

        $municipality->update(['transparency_enabled' => true, 'transparency_updated_at' => now()]);

        $this->get(route('transparency.show', ['municipality' => $municipality->transparency_slug]))
            ->assertOk()
            ->assertSee('Portal de transparência')
            ->assertSee('EM-PUBLICA-001')
            ->assertSee('R$ 500.000,00')
            ->assertSee('R$ 100.000,00')
            ->assertDontSee($manager->name)
            ->assertDontSee('Fornecedor Sigiloso')
            ->assertDontSee('Observação estritamente interna')
            ->assertDontSee('Crítico');
    }

    public function test_public_csv_respects_filters_and_does_not_expose_private_columns(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $municipality->update([
            'transparency_enabled' => true,
            'transparency_slug' => 'municipio-transparente-xyz789',
            'transparency_updated_at' => now(),
        ]);
        $published = $this->amendment($municipality, $manager, [
            'reference' => '=SUM(A1:A2)',
            'fiscal_year' => 2026,
            'expected_amount' => 150000,
            'received_amount' => 100000,
        ]);
        $this->execution($municipality, $published, $manager, 100, 90000, 80000, 'Fornecedor Privado');
        $this->amendment($municipality, $manager, ['reference' => 'EM-2025-OCULTA', 'fiscal_year' => 2025]);

        $response = $this->get(route('transparency.export', [
            'municipality' => $municipality->transparency_slug,
            'year' => 2026,
        ]));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString("'=SUM(A1:A2)", $content);
        $this->assertStringContainsString('80000,00', $content);
        $this->assertStringNotContainsString('EM-2025-OCULTA', $content);
        $this->assertStringNotContainsString('Fornecedor Privado', $content);
        $this->assertStringNotContainsString('Responsável operacional', $content);
        $this->assertStringNotContainsString('Risco', $content);
    }

    public function test_internal_csv_is_scoped_to_municipality_and_audited(): void
    {
        $this->get(route('reports.export'))->assertRedirect(route('login'));

        [$auditor, $municipality] = $this->memberWithMunicipality(User::ROLE_AUDITOR);
        $this->amendment($municipality, $auditor, ['reference' => 'EM-MUNICIPIO-CERTO']);
        [$otherUser, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $this->amendment($otherMunicipality, $otherUser, ['reference' => 'EM-OUTRO-MUNICIPIO']);

        $response = $this->actingAs($auditor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('reports.export'));

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('EM-MUNICIPIO-CERTO', $content);
        $this->assertStringNotContainsString('EM-OUTRO-MUNICIPIO', $content);
        $this->assertDatabaseHas('audit_logs', [
            'municipality_id' => $municipality->id,
            'user_id' => $auditor->id,
            'action' => 'report_exported',
        ]);

    }

    /** @return array{User, Municipality} */
    private function memberWithMunicipality(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }

    private function amendment(Municipality $municipality, User $user, array $attributes = []): ParliamentaryAmendment
    {
        return ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($user, 'creator')
            ->create($attributes);
    }

    private function execution(
        Municipality $municipality,
        ParliamentaryAmendment $amendment,
        User $user,
        int $progress,
        float $committed,
        float $paid,
        string $supplier = 'Fornecedor Municipal',
    ): void {
        $stage = $amendment->executionStages()->create([
            'municipality_id' => $municipality->id,
            'responsible_user_id' => $user->id,
            'created_by' => $user->id,
            'title' => 'Execução principal',
            'status' => $progress === 100 ? ExecutionStage::STATUS_COMPLETED : ExecutionStage::STATUS_IN_PROGRESS,
            'progress_percentage' => $progress,
            'planned_amount' => $committed,
            'sort_order' => 10,
        ]);
        $commitment = $amendment->financialCommitments()->create([
            'municipality_id' => $municipality->id,
            'execution_stage_id' => $stage->id,
            'created_by' => $user->id,
            'commitment_number' => 'NE-'.$amendment->id,
            'supplier_name' => $supplier,
            'procurement_process' => 'PROC-'.$amendment->id,
            'object_description' => 'Execução do objeto da emenda.',
            'committed_amount' => $committed,
            'committed_at' => today(),
            'status' => FinancialCommitment::STATUS_ACTIVE,
        ]);
        $commitment->payments()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'created_by' => $user->id,
            'payment_reference' => 'OB-'.$amendment->id,
            'amount' => $paid,
            'paid_at' => today(),
        ]);
    }

    private function sessionFor(Municipality $municipality, string $scope): string
    {
        $token = (string) Str::uuid();
        $this->withSession([
            'active_municipality_id' => $municipality->id,
            'form_submission_tokens' => [$scope => [$token => now()->timestamp]],
        ]);

        return $token;
    }
}
