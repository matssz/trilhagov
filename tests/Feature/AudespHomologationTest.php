<?php

namespace Tests\Feature;

use App\Models\AudespAmendmentRegistration;
use App\Models\AudespHomologationBatch;
use App\Models\AudespHomologationItem;
use App\Models\FinancialCommitment;
use App\Models\LegislativeProposal;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class AudespHomologationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_imports_siafic_xml_and_gets_ready_batch_with_preserved_hash(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $registration = $this->registration($municipality, $manager);
        $xml = $this->xml();
        $token = $this->sessionFor($municipality, 'audesp-homologation-upload');

        $response = $this->actingAs($manager)->post(route('audesp-homologations.store'), [
            '_submission_token' => $token,
            'fiscal_year' => 2026,
            'reference_month' => 7,
            'source_system' => 'Siafic Municipal',
            'source_version' => '4.2',
            'source_file' => UploadedFile::fake()->createWithContent('cadastro-emendas.xml', $xml),
        ]);

        $batch = AudespHomologationBatch::firstOrFail();
        $response->assertRedirect(route('audesp-homologations.show', $batch));
        $this->assertSame(AudespHomologationBatch::STATUS_READY, $batch->status);
        $this->assertSame(hash('sha256', $xml), $batch->source_sha256);
        $this->assertSame(1, $batch->matched_count);
        $this->assertDatabaseHas('audesp_homologation_items', [
            'audesp_amendment_registration_id' => $registration->id,
            'status' => AudespHomologationItem::STATUS_MATCHED,
        ]);
        Storage::disk('local')->assertExists($batch->source_storage_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'audesp_homologation_created']);

        $duplicateToken = $this->sessionFor($municipality, 'audesp-homologation-upload');
        $this->post(route('audesp-homologations.store'), [
            '_submission_token' => $duplicateToken,
            'fiscal_year' => 2026,
            'reference_month' => 7,
            'source_system' => 'Siafic Municipal',
            'source_file' => UploadedFile::fake()->createWithContent('copia.xml', $xml),
        ])->assertSessionHasErrors('source_file');
        $this->assertDatabaseCount('audesp_homologation_batches', 1);
    }

    public function test_divergent_batch_can_be_rechecked_after_local_correction(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $registration = $this->registration($municipality, $manager, ['purpose' => 'Finalidade divergente registrada localmente.']);
        $token = $this->sessionFor($municipality, 'audesp-homologation-upload');
        $this->actingAs($manager)->post(route('audesp-homologations.store'), [
            '_submission_token' => $token,
            'fiscal_year' => 2026,
            'reference_month' => 7,
            'source_system' => 'Contabilidade ABC',
            'source_file' => UploadedFile::fake()->createWithContent('divergente.xml', $this->xml()),
        ])->assertRedirect();

        $batch = AudespHomologationBatch::firstOrFail();
        $this->assertSame(AudespHomologationBatch::STATUS_UNDER_REVIEW, $batch->status);
        $this->assertSame('purpose', $batch->items->first()->differences[0]['field']);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$registration->parliamentary_amendment_id}:audesp-homologation:{$batch->id}",
        ]);
        $this->assertDatabaseHas('integrity_alerts', [
            'alert_key' => "audesp:homologation:{$batch->id}",
            'severity' => 'critical',
        ]);

        $registration->update(['purpose' => 'Ampliar atendimento da atenção básica municipal.']);
        $recheckToken = $this->sessionFor($municipality, "audesp-homologation-recheck-{$batch->id}");
        $this->post(route('audesp-homologations.recheck', $batch), [
            '_submission_token' => $recheckToken,
        ])->assertSessionHas('status');

        $this->assertSame(AudespHomologationBatch::STATUS_READY, $batch->fresh()->status);
        $this->assertSame(AudespHomologationItem::STATUS_MATCHED, $batch->items()->first()->status);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$registration->parliamentary_amendment_id}:audesp-homologation:{$batch->id}",
            'status' => 'completed',
        ]);
    }

    public function test_monthly_financial_xml_reconciles_portal_reservation_and_execution_events(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $registration = $this->registration($municipality, $manager);
        $this->financialExecution($registration->amendment, $municipality, $manager);
        $token = $this->sessionFor($municipality, 'audesp-homologation-upload');

        $response = $this->actingAs($manager)->post(route('audesp-homologations.store'), [
            '_submission_token' => $token,
            'fiscal_year' => 2026,
            'reference_month' => 7,
            'source_system' => 'Siafic Municipal',
            'source_file' => UploadedFile::fake()->createWithContent('movimento-mensal.xml', $this->monthlyFinancialXml()),
        ]);

        $batch = AudespHomologationBatch::firstOrFail();
        $response->assertRedirect(route('audesp-homologations.show', $batch));
        $this->assertSame(AudespHomologationBatch::TYPE_MONTHLY_FINANCIAL, $batch->source_document_type);
        $this->assertSame(AudespHomologationBatch::STATUS_READY, $batch->status);
        $this->assertSame(1, $batch->matched_count);
        $item = $batch->items()->firstOrFail();
        $this->assertSame($registration->id, $item->audesp_amendment_registration_id);
        $this->assertSame('1000.00', $item->source_snapshot['pre_commitment_amount']);
        $this->assertSame('800.00', $item->local_snapshot['committed_amount']);
        $this->assertNull($item->differences);
        $this->get(route('audesp-homologations.show', $batch))
            ->assertOk()
            ->assertSee('Movimento contábil mensal')
            ->assertSee('Execução financeira da emenda');
    }

    public function test_monthly_financial_xml_flags_unknown_application_code_without_guessing_a_link(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $this->registration($municipality, $manager);
        $token = $this->sessionFor($municipality, 'audesp-homologation-upload');

        $this->actingAs($manager)->post(route('audesp-homologations.store'), [
            '_submission_token' => $token,
            'fiscal_year' => 2026,
            'reference_month' => 7,
            'source_system' => 'Siafic Municipal',
            'source_file' => UploadedFile::fake()->createWithContent(
                'codigo-sem-vinculo.xml',
                $this->monthlyFinancialXml('8999'),
            ),
        ])->assertRedirect();

        $batch = AudespHomologationBatch::firstOrFail();
        $this->assertSame(AudespHomologationBatch::STATUS_UNDER_REVIEW, $batch->status);
        $this->assertSame(1, $batch->unmatched_count);
        $this->assertDatabaseHas('audesp_homologation_items', [
            'audesp_homologation_batch_id' => $batch->id,
            'parliamentary_amendment_id' => null,
            'status' => AudespHomologationItem::STATUS_UNMATCHED,
            'source_amendment_number' => 'Cód. 8999',
        ]);
    }

    public function test_monthly_financial_divergence_creates_municipal_work_and_integrity_alert(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $registration = $this->registration($municipality, $manager);
        $this->financialExecution($registration->amendment, $municipality, $manager);
        $token = $this->sessionFor($municipality, 'audesp-homologation-upload');

        $this->actingAs($manager)->post(route('audesp-homologations.store'), [
            '_submission_token' => $token,
            'fiscal_year' => 2026,
            'reference_month' => 7,
            'source_system' => 'Siafic Municipal',
            'source_file' => UploadedFile::fake()->createWithContent(
                'movimento-divergente.xml',
                $this->monthlyFinancialXml('8001', 750),
            ),
        ])->assertRedirect();

        $batch = AudespHomologationBatch::firstOrFail();
        $this->assertSame(AudespHomologationBatch::STATUS_UNDER_REVIEW, $batch->status);
        $this->assertSame('committed_amount', $batch->items()->firstOrFail()->differences[0]['field']);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$registration->parliamentary_amendment_id}:audesp-homologation:{$batch->id}",
            'title' => 'Conciliar execução financeira com o Siafic',
        ]);
        $this->assertDatabaseHas('integrity_alerts', [
            'alert_key' => "audesp:homologation:{$batch->id}",
            'title' => 'Execução financeira Audesp divergente',
            'severity' => 'critical',
        ]);
    }

    public function test_submission_and_rejection_keep_evidence_and_create_correction_work(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $registration = $this->registration($municipality, $manager);
        $batch = $this->readyBatch($municipality, $manager, $registration);

        $submissionToken = $this->sessionFor($municipality, "audesp-homologation-submit-{$batch->id}");
        $this->actingAs($manager)->post(route('audesp-homologations.submission', $batch), [
            '_submission_token' => $submissionToken,
            'external_protocol' => 'PACOTE-2026-00042',
            'submitted_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ])->assertSessionHas('status');
        $this->assertSame(AudespHomologationBatch::STATUS_SUBMITTED, $batch->fresh()->status);

        $returnToken = $this->sessionFor($municipality, "audesp-homologation-return-{$batch->id}");
        $this->post(route('audesp-homologations.return', $batch), [
            '_submission_token' => $returnToken,
            'external_status' => AudespHomologationBatch::STATUS_REJECTED,
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'protocol' => 'RECIBO-2026-9001',
            'issue_code' => '47.4.63',
            'issue_field' => 'EmendasParlamentares/CodigoAplicacao',
            'message' => 'Código de aplicação sem cadastro correspondente no movimento.',
            'evidence' => UploadedFile::fake()->createWithContent('retorno.txt', 'Documento rejeitado: 47.4.63'),
        ])->assertSessionHas('status');

        $batch->refresh();
        $this->assertSame(AudespHomologationBatch::STATUS_REJECTED, $batch->status);
        $event = $batch->events()->where('type', 'rejection_recorded')->firstOrFail();
        Storage::disk('local')->assertExists($event->evidence_storage_path);
        $this->assertSame(hash('sha256', 'Documento rejeitado: 47.4.63'), $event->evidence_sha256);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$registration->parliamentary_amendment_id}:audesp-rejection:{$batch->id}",
            'priority' => 'critical',
        ]);
        $this->assertDatabaseHas('integrity_alerts', [
            'alert_key' => "audesp:rejection:{$batch->id}",
            'severity' => 'critical',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'audesp_return_recorded']);

        $this->expectException(LogicException::class);
        $event->update(['message' => 'Tentativa de alteração posterior.']);
    }

    public function test_module_is_limited_to_municipalities_in_tcesp_scope(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $municipality->update(['state' => 'MG']);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('audesp-homologations.index'))
            ->assertNotFound();
    }

    public function test_viewer_can_read_own_municipality_but_cannot_change_or_cross_tenants(): void
    {
        [$viewer, $municipality] = $this->memberWithMunicipality(User::ROLE_VIEWER);
        [$otherManager, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $batch = AudespHomologationBatch::create([
            'municipality_id' => $otherMunicipality->id,
            'created_by' => $otherManager->id,
            'reference' => (string) Str::uuid(),
            'fiscal_year' => 2026,
            'reference_month' => 7,
            'source_system' => 'Outro Siafic',
            'schema_version' => '2026_A',
            'status' => AudespHomologationBatch::STATUS_UNDER_REVIEW,
            'source_original_name' => 'outro.xml',
            'source_storage_path' => 'private/outro.xml',
            'source_mime_type' => 'application/xml',
            'source_size_bytes' => 10,
            'source_sha256' => str_repeat('a', 64),
        ]);

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('audesp-homologations.index'))
            ->assertOk()
            ->assertDontSee('Novo lote de conferência');
        $this->get(route('audesp-homologations.show', $batch))->assertNotFound();
        $this->post(route('audesp-homologations.store'), [])->assertForbidden();
    }

    public function test_xml_with_doctype_is_rejected_without_being_stored(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $token = $this->sessionFor($municipality, 'audesp-homologation-upload');
        $xml = '<?xml version="1.0"?><!DOCTYPE data [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><data><EmendasParlamentares><NumeroEmenda>&xxe;</NumeroEmenda></EmendasParlamentares></data>';

        $this->actingAs($manager)->post(route('audesp-homologations.store'), [
            '_submission_token' => $token,
            'fiscal_year' => 2026,
            'reference_month' => 7,
            'source_system' => 'Siafic',
            'source_file' => UploadedFile::fake()->createWithContent('inseguro.xml', $xml),
        ])->assertSessionHasErrors('source_file');

        $this->assertDatabaseCount('audesp_homologation_batches', 0);
        Storage::disk('local')->assertDirectoryEmpty('audesp-homologations');
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

    private function registration(Municipality $municipality, User $user, array $attributes = []): AudespAmendmentRegistration
    {
        $amendment = ParliamentaryAmendment::factory()->for($municipality)->for($user, 'creator')->create([
            'government_sphere' => 'municipal',
            'responsible_user_id' => $user->id,
        ]);

        return $amendment->audespRegistration()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $user->id,
            'scope' => 'M',
            'amendment_type' => 2,
            'legal_basis' => 'Lei',
            'proponent_name' => 'Vereador João Municipal',
            'amendment_number' => 'EM-2026-010',
            'amendment_year' => 2026,
            'object' => 'Modernização da unidade básica de saúde municipal.',
            'purpose' => 'Ampliar atendimento da atenção básica municipal.',
            'government_function' => '10',
            'government_subfunctions' => ['301', '302'],
            'destination' => 'C',
            'bank_account_opened' => false,
            'application_code' => '8001',
            'prepared_at' => now(),
            ...$attributes,
        ]);
    }

    private function readyBatch(Municipality $municipality, User $user, AudespAmendmentRegistration $registration): AudespHomologationBatch
    {
        $batch = $municipality->audespHomologationBatches()->create([
            'created_by' => $user->id,
            'reference' => (string) Str::uuid(),
            'fiscal_year' => 2026,
            'reference_month' => 7,
            'source_system' => 'Siafic Municipal',
            'schema_version' => '2026_A',
            'status' => AudespHomologationBatch::STATUS_READY,
            'source_original_name' => 'cadastro.xml',
            'source_storage_path' => 'audesp-homologations/source.xml',
            'source_mime_type' => 'application/xml',
            'source_size_bytes' => 100,
            'source_sha256' => str_repeat('b', 64),
            'item_count' => 1,
            'matched_count' => 1,
        ]);
        $batch->items()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $registration->parliamentary_amendment_id,
            'audesp_amendment_registration_id' => $registration->id,
            'status' => AudespHomologationItem::STATUS_MATCHED,
            'source_scope' => 'M',
            'source_amendment_number' => 'EM-2026-010',
            'source_amendment_year' => 2026,
            'source_snapshot' => [],
            'local_snapshot' => [],
        ]);

        return $batch;
    }

    private function financialExecution(ParliamentaryAmendment $amendment, Municipality $municipality, User $user): void
    {
        $municipality->legislativeProposals()->create([
            'submitted_by' => $user->id,
            'parliamentary_amendment_id' => $amendment->id,
            'reference' => 'LEG-2026-010',
            'fiscal_year' => 2026,
            'author_name' => 'Vereador João Municipal',
            'author_party' => 'PSD',
            'object' => 'Modernização da unidade básica de saúde municipal.',
            'justification' => 'Ampliar o atendimento municipal.',
            'priority' => 'high',
            'beneficiary_type' => 'municipal_body',
            'beneficiary_name' => 'Secretaria Municipal de Saúde',
            'beneficiary_location' => 'Centro',
            'expense_destination' => 'investment',
            'transfer_type' => 'direct_execution',
            'health_related' => true,
            'responsible_department' => 'Secretaria Municipal de Saúde',
            'public_need' => 'Modernizar o atendimento da atenção básica.',
            'estimated_amount' => 1000,
            'estimate_source' => 'Pesquisa municipal de preços',
            'status' => LegislativeProposal::STATUS_RESERVED,
            'budget_reservation_number' => 'RES-2026-010',
            'budget_reserved_amount' => 1000,
            'budget_reserved_at' => '2026-07-02',
        ]);
        $commitment = $amendment->financialCommitments()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $user->id,
            'commitment_number' => '2026NE0001',
            'supplier_name' => 'Fornecedor Municipal Ltda',
            'procurement_process' => 'PROC-2026-010',
            'object_description' => 'Equipamentos para a unidade municipal.',
            'committed_amount' => 800,
            'committed_at' => '2026-07-10',
            'status' => FinancialCommitment::STATUS_ACTIVE,
        ]);
        $liquidation = $commitment->liquidations()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'created_by' => $user->id,
            'liquidation_reference' => '2026NL0001',
            'amount' => 600,
            'liquidated_at' => '2026-07-20',
            'supporting_document' => 'NF-100',
            'acceptance_reference' => 'ATESTO-100',
        ]);
        $commitment->payments()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'financial_liquidation_id' => $liquidation->id,
            'created_by' => $user->id,
            'payment_reference' => '2026OB0001',
            'amount' => 500,
            'paid_at' => '2026-07-25',
        ]);
    }

    private function monthlyFinancialXml(string $applicationCode = '8001', float $committedAmount = 800): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<DetalheMovimentoMensal xmlns="http://www.tce.sp.gov.br/audesp/xml/dadoscontabeis">
  <Descritor><AnoExercicio>2026</AnoExercicio><MesReferencia>7</MesReferencia></Descritor>
  <ContasCorrentes>
    <DotacaoOrcamentaria>
      <CodigoAplicacao>0001</CodigoAplicacao><ContaContabil>522110100</ContaContabil>
      <MovimentoContabil><SaldoInicial>0</SaldoInicial><NatInicial>D</NatInicial><MovimentoCredito>50000</MovimentoCredito><MovimentoDebito>0</MovimentoDebito><SaldoFinal>50000</SaldoFinal><NatFinal>D</NatFinal></MovimentoContabil>
    </DotacaoOrcamentaria>
    <DotacaoOrcamentaria>
      <CodigoAplicacao>{$applicationCode}</CodigoAplicacao><ContaContabil>522910100</ContaContabil>
      <MovimentoContabil><SaldoInicial>0</SaldoInicial><NatInicial>D</NatInicial><MovimentoCredito>1000</MovimentoCredito><MovimentoDebito>0</MovimentoDebito><SaldoFinal>1000</SaldoFinal><NatFinal>D</NatFinal></MovimentoContabil>
    </DotacaoOrcamentaria>
    <EmissaoEmpenho>
      <EntidadeOrcamentaria>1</EntidadeOrcamentaria><CodigoAplicacao>{$applicationCode}</CodigoAplicacao><NumeroEmpenho>2026NE0001</NumeroEmpenho><ContaContabil>522920101</ContaContabil>
      <MovimentoContabil><SaldoInicial>0</SaldoInicial><NatInicial>D</NatInicial><MovimentoCredito>{$committedAmount}</MovimentoCredito><MovimentoDebito>0</MovimentoDebito><SaldoFinal>{$committedAmount}</SaldoFinal><NatFinal>D</NatFinal></MovimentoContabil>
    </EmissaoEmpenho>
    <EmissaoEmpenho>
      <EntidadeOrcamentaria>1</EntidadeOrcamentaria><CodigoAplicacao>{$applicationCode}</CodigoAplicacao><NumeroEmpenho>2026NE0001</NumeroEmpenho><ContaContabil>853210000</ContaContabil>
      <MovimentoContabil><SaldoInicial>0</SaldoInicial><NatInicial>D</NatInicial><MovimentoCredito>{$committedAmount}</MovimentoCredito><MovimentoDebito>0</MovimentoDebito><SaldoFinal>{$committedAmount}</SaldoFinal><NatFinal>D</NatFinal></MovimentoContabil>
    </EmissaoEmpenho>
    <LiquidacaoEmpenho>
      <EntidadeOrcamentaria>1</EntidadeOrcamentaria><NumeroEmpenho>2026NE0001</NumeroEmpenho><ContaContabil>622920103</ContaContabil>
      <MovimentoContabil><SaldoInicial>0</SaldoInicial><NatInicial>D</NatInicial><MovimentoCredito>600</MovimentoCredito><MovimentoDebito>0</MovimentoDebito><SaldoFinal>600</SaldoFinal><NatFinal>D</NatFinal></MovimentoContabil>
    </LiquidacaoEmpenho>
    <PagamentoEmpenho>
      <EntidadeOrcamentaria>1</EntidadeOrcamentaria><NumeroEmpenho>2026NE0001</NumeroEmpenho><ContaContabil>622920104</ContaContabil>
      <MovimentoContabil><SaldoInicial>0</SaldoInicial><NatInicial>D</NatInicial><MovimentoCredito>500</MovimentoCredito><MovimentoDebito>0</MovimentoDebito><SaldoFinal>500</SaldoFinal><NatFinal>D</NatFinal></MovimentoContabil>
    </PagamentoEmpenho>
  </ContasCorrentes>
</DetalheMovimentoMensal>
XML;
    }

    private function xml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<cadc:CadastrosContabeis xmlns:cadc="http://www.tce.sp.gov.br/audesp/xml/cadastroscontabeis">
  <cadc:EmendasParlamentares>
    <cadc:AmbitoEmenda>M</cadc:AmbitoEmenda>
    <cadc:TipoEmenda>2</cadc:TipoEmenda>
    <cadc:FundamentoLegal>Lei</cadc:FundamentoLegal>
    <cadc:ParlamentarProponente>Vereador João Municipal</cadc:ParlamentarProponente>
    <cadc:NumeroEmenda>EM-2026-010</cadc:NumeroEmenda>
    <cadc:AnoEmenda>2026</cadc:AnoEmenda>
    <cadc:ObjetoEmenda>Modernização da unidade básica de saúde municipal.</cadc:ObjetoEmenda>
    <cadc:FinalidadeEmenda>Ampliar atendimento da atenção básica municipal.</cadc:FinalidadeEmenda>
    <cadc:Funcao>10</cadc:Funcao>
    <cadc:SubFuncao>301</cadc:SubFuncao>
    <cadc:SubFuncao>302</cadc:SubFuncao>
    <cadc:DestinacaoEmenda>C</cadc:DestinacaoEmenda>
    <cadc:AberturaContaBancaria>N</cadc:AberturaContaBancaria>
    <cadc:CodigoAplicacao>8001</cadc:CodigoAplicacao>
  </cadc:EmendasParlamentares>
</cadc:CadastrosContabeis>
XML;
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
