<?php

namespace Tests\Feature;

use App\Models\LegislativeProposal;
use App\Models\Municipality;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\MunicipalWorkItem;
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

    public function test_councilor_can_create_proposal_with_simplified_required_fields(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereadora Simples',
            'legislative_party' => 'PSD',
        ]);

        $this->actingAs($councilor);
        $token = $this->token($municipality, 'legislative-proposal-create');

        $this->post(route('legislative.store'), [
            '_submission_token' => $token,
            'fiscal_year' => now()->year + 1,
            'object' => 'Compra de equipamentos para atendimento da populacao no posto municipal.',
            'justification' => 'A unidade precisa ampliar o atendimento e reduzir o tempo de espera dos moradores.',
            'beneficiary_name' => 'Posto Municipal Central',
            'expense_destination' => 'investment',
            'health_related' => '1',
            'responsible_department' => 'Secretaria Municipal de Saude',
            'estimated_amount' => 75000,
        ])->assertRedirect();

        $proposal = LegislativeProposal::firstOrFail();
        $this->assertSame('normal', $proposal->priority);
        $this->assertSame('municipal_body', $proposal->beneficiary_type);
        $this->assertSame('direct_execution', $proposal->transfer_type);
        $this->assertSame($municipality->name, $proposal->beneficiary_location);
        $this->assertSame($proposal->justification, $proposal->public_need);
        $this->assertSame('Estimativa declarada pelo vereador', $proposal->estimate_source);
    }

    public function test_councilor_cannot_save_proposal_above_available_quota(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereador Limite Inicial',
            'legislative_party' => 'PSD',
        ]);

        $this->actingAs($councilor);
        $this->post(route('legislative.store'), [
            ...$this->proposalPayload(),
            '_submission_token' => $this->token($municipality, 'legislative-proposal-create'),
            'estimated_amount' => 155000.01,
        ])->assertSessionHasErrors('estimated_amount');

        $this->assertSame(0, LegislativeProposal::count());
    }

    public function test_councilor_cannot_update_draft_above_available_quota(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereador Limite Edicao',
            'legislative_party' => 'PSD',
        ]);
        $proposal = $this->proposal($municipality, $profile, $councilor, [
            'author_name' => 'Vereador Limite Edicao',
            'estimated_amount' => 50000,
        ]);

        $this->actingAs($councilor);
        $this->patch(route('legislative.update', $proposal), [
            ...$this->proposalPayload(),
            '_submission_token' => $this->token($municipality, "legislative-proposal-update-{$proposal->id}"),
            'estimated_amount' => 155000.01,
        ])->assertSessionHasErrors('estimated_amount');

        $this->assertEquals(50000.0, (float) $proposal->fresh()->estimated_amount);
    }

    public function test_portal_uses_available_active_year_instead_of_unconfigured_next_year(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $this->profile($municipality, $manager, now()->year);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereador Exercício Atual',
            'legislative_party' => 'ABC',
        ]);

        $this->actingAs($councilor)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.index'))
            ->assertOk()
            ->assertViewHas('year', now()->year)
            ->assertSee(now()->year.' · ativo')
            ->assertSee('Nova proposta')
            ->assertSee('Pronto para indicar')
            ->assertSee('Uso da cota')
            ->assertSee('Reserva minima de saude')
            ->assertSee('Resumo automatico para o vereador')
            ->assertSee('Quanto ainda posso indicar')
            ->assertSee('Reserva de saude')
            ->assertSee('Proximo movimento')
            ->assertSee('Vereador indica')
            ->assertSee('Prefeitura executa')
            ->assertSee('Meu acompanhamento')
            ->assertSee('Suas propostas por etapa')
            ->assertSee('Com o Executivo')
            ->assertDontSee('Cadastro indisponível')
            ->assertDontSee('Cadastrar emenda');

        $this->get(route('legislative.create'))
            ->assertOk()
            ->assertViewHas('year', now()->year)
            ->assertSee('Nova proposta legislativa')
            ->assertDontSee('Cadastrar emenda');
    }

    public function test_create_redirects_with_human_message_when_no_active_year_exists(): void
    {
        [, $municipality] = $this->member(User::ROLE_MANAGER);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereador Sem Regra',
            'legislative_party' => 'XYZ',
        ]);

        $this->actingAs($councilor)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.create'))
            ->assertRedirect(route('legislative.index', ['year' => now()->year + 1]))
            ->assertSessionHas('warning');
    }

    public function test_councilor_portal_guides_when_quota_is_exhausted(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereadora Saldo Zero',
            'legislative_party' => 'PSD',
        ]);
        $this->proposal($municipality, $profile, $councilor, [
            'author_name' => 'Vereadora Saldo Zero',
            'estimated_amount' => 155000,
        ]);

        $this->actingAs($councilor)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.index', ['year' => 2027]))
            ->assertOk()
            ->assertSee('Sem saldo')
            ->assertSee('Saldo individual esgotado')
            ->assertSee('Aguardando liberacao');
    }

    public function test_create_screen_projects_available_quota_and_recommends_health_automatically(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereadora Automacao',
            'legislative_party' => 'PSD',
        ]);
        $this->proposal($municipality, $profile, $councilor, [
            'author_name' => 'Vereadora Automacao',
            'estimated_amount' => 50000,
            'health_related' => false,
        ]);

        $this->actingAs($councilor)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.create', ['year' => 2027]))
            ->assertOk()
            ->assertSee('Saldo que sobrara na cota')
            ->assertSee('Assistente automatico de preenchimento')
            ->assertSee('Modelos rapidos para vereador')
            ->assertSee('Equipamento de saude')
            ->assertSee('Escola municipal')
            ->assertSee('Obra no bairro')
            ->assertSee('data-template="health-equipment"', false)
            ->assertSee('Conferência automática antes da Câmara')
            ->assertSee('Cota e saldo')
            ->assertSee('Reserva mínima')
            ->assertSee('Salvar proposta')
            ->assertSee('data-legislative-readiness', false)
            ->assertSee('data-auto-health-source', false)
            ->assertSee('data-auto-health-toggle', false)
            ->assertSee('data-auto-department', false)
            ->assertSee('data-auto-estimate-source', false)
            ->assertSee('data-fill-available', false)
            ->assertSee('data-fill-health', false)
            ->assertSee('data-health-amount="25000"', false)
            ->assertSee('max="105000"', false);
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

        $this->actingAs($manager)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.show', $proposal))
            ->assertOk()
            ->assertSee('Recebimento pela Prefeitura')
            ->assertSee('Receber agora')
            ->assertSee('Recebimento automatico preparado')
            ->assertSee('Processo sugerido')
            ->assertSee('Secretaria sugerida')
            ->assertSee('Processo executivo');

        $this->actingAs($manager)->post(route('legislative.receive', $proposal), [
            '_submission_token' => $receiveToken = $this->token($municipality, "legislative-proposal-receive-{$proposal->id}"),
            'executive_process_number' => 'PREF-2027-0021',
            'executive_notes' => 'Documentação recebida e encaminhada para reanálise orçamentária da unidade municipal competente.',
        ])->assertSessionHas('status');
        $this->post(route('legislative.receive', $proposal), [
            '_submission_token' => $receiveToken,
            'executive_process_number' => 'PREF-2027-0021',
            'executive_notes' => 'Documentação recebida e encaminhada para reanálise orçamentária da unidade municipal competente.',
        ])->assertRedirect(route('emendas.show', $proposal->fresh()->amendment))
            ->assertSessionHas('status');

        $proposal->refresh();
        $amendment = $proposal->amendment;
        $this->assertNotNull($amendment);
        $this->assertTrue($amendment->indicated_for_health);
        $this->assertSame(1, ParliamentaryAmendment::where('reference', $proposal->reference)->count());

        $this->actingAs($manager)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.show', $proposal))
            ->assertOk()
            ->assertSee('Reserva orçamentária')
            ->assertSee('Registrar reserva')
            ->assertSee('Emenda aberta');

        $this->post(route('legislative.reserve', $proposal), [
            '_submission_token' => $this->token($municipality, "legislative-proposal-reserve-{$proposal->id}"),
            'budget_reservation_number' => 'RES-2027-0009',
            'budget_reserved_amount' => 100000,
            'budget_reserved_at' => today()->toDateString(),
            'executive_notes' => 'Dotação e disponibilidade financeira reanalisadas, com reserva integral registrada no orçamento municipal.',
        ])->assertSessionHas('status');

        $this->assertSame(LegislativeProposal::STATUS_RESERVED, $proposal->fresh()->status);
        $this->assertSame(ParliamentaryAmendment::STATUS_PLAN_PENDING, $amendment->fresh()->status);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:municipal-work-plan:create",
            'category' => 'planning',
            'status' => MunicipalWorkItem::STATUS_PENDING,
        ]);
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
            ->assertSee('Próximo passo do Executivo')
            ->assertSee('Acompanhar no fluxo executivo')
            ->assertSee('Abrir fluxo executivo')
            ->assertSee('R$ 80.000,00')
            ->assertSee('R$ 60.000,00')
            ->assertSee('R$ 50.000,00')
            ->assertSee('40%');
        $this->actingAs($councilor)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.show', $proposal))
            ->assertOk()
            ->assertSee('Próximo passo para o vereador')
            ->assertSee('Acompanhando o Executivo')
            ->assertSee('Cadastro Câmara')
            ->assertSee('Análise executiva')
            ->assertSee('Pagamento')
            ->assertSee('Esteira da proposta')
            ->assertSee('Onde esta e quem precisa agir')
            ->assertSee('Conferencia da Camara')
            ->assertSee('Recebimento municipal')
            ->assertSee('Reserva orcamentaria')
            ->assertDontSee('Abrir fluxo executivo');
    }

    public function test_executive_can_receive_proposal_with_automatic_defaults(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereador Automatico',
            'legislative_party' => 'PSD',
        ]);
        $proposal = $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_SENT,
            'protocol_number' => 'CAM-2027-044',
            'sent_at' => now(),
            'responsible_department' => 'Secretaria Municipal de Saude',
            'health_related' => true,
        ]);

        $expectedProcess = 'PREF-2027-'.Str::after($proposal->reference, 'LEG-2027-');

        $this->actingAs($manager)->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('legislative.receive', $proposal), [
                '_submission_token' => $this->token($municipality, "legislative-proposal-receive-{$proposal->id}"),
            ])->assertSessionHas('status');

        $proposal->refresh();
        $this->assertSame(LegislativeProposal::STATUS_RECEIVED, $proposal->status);
        $this->assertSame($expectedProcess, $proposal->executive_process_number);
        $this->assertStringContainsString('Recebimento automatico', $proposal->executive_notes);
        $this->assertNotNull($proposal->amendment);
        $this->assertSame($expectedProcess, $proposal->amendment->administrative_process);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$proposal->amendment->id}:municipal-work-plan:create",
            'status' => MunicipalWorkItem::STATUS_PENDING,
        ]);

        $this->post(route('legislative.reserve', $proposal), [
            '_submission_token' => $this->token($municipality, "legislative-proposal-reserve-{$proposal->id}"),
        ])->assertSessionHas('status');

        $proposal->refresh();
        $this->assertSame(LegislativeProposal::STATUS_RESERVED, $proposal->status);
        $this->assertSame('RES-2027-'.Str::after($proposal->reference, 'LEG-2027-'), $proposal->budget_reservation_number);
        $this->assertEquals((float) $proposal->estimated_amount, (float) $proposal->budget_reserved_amount);
        $this->assertStringContainsString('Reserva orcamentaria automatica', $proposal->executive_notes);
    }

    public function test_executive_sees_single_board_for_chamber_intake_and_councilor_keeps_simple_view(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereadora Mesa',
            'legislative_party' => 'PSD',
        ]);

        $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_SUBMITTED,
            'object' => 'Compra de computadores para modernizar o atendimento da secretaria municipal.',
        ]);
        $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_SENT,
            'object' => 'Aquisição de equipamentos para ampliar serviços públicos nos bairros.',
            'protocol_number' => 'CAM-2027-001',
            'sent_at' => now(),
        ]);
        $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_RECEIVED,
            'object' => 'Reforma de unidade municipal para atendimento direto aos moradores.',
            'executive_process_number' => 'PREF-2027-001',
            'received_by' => $manager->id,
            'received_at' => now(),
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.index', ['year' => 2027]))
            ->assertOk()
            ->assertSee('Mesa do Executivo')
            ->assertSee('Decisão, recebimento e reserva em uma fila')
            ->assertSee('Conferência da Câmara')
            ->assertSee('Receber no Executivo')
            ->assertSee('Reservar orçamento')
            ->assertSee('Compra de computadores')
            ->assertSee('Receber proposta')
            ->assertSee('Registrar reserva');

        $this->actingAs($councilor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.index', ['year' => 2027]))
            ->assertOk()
            ->assertSee('Minha cota')
            ->assertDontSee('Mesa do Executivo');
    }

    public function test_councilor_portal_groups_proposals_by_next_step(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereador Painel',
            'legislative_party' => 'PSD',
        ]);

        $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_RETURNED,
            'object' => 'Ajuste de proposta para compra de equipamentos de atendimento municipal.',
        ]);
        $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_SUBMITTED,
            'object' => 'Proposta aguardando conferencia da Camara Municipal.',
        ]);
        $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_SENT,
            'object' => 'Proposta protocolada e aguardando recebimento do Executivo.',
            'protocol_number' => 'CAM-2027-777',
            'sent_at' => now(),
        ]);

        $this->actingAs($councilor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.index', ['year' => 2027]))
            ->assertOk()
            ->assertSee('Meu acompanhamento')
            ->assertSee('Precisa de voc')
            ->assertSee('Com o Executivo')
            ->assertSee('Corrigir proposta')
            ->assertSee('#editor-proposta')
            ->assertSee('#acompanhamento-executivo')
            ->assertSee('Proposta protocolada e aguardando recebimento');
    }

    public function test_executive_desk_shows_focus_metrics_and_action_shortcuts(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereador Atalho',
            'legislative_party' => 'PSD',
        ]);

        $stale = $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_SENT,
            'object' => 'Equipamentos para atendimento municipal integrado.',
            'protocol_number' => 'CAM-2027-088',
            'sent_at' => now(),
        ]);
        $stale->forceFill(['updated_at' => now()->subDays(4)])->saveQuietly();
        $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_RECEIVED,
            'object' => 'Reforma de unidade de atendimento ao cidadao.',
            'executive_process_number' => 'PREF-2027-088',
            'received_by' => $manager->id,
            'received_at' => now(),
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.index', ['year' => 2027]))
            ->assertOk()
            ->assertSee('Foco recomendado agora')
            ->assertSee('Acoes pendentes')
            ->assertSee('Valor sob decisao')
            ->assertSee('Em execucao aberta')
            ->assertSee('Fora do prazo')
            ->assertSee('Fila rapida de atendimento')
            ->assertSee('Ordem sugerida pelo prazo')
            ->assertSee('prioridade(s)')
            ->assertSee('fora do prazo')
            ->assertSee('processo e pendencias serao sugeridos automaticamente')
            ->assertSee('Confirmar')
            ->assertSee('reserva integral e Plano de Trabalho serao acionados automaticamente')
            ->assertSee('Reservar')
            ->assertSee('Atenção imediata')
            ->assertSee('Abrir prioridade')
            ->assertSee('Triagem rapida do Legislativo')
            ->assertSee('1 em Receber no Executivo')
            ->assertSee('1 em Reservar or')
            ->assertSee('1 atrasada(s)')
            ->assertSee('4 dia(s)')
            ->assertSee('Filtrar')
            ->assertSee('#recebimento-executivo')
            ->assertSee('#reserva-orcamentaria');
    }

    public function test_stale_legislative_proposal_shows_internal_deadline_alert(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $profile = $this->profile($municipality, $manager);
        $councilor = $this->attach($municipality, User::ROLE_COUNCILOR, [
            'legislative_name' => 'Vereador Prazo',
            'legislative_party' => 'PSD',
        ]);
        $proposal = $this->proposal($municipality, $profile, $councilor, [
            'status' => LegislativeProposal::STATUS_SENT,
            'object' => 'Aquisicao de equipamentos para atendimento municipal integrado.',
            'protocol_number' => 'CAM-2027-099',
            'sent_at' => now()->subDays(4),
        ]);
        $proposal->forceFill(['updated_at' => now()->subDays(4)])->saveQuietly();

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('legislative.show', $proposal))
            ->assertOk()
            ->assertSee('Alerta interno de prazo')
            ->assertSee('Recebimento municipal parada ha 4 dia(s)')
            ->assertSee('Responsavel atual: Executivo')
            ->assertSee('Ir para etapa')
            ->assertSee('#recebimento-executivo');
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

    private function profile(Municipality $municipality, User $manager, ?int $year = null): MunicipalRegulatoryProfile
    {
        return $municipality->regulatoryProfiles()->create([
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
            'activated_by' => $manager->id,
            'fiscal_year' => $year ?? now()->year + 1,
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
