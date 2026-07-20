<?php

namespace Tests\Feature;

use App\Models\AmendmentTransparencyEvent;
use App\Models\ExecutionStage;
use App\Models\FinancialCommitment;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\IntegrityAlertService;
use App\Services\MunicipalTransparencyTrail;
use App\Services\MunicipalWorkItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
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

    public function test_public_detail_contains_the_municipal_minimum_list_and_change_history(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $municipality->update([
            'transparency_enabled' => true,
            'transparency_slug' => 'municipio-artigo-tres',
            'transparency_updated_at' => now(),
        ]);
        $amendment = $this->amendment($municipality, $manager, [
            'reference' => 'EM-MUN-2026-010',
            'government_sphere' => 'municipal',
            'transfer_type' => 'direct_execution',
            'expense_destination' => 'investment',
            'beneficiary_location' => 'Distrito Municipal Central',
            'administrative_process' => 'PROC-ADM-010/2026',
            'legal_instrument' => 'Decreto Municipal 25/2026',
            'bank_tracking_type' => 'municipal_direct_codes',
            'funding_source_code' => '08',
            'application_code_fixed' => '100.0000',
            'application_code_variable' => '010',
            'application_deadline' => '2026-12-31',
            'notes' => 'Anotação reservada da controladoria.',
        ]);
        $plan = $amendment->municipalWorkPlan()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $manager->id,
            'beneficiary_name' => 'Secretaria Municipal de Saúde',
        ]);
        $plan->stages()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'created_by' => $manager->id,
            'title' => 'Aquisição dos equipamentos',
            'physical_delivery' => 'Entrega e instalação de dez equipamentos.',
            'planned_amount' => 100000,
            'planned_start_at' => '2026-08-01',
            'planned_end_at' => '2026-10-31',
        ]);
        app(MunicipalTransparencyTrail::class)->recordCreation($amendment);

        $this->get(route('transparency.detail', [$municipality->transparency_slug, $amendment]))
            ->assertOk()
            ->assertSee('EM-MUN-2026-010')
            ->assertSee('PROC-ADM-010/2026')
            ->assertSee('Decreto Municipal 25/2026')
            ->assertSee('Código de Aplicação Fixo')
            ->assertSee('100.0000')
            ->assertSee('Aquisição dos equipamentos')
            ->assertSee('Emenda cadastrada')
            ->assertDontSee('Anotação reservada da controladoria')
            ->assertDontSee($manager->name);
    }

    public function test_value_reduction_is_published_and_the_public_history_is_immutable(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, ['expected_amount' => 200000]);
        $before = $amendment->getOriginal();
        $amendment->update(['expected_amount' => 175000]);

        app(MunicipalTransparencyTrail::class)->recordAmendmentChanges($amendment, $before);

        $event = AmendmentTransparencyEvent::query()->firstOrFail();
        $this->assertSame('value_reduced', $event->event_type);
        $this->assertSame('R$ 200.000,00', $event->changes['Valor autorizado']['anterior']);
        $this->assertSame('R$ 175.000,00', $event->changes['Valor autorizado']['atual']);

        $this->expectException(LogicException::class);
        $event->update(['title' => 'Histórico adulterado']);
    }

    public function test_incomplete_municipal_publication_generates_alert_and_work_action(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $municipality->update(['state' => 'SP', 'ibge_code' => '3500105']);
        $amendment = $this->amendment($municipality, $manager, [
            'government_sphere' => 'municipal',
            'transfer_type' => 'direct_execution',
            'responsible_user_id' => $manager->id,
        ]);

        app(IntegrityAlertService::class)->sync($municipality->fresh());
        app(MunicipalWorkItemService::class)->synchronize($municipality->fresh());

        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => 'transparency:minimum-list',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'parliamentary_amendment_id' => $amendment->id,
            'source_key' => "amendment:{$amendment->id}:transparency:minimum-list",
            'category' => 'transparency',
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
