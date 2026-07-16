<?php

namespace Tests\Feature;

use App\Models\ExternalAmendmentCandidate;
use App\Models\ExternalDataSync;
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
        ];
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
