<?php

namespace Tests\Feature;

use App\Models\ContractAddendum;
use App\Models\ContractMeasurement;
use App\Models\MunicipalContract;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\IntegrityAlertService;
use App\Services\MunicipalWorkItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MunicipalContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_creates_municipal_procurement_with_idempotency_and_human_validation(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager);
        $this->actingAs($manager);
        $token = $this->token($municipality, 'municipal-contract-create');
        $payload = [
            '_submission_token' => $token,
            'parliamentary_amendment_id' => $amendment->id,
            'process_number' => 'PROC-2026-0042',
            'object_type' => 'public_work',
            'procurement_method' => 'competition',
            'object' => 'Construção de unidade municipal de atendimento básico no bairro Central.',
            'estimated_amount' => 800000,
        ];

        $this->post(route('municipal-contracts.store'), $payload)->assertRedirect();
        $contract = MunicipalContract::firstOrFail();
        $this->assertSame(MunicipalContract::STATUS_PLANNING, $contract->status);
        $this->assertSame($amendment->id, $contract->parliamentary_amendment_id);
        $this->post(route('municipal-contracts.store'), $payload)->assertSessionHas('warning');
        $this->assertSame(1, MunicipalContract::count());
        $this->get(route('municipal-contracts.index'))->assertOk()->assertSee('Obras e contratos')->assertSee('PROC-2026-0042');
        $this->get(route('municipal-contracts.show', $contract))->assertOk()->assertSee('Planejamento e seleção')->assertSee('Medições e atestes');
    }

    public function test_contract_cannot_advance_without_controls_and_complete_contract_reaches_execution(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $contract = $this->contract($municipality, $manager, ['status' => MunicipalContract::STATUS_PLANNING]);
        $this->actingAs($manager)->post(route('municipal-contracts.transition', $contract), [
            '_submission_token' => $this->token($municipality, "municipal-contract-transition-{$contract->id}"),
            'action' => 'selection',
        ])->assertSessionHasErrors('transition');
        $this->assertSame(MunicipalContract::STATUS_PLANNING, $contract->fresh()->status);

        $contract->update($this->completeContractData($manager));
        $this->post(route('municipal-contracts.transition', $contract), [
            '_submission_token' => $this->token($municipality, "municipal-contract-transition-{$contract->id}"), 'action' => 'selection',
        ])->assertSessionHas('status');
        $this->post(route('municipal-contracts.transition', $contract), [
            '_submission_token' => $this->token($municipality, "municipal-contract-transition-{$contract->id}"), 'action' => 'contracted',
        ])->assertSessionHas('status');
        $this->post(route('municipal-contracts.transition', $contract), [
            '_submission_token' => $this->token($municipality, "municipal-contract-transition-{$contract->id}"), 'action' => 'executing',
        ])->assertSessionHas('status');

        $this->assertSame(MunicipalContract::STATUS_EXECUTING, $contract->fresh()->status);
    }

    public function test_measurement_requires_evidence_and_preserves_approved_snapshot(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $contract = $this->contract($municipality, $manager, [...$this->completeContractData($manager), 'status' => MunicipalContract::STATUS_EXECUTING]);
        $document = $this->document($municipality, $manager, $contract->amendment);
        $this->actingAs($manager)->post(route('contract-measurements.store', $contract), [
            '_submission_token' => $this->token($municipality, "contract-measurement-create-{$contract->id}"),
            'period_start_at' => '2026-06-01', 'period_end_at' => '2026-06-30', 'measured_at' => '2026-07-02',
            'amount' => 200000, 'cumulative_physical_percentage' => 18,
            'evidence_document_id' => $document->id,
            'notes' => 'Fundações e estrutura inicial executadas conforme boletim de medição.',
        ])->assertSessionHas('status');
        $measurement = ContractMeasurement::firstOrFail();
        $this->post(route('contract-measurements.decide', $measurement), [
            '_submission_token' => $this->token($municipality, "contract-measurement-decision-{$measurement->id}"),
            'action' => 'approve', 'review_notes' => 'Quantitativos conferidos presencialmente pelo fiscal e compatíveis com a evidência.',
        ])->assertSessionHas('status');

        $measurement->refresh();
        $this->assertSame(ContractMeasurement::STATUS_APPROVED, $measurement->status);
        $this->assertSame(64, strlen($measurement->snapshot_sha256));
        $this->assertDatabaseHas('audit_logs', ['action' => 'contract_measurement_decided']);
    }

    public function test_addendum_above_legal_limit_is_blocked_and_valid_one_updates_contract(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $contract = $this->contract($municipality, $manager, [...$this->completeContractData($manager), 'status' => MunicipalContract::STATUS_EXECUTING]);
        $this->actingAs($manager);
        $excessive = $this->addendum($municipality, $manager, $contract, 1, 260000);
        $this->post(route('contract-addenda.decide', $excessive), [
            '_submission_token' => $this->token($municipality, "contract-addendum-decision-{$excessive->id}"),
            'action' => 'approve', 'review_notes' => 'Análise formal do acréscimo solicitado pela unidade executora municipal.',
        ])->assertSessionHasErrors('addendum');
        $this->assertSame(ContractAddendum::STATUS_DRAFT, $excessive->fresh()->status);

        $excessive->delete();
        $valid = $this->addendum($municipality, $manager, $contract, 1, 250000);
        $this->post(route('contract-addenda.decide', $valid), [
            '_submission_token' => $this->token($municipality, "contract-addendum-decision-{$valid->id}"),
            'action' => 'approve', 'review_notes' => 'Acréscimo dentro do limite, com justificativa, projeto e publicidade conferidos.',
        ])->assertSessionHas('status');

        $this->assertSame(ContractAddendum::STATUS_APPROVED, $valid->fresh()->status);
        $this->assertEquals(1250000.0, (float) $contract->fresh()->current_amount);
        $this->assertSame(64, strlen($valid->fresh()->snapshot_sha256));
    }

    public function test_contract_risks_feed_integrity_and_work_center(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $contract = $this->contract($municipality, $manager, [
            ...$this->completeContractData($manager),
            'status' => MunicipalContract::STATUS_SUSPENDED,
            'contract_inspector_id' => null,
            'publication_reference' => null,
            'published_at' => null,
            'suspended_at' => today()->subDays(40),
            'suspension_reason' => 'Interferência de rede pública exige revisão técnica antes da retomada dos serviços.',
        ]);

        app(IntegrityAlertService::class)->sync($municipality);
        app(MunicipalWorkItemService::class)->synchronize($municipality);

        $this->assertDatabaseHas('integrity_alerts', ['alert_key' => "contract:{$contract->id}:publication", 'severity' => 'critical']);
        $this->assertDatabaseHas('integrity_alerts', ['alert_key' => "contract:{$contract->id}:suspended-over-month", 'severity' => 'critical']);
        $this->assertDatabaseHas('municipal_work_items', ['source_key' => "amendment:{$contract->parliamentary_amendment_id}:contract:{$contract->id}:suspension-notice", 'category' => 'contract']);
    }

    public function test_roles_and_active_municipality_are_enforced(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $contract = $this->contract($municipality, $manager);
        [$viewer, $other] = $this->member(User::ROLE_VIEWER);
        $this->actingAs($viewer)->withSession(['active_municipality_id' => $other->id]);
        $this->get(route('municipal-contracts.index'))->assertOk();
        $this->get(route('municipal-contracts.show', $contract))->assertNotFound();
        $this->post(route('municipal-contracts.store'))->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function completeContractData(User $user): array
    {
        return [
            'contract_number' => 'CT-2026-018', 'supplier_name' => 'Engenharia Municipal Ltda.',
            'supplier_document' => '12345678000190', 'original_amount' => 1000000, 'current_amount' => 1000000,
            'signed_at' => '2026-05-02', 'effective_start_at' => '2026-05-05', 'effective_end_at' => '2027-05-04',
            'work_order_at' => '2026-05-08', 'contract_manager_id' => $user->id, 'contract_inspector_id' => $user->id,
            'measurement_criteria' => 'Medições mensais por quantitativos efetivamente executados e conferidos.',
            'payment_terms' => 'Liquidação após ateste e pagamento conforme ordem cronológica.',
            'technical_responsible' => 'Engenheira Carla Souza', 'technical_registration' => 'ART 280272302600001',
            'site_location' => 'Rua Municipal, 100, Bairro Central',
            'publication_type' => 'pncp', 'publication_reference' => 'https://pncp.gov.br/app/contratos/municipio/2026/18', 'published_at' => '2026-05-06',
            'planning_checklist' => array_fill_keys([
                'demand_formalized', 'technical_study', 'risk_analysis', 'basic_project',
                'budget_spreadsheet', 'physical_financial_schedule', 'licenses_and_land',
                'budget_allocation', 'legal_review',
            ], true),
        ];
    }

    private function addendum(Municipality $municipality, User $user, MunicipalContract $contract, int $sequence, float $amount): ContractAddendum
    {
        return $contract->addenda()->create([
            'municipality_id' => $municipality->id, 'parliamentary_amendment_id' => $contract->parliamentary_amendment_id,
            'created_by' => $user->id, 'sequence' => $sequence, 'type' => 'increase', 'status' => 'draft',
            'value_change' => $amount, 'days_change' => 0,
            'justification' => 'Adequação quantitativa indispensável para concluir o objeto contratado pelo Município.',
            'technical_basis' => 'Memória de cálculo e projeto revisado demonstram a necessidade dos quantitativos adicionais.',
            'effective_at' => '2026-08-01', 'signed_at' => '2026-08-01',
            'publication_reference' => 'PNCP-ADITIVO-2026-01', 'published_at' => '2026-08-04',
        ]);
    }

    private function document(Municipality $municipality, User $user, ParliamentaryAmendment $amendment)
    {
        $type = $municipality->documentTypes()->create(['created_by' => $user->id, 'name' => 'Boletim de medição', 'is_active' => true]);

        return $amendment->documents()->create([
            'municipality_id' => $municipality->id, 'document_type_id' => $type->id,
            'uploaded_by' => $user->id, 'uploader_name' => $user->name,
            'original_name' => 'medicao-01.pdf', 'storage_path' => 'tests/'.Str::uuid().'.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1024, 'version' => 1,
        ]);
    }

    private function contract(Municipality $municipality, User $user, array $overrides = []): MunicipalContract
    {
        $amendment = $this->amendment($municipality, $user);

        return $municipality->municipalContracts()->create([
            'parliamentary_amendment_id' => $amendment->id, 'created_by' => $user->id, 'updated_by' => $user->id,
            'reference' => (string) Str::uuid(), 'process_number' => 'PROC-'.Str::random(8),
            'object_type' => 'public_work', 'procurement_method' => 'competition', 'execution_regime' => 'unit_price',
            'object' => 'Construção de unidade municipal de atendimento básico no bairro Central.',
            'estimated_amount' => 1000000, 'status' => MunicipalContract::STATUS_PLANNING,
            ...$overrides,
        ]);
    }

    private function amendment(Municipality $municipality, User $user): ParliamentaryAmendment
    {
        return ParliamentaryAmendment::factory()->create([
            'municipality_id' => $municipality->id, 'created_by' => $user->id,
            'reference' => 'EM-OBRA-'.Str::random(7), 'fiscal_year' => now()->year,
            'government_sphere' => 'municipal', 'status' => ParliamentaryAmendment::STATUS_EXECUTING,
        ]);
    }

    /** @return array{0: User, 1: Municipality} */
    private function member(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP']);
        $municipality->users()->attach($user->id, ['role' => $role]);

        return [$user, $municipality];
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
