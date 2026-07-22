<?php

namespace Tests\Feature;

use App\Models\LegislativeProposal;
use App\Models\Municipality;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\LegislativeProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegislativeProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_limit_is_divided_between_council_seats_and_health_is_tracked(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereadora Ana Silva',
            'legislative_party' => 'PSD',
        ]);
        $proposal = $this->proposal($municipality, $profile, $councilor, [
            'estimated_amount' => 40000,
            'health_related' => true,
        ]);

        $quota = app(LegislativeProposalService::class)->quota(
            $municipality,
            $profile,
            'Vereadora Ana Silva',
            $proposal,
        );

        $this->assertEquals(1550000.0, $quota['global_ceiling']);
        $this->assertEquals(155000.0, $quota['author_ceiling']);
        $this->assertEquals(40000.0, $quota['used']);
        $this->assertEquals(40000.0, $quota['health_allocated']);
        $this->assertEquals(20000.0, $quota['health_required']);
        $this->assertEquals(0.0, $quota['health_gap']);
    }

    public function test_councilor_creates_proposal_with_institutional_identity_and_duplicate_click_is_ignored(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereador Bruno Costa',
            'legislative_party' => 'MDB',
        ]);
        $this->actingAs($councilor);
        $token = $this->token($municipality, 'legislative-proposal-create');
        $payload = [...$this->proposalPayload(), '_submission_token' => $token];

        $this->post(route('legislative.store'), $payload)->assertRedirect();
        $proposal = LegislativeProposal::firstOrFail();

        $this->assertSame('Vereador Bruno Costa', $proposal->author_name);
        $this->assertSame('MDB', $proposal->author_party);
        $this->assertSame(LegislativeProposal::STATUS_DRAFT, $proposal->status);
        $this->post(route('legislative.store'), $payload)->assertSessionHas('warning');
        $this->assertSame(1, LegislativeProposal::count());
        $this->get(route('legislative.index'))->assertOk()->assertSee($proposal->reference);
        $this->get(route('dashboard'))->assertForbidden();
    }

    public function test_submission_blocks_amount_above_councilor_quota(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereadora Carla Souza',
            'legislative_party' => 'PSB',
        ]);
        $proposal = $this->proposal($municipality, $profile, $councilor, ['estimated_amount' => 155000.01]);

        $this->actingAs($councilor)->post(route('legislative.submit', $proposal), [
            '_submission_token' => $this->token($municipality, "legislative-proposal-submit-{$proposal->id}"),
        ])->assertSessionHasErrors('estimated_amount');

        $this->assertSame(LegislativeProposal::STATUS_DRAFT, $proposal->fresh()->status);
    }

    public function test_health_gap_blocks_protocol_until_councilor_portfolio_meets_local_percentage(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereador Daniel Lima',
            'legislative_party' => 'UNI',
        ]);
        $reviewer = $this->attach($municipality, User::ROLE_LEGISLATIVE_REVIEWER);
        $proposal = $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_APPROVED,
            'estimated_amount' => 50000,
            'health_related' => false,
            ...$this->reviewedData($reviewer),
        ]);

        $this->actingAs($reviewer)->post(route('legislative.protocol', $proposal), [
            '_submission_token' => $this->token($municipality, "legislative-proposal-protocol-{$proposal->id}"),
            'protocol_number' => 'CAM-2027-0042',
        ])->assertSessionHasErrors('protocol');

        $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_APPROVED,
            'estimated_amount' => 50000,
            'health_related' => true,
            ...$this->reviewedData($reviewer),
        ]);

        $this->post(route('legislative.protocol', $proposal), [
            '_submission_token' => $this->token($municipality, "legislative-proposal-protocol-{$proposal->id}"),
            'protocol_number' => 'CAM-2027-0042',
        ])->assertSessionHas('status');

        $proposal->refresh();
        $this->assertSame(LegislativeProposal::STATUS_SENT, $proposal->status);
        $this->assertSame(64, strlen($proposal->protocol_sha256));
        $this->assertNotEmpty($proposal->protocol_snapshot);
    }

    public function test_complete_legislative_to_executive_flow_creates_amendment_and_budget_reservation(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereadora Elisa Martins',
            'legislative_party' => 'PV',
        ]);
        $reviewer = $this->attach($municipality, User::ROLE_LEGISLATIVE_REVIEWER);
        $proposal = $this->proposal($municipality, $profile, $councilor, [
            'estimated_amount' => 100000,
            'health_related' => true,
        ]);

        $this->actingAs($councilor)->post(route('legislative.submit', $proposal), [
            '_submission_token' => $this->token($municipality, "legislative-proposal-submit-{$proposal->id}"),
        ])->assertSessionHas('status');

        $review = ['decision' => 'approve', 'review_notes' => 'Compatibilidade normativa e viabilidade preliminar conferidas pela assessoria técnica.'];
        foreach (array_keys(app(LegislativeProposalService::class)->reviewChecklist()) as $field) {
            $review[$field] = 1;
        }
        $this->actingAs($reviewer)->post(route('legislative.review', $proposal), [
            ...$review,
            '_submission_token' => $this->token($municipality, "legislative-proposal-review-{$proposal->id}"),
        ])->assertSessionHas('status');
        $this->post(route('legislative.protocol', $proposal), [
            '_submission_token' => $this->token($municipality, "legislative-proposal-protocol-{$proposal->id}"),
            'protocol_number' => 'CAM-2027-0100',
        ])->assertSessionHas('status');

        $this->actingAs($manager)->post(route('legislative.receive', $proposal), [
            '_submission_token' => $receiveToken = $this->token($municipality, "legislative-proposal-receive-{$proposal->id}"),
            'executive_process_number' => 'PREF-2027-0021',
            'executive_notes' => 'Documentação recebida e encaminhada para reanálise orçamentária da unidade municipal competente.',
        ])->assertSessionHas('status');
        $this->post(route('legislative.receive', $proposal), [
            '_submission_token' => $receiveToken,
            'executive_process_number' => 'PREF-2027-0021',
            'executive_notes' => 'Documentação recebida e encaminhada para reanálise orçamentária da unidade municipal competente.',
        ])->assertSessionHas('warning');

        $proposal->refresh();
        $amendment = $proposal->amendment;
        $this->assertNotNull($amendment);
        $this->assertTrue($amendment->indicated_for_health);
        $this->assertSame(1, ParliamentaryAmendment::where('reference', $proposal->reference)->count());

        $this->post(route('legislative.reserve', $proposal), [
            '_submission_token' => $this->token($municipality, "legislative-proposal-reserve-{$proposal->id}"),
            'budget_reservation_number' => 'RES-2027-0009',
            'budget_reserved_amount' => 100000,
            'budget_reserved_at' => today()->toDateString(),
            'executive_notes' => 'Dotação e disponibilidade financeira reanalisadas, com reserva integral registrada no orçamento municipal.',
        ])->assertSessionHas('status');

        $this->assertSame(LegislativeProposal::STATUS_RESERVED, $proposal->fresh()->status);
        $this->assertSame(ParliamentaryAmendment::STATUS_PLAN_PENDING, $amendment->fresh()->status);
        $this->assertDatabaseHas('legislative_proposal_events', ['legislative_proposal_id' => $proposal->id, 'event_type' => 'budget_reserved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'legislative_proposal_budget_reserved']);

        $amendment->executionStages()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $manager->id,
            'title' => 'Entrega dos equipamentos municipais',
            'status' => 'in_progress',
            'progress_percentage' => 40,
            'sort_order' => 10,
        ]);
        $commitment = $amendment->financialCommitments()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $manager->id,
            'commitment_number' => '2027NE0001',
            'supplier_name' => 'Fornecedor Municipal Ltda',
            'procurement_process' => 'PROC-2027-021',
            'object_description' => 'Equipamentos vinculados à indicação legislativa.',
            'committed_amount' => 80000,
            'committed_at' => today(),
            'status' => 'active',
        ]);
        $liquidation = $commitment->liquidations()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'created_by' => $manager->id,
            'liquidation_reference' => '2027NL0001',
            'amount' => 60000,
            'liquidated_at' => today(),
            'supporting_document' => 'NF-2027-100',
            'acceptance_reference' => 'ATESTO-2027-100',
        ]);
        $commitment->payments()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'financial_liquidation_id' => $liquidation->id,
            'created_by' => $manager->id,
            'payment_reference' => '2027OB0001',
            'amount' => 50000,
            'paid_at' => today(),
        ]);

        $this->actingAs($manager)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.show', $proposal))
            ->assertOk()
            ->assertSee('Abrir fluxo executivo')
            ->assertSee('R$ 80.000,00')
            ->assertSee('R$ 60.000,00')
            ->assertSee('R$ 50.000,00')
            ->assertSee('40%');
        $this->actingAs($councilor)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.show', $proposal))
            ->assertOk()
            ->assertSee('Cadastro Câmara')
            ->assertSee('Análise executiva')
            ->assertSee('Pagamento')
            ->assertDontSee('Abrir fluxo executivo');
    }

    public function test_councilor_cannot_view_another_councilors_proposal_or_another_municipality(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $first = $this->attach($municipality, User::ROLE_COUNCILOR, ['legislative_name' => 'Vereador Um', 'legislative_party' => 'A']);
        $second = $this->attach($municipality, User::ROLE_COUNCILOR, ['legislative_name' => 'Vereador Dois', 'legislative_party' => 'B']);
        $proposal = $this->proposal($municipality, $profile, $first);

        $this->actingAs($second)->withSession(['active_municipality_id' => $municipality->id]);
        $this->get(route('legislative.show', $proposal))->assertNotFound();

        [$otherManager, $otherMunicipality] = $this->member(User::ROLE_MANAGER);
        $this->actingAs($otherManager)->withSession(['active_municipality_id' => $otherMunicipality->id]);
        $this->get(route('legislative.show', $proposal))->assertNotFound();
    }

    /** @return array{0: User, 1: Municipality} */
    private function member(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP']);
        $municipality->users()->attach($user->id, ['role' => $role]);

        return [$user, $municipality];
    }

    private function attach(Municipality $municipality, string $role, array $pivot = []): User
    {
        $user = User::factory()->create();
        $municipality->users()->attach($user->id, ['role' => $role, ...$pivot]);

        return $user;
    }

    private function profile(Municipality $municipality, User $manager): MunicipalRegulatoryProfile
    {
        return $municipality->regulatoryProfiles()->create([
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
            'activated_by' => $manager->id,
            'fiscal_year' => now()->year + 1,
            'version' => 1,
            'status' => MunicipalRegulatoryProfile::STATUS_ACTIVE,
            'regime_status' => MunicipalRegulatoryProfile::REGIME_INSTITUTED,
            'previous_year_rcl' => 100000000,
            'individual_limit_percentage' => 1.55,
            'councilor_seats' => 10,
            'health_reserve_percentage' => 50,
            'health_reserve_method' => 'per_councilor',
            'amendments_per_councilor_limit' => 20,
            'minimum_amendment_amount' => 1000,
            'activated_at' => now(),
        ]);
    }

    private function proposal(Municipality $municipality, MunicipalRegulatoryProfile $profile, User $councilor, array $overrides = []): LegislativeProposal
    {
        return $municipality->legislativeProposals()->create([
            'municipal_regulatory_profile_id' => $profile->id,
            'submitted_by' => $councilor->id,
            'reference' => 'LEG-'.(now()->year + 1).'-'.Str::upper(Str::random(5)),
            'fiscal_year' => now()->year + 1,
            'author_name' => 'Vereador padrão',
            'author_party' => 'PSD',
            'object' => 'Aquisição de equipamentos permanentes para a unidade municipal de saúde do bairro Central.',
            'justification' => 'A unidade municipal necessita ampliar a capacidade de atendimento da população local.',
            'priority' => 'high',
            'beneficiary_type' => 'municipal_body',
            'beneficiary_name' => 'Secretaria Municipal de Saúde',
            'beneficiary_location' => 'Bairro Central',
            'expense_destination' => 'investment',
            'transfer_type' => 'direct_execution',
            'health_related' => true,
            'responsible_department' => 'Secretaria Municipal de Saúde',
            'public_need' => 'Reduzir a fila municipal e substituir equipamentos sem condições adequadas de funcionamento.',
            'target_population' => 'Usuários da atenção básica',
            'estimated_quantity' => '10 equipamentos',
            'estimated_amount' => 100000,
            'estimate_source' => 'Pesquisa preliminar de preços',
            'status' => LegislativeProposal::STATUS_DRAFT,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function proposalPayload(): array
    {
        return [
            'fiscal_year' => now()->year + 1,
            'object' => 'Aquisição de equipamentos permanentes para a unidade municipal de saúde do bairro Central.',
            'justification' => 'A unidade municipal necessita ampliar a capacidade de atendimento da população local.',
            'priority' => 'high',
            'beneficiary_type' => 'municipal_body',
            'beneficiary_name' => 'Secretaria Municipal de Saúde',
            'beneficiary_location' => 'Bairro Central',
            'expense_destination' => 'investment',
            'transfer_type' => 'direct_execution',
            'health_related' => 1,
            'responsible_department' => 'Secretaria Municipal de Saúde',
            'program_reference' => 'PPA Saúde 2030',
            'action_reference' => 'Ação 2042',
            'public_need' => 'Reduzir a fila municipal e substituir equipamentos sem condições adequadas de funcionamento.',
            'target_population' => 'Usuários da atenção básica',
            'estimated_quantity' => '10 equipamentos',
            'estimated_amount' => 100000,
            'estimate_source' => 'Pesquisa preliminar de preços',
        ];
    }

    /** @return array<string, mixed> */
    private function reviewedData(User $reviewer): array
    {
        $checks = array_fill_keys(array_keys(app(LegislativeProposalService::class)->reviewChecklist()), true);

        return [
            ...$checks,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => 'Compatibilidade normativa e viabilidade preliminar conferidas pela assessoria técnica.',
        ];
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
