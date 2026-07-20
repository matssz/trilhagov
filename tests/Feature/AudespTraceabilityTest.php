<?php

namespace Tests\Feature;

use App\Models\AudespAmendmentRegistration;
use App\Models\FinancialCommitment;
use App\Models\FinancialLiquidation;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\IntegrityAlertService;
use App\Services\MunicipalWorkItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class AudespTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_prepare_registration_from_official_xsd_and_download_internal_preview_once(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->municipalAmendment($municipality, $manager);
        $token = $this->sessionFor($municipality, "audesp-registration-{$amendment->id}");

        $this->actingAs($manager)
            ->put(route('emendas.audesp.update', $amendment), $this->registrationPayload($token))
            ->assertRedirect()
            ->assertSessionHas('status');

        $registration = AudespAmendmentRegistration::firstOrFail();
        $this->assertNotNull($registration->prepared_at);
        $this->assertSame(['301', '302'], $registration->government_subfunctions);
        $this->assertDatabaseHas('audit_logs', ['action' => 'audesp_registration_created']);

        $previewToken = $this->sessionFor($municipality, "audesp-preview-{$amendment->id}");
        $response = $this->post(route('emendas.audesp.preview', $amendment), [
            '_submission_token' => $previewToken,
        ]);
        $response->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->assertSee('naoTransmitir="true"', false)
            ->assertSee('<AmbitoEmenda>M</AmbitoEmenda>', false)
            ->assertSee('<SubFuncao>301</SubFuncao>', false)
            ->assertSee('<ObjetoEmenda>Saúde &amp; cuidado na unidade municipal.</ObjetoEmenda>', false)
            ->assertSee('<CodigoAplicacao>8001</CodigoAplicacao>', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'audesp_preview_exported']);
        $this->assertSame(1, $registration->fresh()->preview_count);

        $this->post(route('emendas.audesp.preview', $amendment), [
            '_submission_token' => $previewToken,
        ])->assertConflict();
        $this->assertSame(1, $registration->fresh()->preview_count);
    }

    public function test_registration_rejects_codes_outside_official_2026_schema(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->municipalAmendment($municipality, $manager);
        $token = $this->sessionFor($municipality, "audesp-registration-{$amendment->id}");

        $this->actingAs($manager)
            ->put(route('emendas.audesp.update', $amendment), [
                ...$this->registrationPayload($token),
                'application_code' => '7001',
                'government_subfunctions' => '301, 777',
            ])
            ->assertSessionHasErrors(['application_code', 'government_subfunctions']);

        $this->assertDatabaseCount('audesp_amendment_registrations', 0);
    }

    public function test_2025_registration_requires_reclassification_evidence_before_preview(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->municipalAmendment($municipality, $manager, ['fiscal_year' => 2025]);
        $token = $this->sessionFor($municipality, "audesp-registration-{$amendment->id}");

        $this->actingAs($manager)->put(route('emendas.audesp.update', $amendment), [
            ...$this->registrationPayload($token),
            'amendment_year' => 2025,
            'prior_balance_reclassified' => 0,
        ])->assertSessionHas('status');

        $this->assertNull(AudespAmendmentRegistration::firstOrFail()->prepared_at);
        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.audesp', $amendment))
            ->assertOk()
            ->assertSee('registre a movimentação contábil de reclassificação');
    }

    public function test_municipal_payment_requires_liquidation_and_cannot_exceed_its_available_amount(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->municipalAmendment($municipality, $manager);
        $commitment = $this->commitment($amendment, $municipality, $manager, 100000);

        $paymentToken = $this->sessionFor($municipality, "financial-payment-create-{$commitment->id}");
        $this->actingAs($manager)->post(route('emendas.payments.store', [$amendment, $commitment]), [
            '_submission_token' => $paymentToken,
            'payment_reference' => 'OB-SEM-LIQ',
            'amount' => 1000,
            'paid_at' => '2026-07-20',
        ])->assertSessionHasErrors('financial_liquidation_id');

        $liquidationToken = $this->sessionFor($municipality, "financial-liquidation-create-{$commitment->id}");
        $this->post(route('emendas.liquidations.store', [$amendment, $commitment]), [
            '_submission_token' => $liquidationToken,
            'liquidation_reference' => 'LIQ-2026-001',
            'amount' => 40000,
            'liquidated_at' => '2026-07-18',
            'supporting_document' => 'NF-e 4451',
            'acceptance_reference' => 'Ateste do fiscal em 18/07/2026',
        ])->assertSessionHas('status');
        $liquidation = FinancialLiquidation::firstOrFail();

        $overToken = $this->sessionFor($municipality, "financial-payment-create-{$commitment->id}");
        $this->post(route('emendas.payments.store', [$amendment, $commitment]), [
            '_submission_token' => $overToken,
            'financial_liquidation_id' => $liquidation->id,
            'payment_reference' => 'OB-MAIOR',
            'amount' => 41000,
            'paid_at' => '2026-07-20',
        ])->assertSessionHasErrors('amount');

        $validToken = $this->sessionFor($municipality, "financial-payment-create-{$commitment->id}");
        $this->post(route('emendas.payments.store', [$amendment, $commitment]), [
            '_submission_token' => $validToken,
            'financial_liquidation_id' => $liquidation->id,
            'payment_reference' => 'OB-VALIDA',
            'amount' => 30000,
            'paid_at' => '2026-07-20',
        ])->assertSessionHas('status');

        $this->assertSame(10000.0, $liquidation->fresh()->availableAmount());
        $this->assertDatabaseHas('audit_logs', ['action' => 'financial_liquidation_created']);
        $this->assertDatabaseHas('financial_payments', ['financial_liquidation_id' => $liquidation->id]);
    }

    public function test_liquidation_is_immutable_and_cannot_exceed_commitment(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->municipalAmendment($municipality, $manager);
        $commitment = $this->commitment($amendment, $municipality, $manager, 10000);
        $token = $this->sessionFor($municipality, "financial-liquidation-create-{$commitment->id}");

        $this->actingAs($manager)->post(route('emendas.liquidations.store', [$amendment, $commitment]), [
            '_submission_token' => $token,
            'liquidation_reference' => 'LIQ-ACIMA',
            'amount' => 10001,
            'liquidated_at' => '2026-07-18',
            'supporting_document' => 'NF-e 100',
            'acceptance_reference' => 'Ateste 100',
        ])->assertSessionHasErrors('amount');

        $liquidation = $commitment->liquidations()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'created_by' => $manager->id,
            'liquidation_reference' => 'LIQ-IMUTAVEL',
            'amount' => 5000,
            'liquidated_at' => '2026-07-18',
            'supporting_document' => 'NF-e 101',
            'acceptance_reference' => 'Ateste 101',
        ]);

        $cancelToken = $this->sessionFor($municipality, "financial-commitment-cancel-{$commitment->id}");
        $this->patch(route('emendas.commitments.cancel', [$amendment, $commitment]), [
            '_submission_token' => $cancelToken,
            'cancellation_reason' => 'Tentativa posterior à liquidação',
        ])->assertSessionHasErrors('cancellation_reason');
        $this->assertSame(FinancialCommitment::STATUS_ACTIVE, $commitment->fresh()->status);

        $this->expectException(LogicException::class);
        $liquidation->update(['amount' => 4000]);
    }

    public function test_audesp_page_is_tenant_scoped_and_read_only_for_viewer(): void
    {
        [$viewer, $municipality] = $this->memberWithMunicipality(User::ROLE_VIEWER);
        $amendment = $this->municipalAmendment($municipality, $viewer);
        [$otherManager, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $otherAmendment = $this->municipalAmendment($otherMunicipality, $otherManager);

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.audesp', $amendment))
            ->assertOk()
            ->assertDontSee('Salvar e diagnosticar');
        $this->put(route('emendas.audesp.update', $amendment), [])->assertForbidden();
        $this->get(route('emendas.audesp', $otherAmendment))->assertNotFound();
    }

    public function test_missing_audesp_registration_creates_alert_and_operational_action(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->municipalAmendment($municipality, $manager);

        app(IntegrityAlertService::class)->sync($municipality);
        app(MunicipalWorkItemService::class)->synchronize($municipality);

        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => 'audesp:registration-readiness',
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:audesp-readiness",
        ]);

        $response = $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.audesp.diagnostic', $amendment));
        $response->assertOk()->assertDownload();
        $this->assertStringContainsString('Possui bloqueios', $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'audesp_diagnostic_exported']);
    }

    /** @return array{User, Municipality} */
    private function memberWithMunicipality(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create([
            'state' => 'SP',
            'ibge_code' => fake()->unique()->numerify('35#####'),
        ]);
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }

    private function municipalAmendment(Municipality $municipality, User $user, array $attributes = []): ParliamentaryAmendment
    {
        return ParliamentaryAmendment::factory()->for($municipality)->for($user, 'creator')->create([
            'government_sphere' => 'municipal',
            'authorship_type' => 'individual',
            'transfer_type' => 'direct_execution',
            'bank_tracking_type' => 'municipal_direct_codes',
            'funding_source_code' => '08',
            'application_code_fixed' => '800',
            'application_code_variable' => '1',
            'expense_destination' => 'cost',
            'responsible_user_id' => $user->id,
            ...$attributes,
        ]);
    }

    private function commitment(ParliamentaryAmendment $amendment, Municipality $municipality, User $user, float $amount): FinancialCommitment
    {
        return $amendment->financialCommitments()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $user->id,
            'commitment_number' => 'NE-2026-001',
            'supplier_name' => 'Fornecedor Municipal Ltda',
            'procurement_process' => 'PROC-2026-100',
            'object_description' => 'Execução do objeto municipal cadastrado.',
            'committed_amount' => $amount,
            'committed_at' => '2026-07-15',
            'status' => FinancialCommitment::STATUS_ACTIVE,
        ]);
    }

    /** @return array<string, mixed> */
    private function registrationPayload(string $token): array
    {
        return [
            '_submission_token' => $token,
            'amendment_type' => 2,
            'legal_basis' => 'Lei',
            'proponent_name' => 'Vereador João Municipal',
            'amendment_number' => 'EM-2026-010',
            'amendment_year' => 2026,
            'object' => 'Saúde & cuidado na unidade municipal.',
            'purpose' => 'Ampliar a capacidade de atendimento da atenção básica municipal.',
            'government_function' => '10',
            'government_subfunctions' => '301, 302',
            'destination' => 'C',
            'bank_account_opened' => 0,
            'application_code' => '8001',
            'prior_balance_reclassified' => 0,
        ];
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
