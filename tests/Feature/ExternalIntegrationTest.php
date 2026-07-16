<?php

namespace Tests\Feature;

use App\Models\ExternalAmendmentCandidate;
use App\Models\ExternalDataSync;
use App\Models\ExternalFinancialReconciliation;
use App\Models\FinancialCommitment;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExternalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_syncs_official_plans_by_municipality_cnpj_without_changing_local_data(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        Http::fake($this->officialResponses([$this->officialPlan()]));
        $token = $this->sessionFor($municipality, "external-sync-{$municipality->id}");

        $this->actingAs($manager)->post(route('integrations.sync'), [
            '_submission_token' => $token,
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('external_data_syncs', [
            'municipality_id' => $municipality->id,
            'status' => ExternalDataSync::STATUS_SUCCESS,
            'items_fetched' => 1,
        ]);
        $this->assertDatabaseHas('external_amendment_candidates', [
            'municipality_id' => $municipality->id,
            'external_id' => '3221',
            'external_code' => '0903-003221',
            'match_status' => ExternalAmendmentCandidate::STATUS_NEW,
        ]);
        $this->assertDatabaseCount('parliamentary_amendments', 0);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'beneficiarios_especiais')
            && $request['cnpj_beneficiario'] === $municipality->cnpj);
    }

    public function test_sync_detects_divergence_and_selected_official_field_is_applied_with_audit(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, [
            'transferegov_code' => '0903-003221',
            'author_name' => 'Iracema Portella',
            'expected_amount' => 90000,
            'object' => 'Objeto municipal confirmado',
        ]);
        Http::fake($this->officialResponses([$this->officialPlan()]));
        $syncToken = $this->sessionFor($municipality, "external-sync-{$municipality->id}");

        $this->actingAs($manager)->post(route('integrations.sync'), ['_submission_token' => $syncToken]);

        $candidate = ExternalAmendmentCandidate::firstOrFail();
        $this->assertSame($amendment->id, $candidate->parliamentary_amendment_id);
        $this->assertSame(ExternalAmendmentCandidate::STATUS_DIVERGENT, $candidate->match_status);
        $this->assertArrayHasKey('expected_amount', $candidate->differences);
        $this->assertSame(90000.0, (float) $amendment->fresh()->expected_amount);

        $applyToken = $this->sessionFor($municipality, "external-apply-{$candidate->id}");
        $this->actingAs($manager)->patch(route('integrations.candidates.apply', $candidate), [
            '_submission_token' => $applyToken,
            'fields' => ['expected_amount'],
        ])->assertSessionHas('status');

        $this->assertSame(110000.0, (float) $amendment->fresh()->expected_amount);
        $this->assertDatabaseHas('audit_logs', [
            'municipality_id' => $municipality->id,
            'action' => 'external_fields_applied',
        ]);
        $this->assertArrayNotHasKey('expected_amount', $candidate->fresh()->differences ?? []);
    }

    public function test_existing_candidate_can_be_linked_without_overwriting_amendment(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, ['expected_amount' => 50000]);
        $candidate = $this->candidate($municipality, ['expected_amount' => 110000]);
        $token = $this->sessionFor($municipality, "external-link-{$candidate->id}");

        $this->actingAs($manager)->patch(route('integrations.candidates.link', $candidate), [
            '_submission_token' => $token,
            'parliamentary_amendment_id' => $amendment->id,
        ])->assertSessionHas('status');

        $candidate->refresh();
        $this->assertSame($amendment->id, $candidate->parliamentary_amendment_id);
        $this->assertSame(ExternalAmendmentCandidate::STATUS_DIVERGENT, $candidate->match_status);
        $this->assertSame(50000.0, (float) $amendment->fresh()->expected_amount);
        $this->assertDatabaseHas('audit_logs', ['action' => 'external_candidate_linked']);
    }

    public function test_new_candidate_is_imported_only_after_required_municipal_fields_are_provided(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $candidate = $this->candidate($municipality);
        $token = $this->sessionFor($municipality, "external-import-{$candidate->id}");

        $response = $this->actingAs($manager)->post(route('integrations.candidates.import', $candidate), [
            '_submission_token' => $token,
            'reference' => '202027070006',
            'author_party' => 'PP',
            'object' => 'Implantação de infraestrutura urbana.',
            'responsible_department' => 'Secretaria de Obras',
            'responsible_user_id' => $manager->id,
            'indicated_at' => '2026-01-10',
            'communication_deadline' => '2026-02-10',
            'execution_deadline' => '2026-10-10',
            'accountability_deadline' => '2027-02-10',
        ]);

        $amendment = ParliamentaryAmendment::firstOrFail();
        $response->assertRedirect(route('emendas.show', $amendment));
        $this->assertSame('0903-003221', $amendment->transferegov_code);
        $this->assertSame(110000.0, (float) $amendment->expected_amount);
        $this->assertSame(ExternalAmendmentCandidate::STATUS_IMPORTED, $candidate->fresh()->match_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'external_candidate_imported']);
    }

    public function test_api_failure_is_recorded_with_human_feedback_and_can_be_retried(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        Http::fake(['*' => Http::response(['message' => 'temporary failure'], 503)]);
        $token = $this->sessionFor($municipality, "external-sync-{$municipality->id}");
        $payload = ['_submission_token' => $token];

        $this->actingAs($manager)
            ->post(route('integrations.sync'), $payload)
            ->assertSessionHas('warning', 'Não foi possível consultar a fonte oficial agora. A falha foi registrada para nova tentativa.');
        $this->assertDatabaseHas('external_data_syncs', [
            'municipality_id' => $municipality->id,
            'status' => ExternalDataSync::STATUS_FAILED,
        ]);
        $this->post(route('integrations.sync'), $payload)->assertSessionHas('warning', 'Esta sincronização já foi solicitada.');
    }

    public function test_financial_reconciliation_compares_equivalent_values_without_changing_local_records(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, [
            'expected_amount' => 110000,
            'received_amount' => 110000,
        ]);
        $commitment = $amendment->financialCommitments()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $manager->id,
            'commitment_number' => '2026NE001',
            'supplier_name' => 'Fornecedor Teste',
            'procurement_process' => 'PROC-001',
            'object_description' => 'Execução municipal',
            'committed_amount' => 30000,
            'committed_at' => '2026-04-10',
            'status' => FinancialCommitment::STATUS_ACTIVE,
        ]);
        $commitment->payments()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'created_by' => $manager->id,
            'payment_reference' => 'PAG-001',
            'amount' => 10000,
            'paid_at' => '2026-05-10',
        ]);
        $candidate = $this->candidate($municipality, [
            'parliamentary_amendment_id' => $amendment->id,
            'match_status' => ExternalAmendmentCandidate::STATUS_MATCHED,
        ]);
        Http::fake(fn ($request) => $this->financialResponseFor($request->url()));
        $token = $this->sessionFor($municipality, "external-financial-{$candidate->id}");

        $this->actingAs($manager)->post(route('integrations.candidates.financial', $candidate), [
            '_submission_token' => $token,
        ])->assertSessionHas('status');

        $reconciliation = ExternalFinancialReconciliation::firstOrFail();
        $this->assertSame(110000.0, (float) $reconciliation->official_committed_amount);
        $this->assertSame(110000.0, (float) $reconciliation->official_ordered_amount);
        $this->assertSame(100500.0, (float) $reconciliation->official_account_balance);
        $this->assertSame(30000.0, (float) $reconciliation->local_committed_amount);
        $this->assertSame(10000.0, (float) $reconciliation->local_paid_amount);
        $this->assertSame(100000.0, (float) $reconciliation->local_estimated_balance);
        $this->assertSame(500.0, (float) $reconciliation->differences['account_balance']['difference']);
        $this->assertSame(ExternalFinancialReconciliation::STATUS_DIVERGENT, $reconciliation->status);
        $this->assertCount(3, $reconciliation->official_payment_orders);
        $this->assertSame(110000.0, (float) $amendment->fresh()->received_amount);
        $this->assertDatabaseCount('financial_payments', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'external_financial_reconciled']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'empenhos_especiais')
            && (int) $request['id_plano_acao'] === 3221);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'saldo_conta_gestao_financeira_especiais')
            && $request['id_agencia_conta'] === '1428-34782');

        $amendment->update(['received_amount' => null]);
        $secondToken = $this->sessionFor($municipality, "external-financial-{$candidate->id}");
        $this->actingAs($manager)->post(route('integrations.candidates.financial', $candidate), [
            '_submission_token' => $secondToken,
        ])->assertSessionHas('status');
        $this->assertSame(
            ExternalFinancialReconciliation::STATUS_INCOMPLETE,
            $candidate->financialReconciliations()->firstOrFail()->status,
        );
    }

    public function test_financial_failure_is_recorded_and_duplicate_submission_is_blocked(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $candidate = $this->candidate($municipality);
        Http::fake(['*' => Http::response(['message' => 'temporary failure'], 503)]);
        $token = $this->sessionFor($municipality, "external-financial-{$candidate->id}");
        $payload = ['_submission_token' => $token];

        $this->actingAs($manager)
            ->post(route('integrations.candidates.financial', $candidate), $payload)
            ->assertSessionHas('warning', 'A consulta financeira oficial não respondeu corretamente. A falha foi registrada e pode ser tentada novamente.');

        $this->assertDatabaseHas('external_financial_reconciliations', [
            'external_amendment_candidate_id' => $candidate->id,
            'status' => ExternalFinancialReconciliation::STATUS_FAILED,
        ]);
        $this->post(route('integrations.candidates.financial', $candidate), $payload)
            ->assertSessionHas('warning', 'Esta conciliação financeira já foi solicitada.');
        $this->assertDatabaseCount('external_financial_reconciliations', 1);
    }

    public function test_integration_access_respects_roles_and_active_municipality(): void
    {
        [$viewer, $municipality] = $this->memberWithMunicipality(User::ROLE_VIEWER);
        $candidate = $this->candidate($municipality);

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('Caixa de conferência')
            ->assertDontSee('Consultar fonte oficial');
        $this->post(route('integrations.sync'), [])->assertForbidden();
        $this->post(route('integrations.candidates.financial', $candidate), [])->assertForbidden();

        [$otherManager, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $token = $this->sessionFor($otherMunicipality, "external-ignore-{$candidate->id}");
        $this->actingAs($otherManager)->patch(route('integrations.candidates.ignore', $candidate), [
            '_submission_token' => $token,
            'review_notes' => 'Não pertence ao município ativo.',
        ])->assertNotFound();
    }

    /** @return array<string, callable> */
    private function officialResponses(array $plans): array
    {
        return [
            '*/data-atualizacao' => Http::response(['data_ultima_atualizacao' => '2026-07-16T00:00:00']),
            '*/beneficiarios_especiais*' => Http::response([
                'data' => [[
                    'id_beneficiario' => 7052,
                    'nome_beneficiario' => 'MUNICIPIO TESTE',
                    'cnpj_beneficiario' => '18303263000135',
                ]],
                'total_pages' => 1,
            ]),
            '*/planos_acao_especiais*' => Http::response([
                'data' => $plans,
                'total_pages' => 1,
                'total_items' => count($plans),
                'page_number' => 1,
                'page_size' => 200,
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function officialPlan(): array
    {
        return [
            'id_plano_acao' => 3221,
            'codigo_plano_acao' => '0903-003221',
            'ano_plano_acao' => 2026,
            'situacao_plano_acao' => 'CIENTE',
            'data_aceite_plano_acao' => '2026-01-10',
            'descricao_situacao_dado_bancario_plano_acao' => 'Conta Ativa',
            'nome_parlamentar_emenda_plano_acao' => 'Iracema Portella',
            'ano_emenda_parlamentar_plano_acao' => 2026,
            'numero_emenda_parlamentar_plano_acao' => 202627070006,
            'valor_custeio_plano_acao' => 30000,
            'valor_investimento_plano_acao' => 80000,
            'detalhamento_objeto' => null,
            'nome_objeto' => null,
            'id_beneficiario' => 7052,
            'id_agencia_conta' => '1428-34782',
        ];
    }

    private function financialResponseFor(string $url)
    {
        if (str_contains($url, 'empenhos_especiais')) {
            return Http::response([
                'data' => [
                    ['id_empenho' => 4904, 'numero_empenho' => '2026NE001', 'valor_empenho' => 30000, 'data_emissao_empenho' => '2026-01-10', 'descricao_situacao_empenho' => 'Enviado'],
                    ['id_empenho' => 3653, 'numero_empenho' => '2026NE002', 'valor_empenho' => 80000, 'data_emissao_empenho' => '2026-01-10', 'descricao_situacao_empenho' => 'Enviado'],
                ],
                'total_pages' => 1,
            ]);
        }

        if (str_contains($url, 'documentos_habeis_especiais')) {
            $document = str_contains($url, 'id_empenho=4904')
                ? ['id_dh' => 21257, 'numero_documento_habil' => '2026TF001', 'valor_dh' => 30000]
                : ['id_dh' => 21258, 'numero_documento_habil' => '2026TF002', 'valor_dh' => 80000];

            return Http::response(['data' => [$document], 'total_pages' => 1]);
        }

        if (str_contains($url, 'ordens_pagamentos_ordens_bancarias_especiais')) {
            $documentId = str_contains($url, 'id_dh=21257') ? 21257 : 21258;
            $orders = [[
                'id_op_ob' => $documentId,
                'id_dh' => $documentId,
                'numero_ordem_bancaria' => '2026OB'.$documentId,
                'data_emissao_ob' => '2026-02-10',
                'descricao_situacao_op' => 'Enviada ao banco',
            ]];
            if ($documentId === 21257) {
                $orders[] = [
                    'id_op_ob' => 99999,
                    'id_dh' => $documentId,
                    'numero_ordem_bancaria' => '2026OB99999',
                    'data_emissao_ob' => '2026-02-10',
                    'descricao_situacao_op' => 'Reprocessada',
                ];
            }

            return Http::response(['data' => $orders, 'total_pages' => 1]);
        }

        if (str_contains($url, 'saldo_conta_gestao_financeira_especiais')) {
            return Http::response(['data' => [[
                'id_agencia_conta' => '1428-34782',
                'data_saldo_conta' => '2026-07-15',
                'saldo_final_gestao_financeira' => 100500,
            ]], 'total_pages' => 1]);
        }

        return Http::response(['data_ultima_atualizacao' => '2026-07-16T00:00:00']);
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

    private function candidate(Municipality $municipality, array $attributes = []): ExternalAmendmentCandidate
    {
        return $municipality->externalAmendmentCandidates()->create([
            'source' => ExternalDataSync::SOURCE_TRANSFEREGOV_SPECIAL,
            'external_id' => '3221',
            'external_code' => '0903-003221',
            'amendment_code' => '202627070006',
            'fiscal_year' => 2026,
            'author_name' => 'Iracema Portella',
            'expected_amount' => 110000,
            'external_status' => 'CIENTE',
            'bank_status' => 'Conta Ativa',
            'match_status' => ExternalAmendmentCandidate::STATUS_NEW,
            'payload' => $this->officialPlan(),
            'source_hash' => hash('sha256', 'candidate-test'),
            'last_seen_at' => now(),
            ...$attributes,
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
