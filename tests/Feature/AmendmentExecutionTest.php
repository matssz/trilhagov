<?php

namespace Tests\Feature;

use App\Models\ExecutionStage;
use App\Models\FinancialCommitment;
use App\Models\IntegrityAlert;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Notifications\IntegrityAlertNotification;
use App\Services\IntegrityAlertProcessor;
use App\Services\IntegrityAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AmendmentExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_create_and_update_a_physical_execution_stage_once(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager);
        $token = $this->sessionFor($municipality, "execution-stage-create-{$amendment->id}");
        $payload = $this->stagePayload($token);

        $this->actingAs($manager)
            ->post(route('emendas.stages.store', $amendment), $payload)
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->post(route('emendas.stages.store', $amendment), $payload)
            ->assertSessionHas('warning');

        $stage = ExecutionStage::firstOrFail();
        $this->assertDatabaseCount('execution_stages', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'execution_stage_created']);

        $updateToken = $this->sessionFor($municipality, "execution-stage-update-{$stage->id}");
        $this->patch(route('emendas.stages.update', [$amendment, $stage]), [
            ...$this->stagePayload($updateToken),
            'status' => ExecutionStage::STATUS_COMPLETED,
            'progress_percentage' => 64,
        ])->assertSessionHas('status');

        $this->assertSame(100, $stage->fresh()->progress_percentage);
        $this->assertNotNull($stage->fresh()->completed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'execution_stage_updated']);
    }

    public function test_commitment_accepts_partial_payments_and_rejects_overpayment(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, ['received_amount' => 100000]);
        $commitment = $this->createCommitmentThroughHttp($manager, $municipality, $amendment, 80000);

        $firstToken = $this->sessionFor($municipality, "financial-payment-create-{$commitment->id}");
        $this->post(route('emendas.payments.store', [$amendment, $commitment]), [
            '_submission_token' => $firstToken,
            'payment_reference' => 'OB-2026-001',
            'amount' => 30000,
            'paid_at' => '2026-07-16',
        ])->assertSessionHas('status');

        $secondToken = $this->sessionFor($municipality, "financial-payment-create-{$commitment->id}");
        $this->post(route('emendas.payments.store', [$amendment, $commitment]), [
            '_submission_token' => $secondToken,
            'payment_reference' => 'OB-2026-002',
            'amount' => 60000,
            'paid_at' => '2026-07-16',
        ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('financial_payments', 1);
        $this->assertSame(30000.0, $commitment->fresh()->paidAmount());
        $this->assertSame(50000.0, $commitment->fresh()->remainingAmount());
        $this->assertDatabaseHas('audit_logs', ['action' => 'financial_payment_created']);
    }

    public function test_commitment_with_payment_cannot_be_cancelled(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager);
        $commitment = $this->createCommitmentThroughHttp($manager, $municipality, $amendment, 80000);
        $commitment->payments()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'created_by' => $manager->id,
            'payment_reference' => 'OB-1',
            'amount' => 1000,
            'paid_at' => '2026-07-16',
        ]);
        $token = $this->sessionFor($municipality, "financial-commitment-cancel-{$commitment->id}");

        $this->patch(route('emendas.commitments.cancel', [$amendment, $commitment]), [
            '_submission_token' => $token,
            'cancellation_reason' => 'Correção do processo',
        ])->assertSessionHasErrors('cancellation_reason');

        $this->assertSame(FinancialCommitment::STATUS_ACTIVE, $commitment->fresh()->status);
    }

    public function test_evidence_can_be_linked_to_an_execution_stage(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager);
        $stage = $this->stage($municipality, $amendment, $manager);
        $type = $municipality->documentTypes()->create([
            'name' => 'Termo de recebimento',
            'is_required' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $token = $this->sessionFor($municipality, "amendment-document-upload-{$amendment->id}");

        $this->actingAs($manager)->post(route('emendas.documents.store', $amendment), [
            '_submission_token' => $token,
            'document_type_id' => $type->id,
            'execution_stage_id' => $stage->id,
            'document' => UploadedFile::fake()->create('entrega.pdf', 50, 'application/pdf'),
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('amendment_documents', [
            'parliamentary_amendment_id' => $amendment->id,
            'execution_stage_id' => $stage->id,
        ]);
        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.execution', $amendment))
            ->assertOk()
            ->assertSee('Termo de recebimento')
            ->assertSee('entrega.pdf');
    }

    public function test_execution_records_are_scoped_to_active_municipality_and_roles(): void
    {
        [$viewer, $municipality] = $this->memberWithMunicipality(User::ROLE_VIEWER);
        $amendment = $this->amendment($municipality, $viewer);
        [$otherManager, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $otherAmendment = $this->amendment($otherMunicipality, $otherManager);

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.execution', $amendment))
            ->assertOk()
            ->assertDontSee('Nova etapa');
        $this->post(route('emendas.stages.store', $amendment), [])->assertForbidden();
        $this->get(route('emendas.execution', $otherAmendment))->assertNotFound();
    }

    public function test_execution_divergences_create_integrity_alerts_and_raise_risk(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, [
            'received_amount' => 50000,
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
        ]);
        $commitment = $amendment->financialCommitments()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $manager->id,
            'commitment_number' => 'NE-ALERTA',
            'supplier_name' => 'Fornecedor Teste',
            'procurement_process' => 'PROC-ALERTA',
            'object_description' => 'Entrega vinculada ao objeto',
            'committed_amount' => 60000,
            'committed_at' => '2026-07-15',
            'status' => FinancialCommitment::STATUS_ACTIVE,
        ]);
        $commitment->payments()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'created_by' => $manager->id,
            'payment_reference' => 'OB-ALERTA',
            'amount' => 55000,
            'paid_at' => '2026-07-16',
        ]);

        app(IntegrityAlertService::class)->sync($municipality);

        $keys = IntegrityAlert::query()->where('parliamentary_amendment_id', $amendment->id)->pluck('alert_key');
        $this->assertTrue($keys->contains('execution:missing-stages'));
        $this->assertTrue($keys->contains('execution:commitments-over-received'));
        $this->assertTrue($keys->contains('execution:payments-over-received'));
        $this->assertTrue($keys->contains('execution:payments-without-evidence'));
        $this->assertContains($amendment->fresh()->risk_level, [
            ParliamentaryAmendment::RISK_HIGH,
            ParliamentaryAmendment::RISK_CRITICAL,
        ]);
    }

    public function test_stage_deadline_alert_is_sent_to_the_stage_responsible_person(): void
    {
        Notification::fake();
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $editor = User::factory()->create();
        $municipality->users()->attach($editor, ['role' => User::ROLE_EDITOR]);
        $amendment = $this->amendment($municipality, $manager, [
            'responsible_user_id' => $manager->id,
        ]);
        $stage = $this->stage($municipality, $amendment, $manager);
        $stage->update([
            'responsible_user_id' => $editor->id,
            'planned_end_at' => today()->subDay(),
        ]);

        app(IntegrityAlertProcessor::class)->process($municipality);

        $this->assertDatabaseHas('integrity_alerts', [
            'alert_key' => "deadline:stage:{$stage->id}",
            'assigned_user_id' => $editor->id,
        ]);
        Notification::assertSentTo($editor, IntegrityAlertNotification::class);
    }

    private function createCommitmentThroughHttp(User $manager, Municipality $municipality, ParliamentaryAmendment $amendment, float $amount): FinancialCommitment
    {
        $token = $this->sessionFor($municipality, "financial-commitment-create-{$amendment->id}");
        $this->actingAs($manager)->post(route('emendas.commitments.store', $amendment), [
            '_submission_token' => $token,
            'commitment_number' => 'NE-2026-001',
            'supplier_name' => 'Saúde Equipamentos Ltda',
            'supplier_document' => '12345678000199',
            'procurement_process' => 'PROC-2026-044',
            'object_description' => 'Aquisição dos equipamentos previstos no objeto da emenda.',
            'committed_amount' => $amount,
            'committed_at' => '2026-07-15',
        ])->assertSessionHas('status');

        return FinancialCommitment::firstOrFail();
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

    private function stage(Municipality $municipality, ParliamentaryAmendment $amendment, User $user): ExecutionStage
    {
        return $amendment->executionStages()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $user->id,
            'title' => 'Entrega dos equipamentos',
            'status' => ExecutionStage::STATUS_IN_PROGRESS,
            'progress_percentage' => 50,
            'sort_order' => 10,
        ]);
    }

    /** @return array<string, mixed> */
    private function stagePayload(string $token): array
    {
        return [
            '_submission_token' => $token,
            'title' => 'Entrega dos equipamentos',
            'description' => 'Receber e conferir os equipamentos adquiridos.',
            'status' => ExecutionStage::STATUS_PLANNED,
            'progress_percentage' => 0,
            'planned_amount' => 80000,
            'planned_start_at' => '2026-07-16',
            'planned_end_at' => '2026-08-30',
            'sort_order' => 10,
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
