<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParliamentaryAmendmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_amendments(): void
    {
        $this->get(route('emendas.index'))->assertRedirect(route('login'));
    }

    public function test_user_can_create_amendment_for_their_municipality(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();
        $payload = $this->payloadWithToken('amendment-create');

        $response = $this->actingAs($user)->post(route('emendas.store'), $payload);

        $amendment = $municipality->amendments()->firstOrFail();

        $response->assertRedirect(route('emendas.show', $amendment));
        $this->assertSame($user->id, $amendment->created_by);
        $this->assertSame('EM-2026-001', $amendment->reference);
        $this->assertSame('500000.00', $amendment->expected_amount);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('EM-2026-001')
            ->assertSee('R$ 500.000,00');
    }

    public function test_user_sees_only_amendments_from_their_municipality(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();
        [$otherUser, $otherMunicipality] = $this->userAndMunicipality();
        $this->createAmendment($municipality, $user, ['reference' => 'EM-VISIVEL']);
        $this->createAmendment($otherMunicipality, $otherUser, ['reference' => 'EM-PROTEGIDA']);

        $this->actingAs($user)
            ->get(route('emendas.index'))
            ->assertOk()
            ->assertSee('EM-VISIVEL')
            ->assertDontSee('EM-PROTEGIDA');
    }

    public function test_active_municipality_scopes_validation_and_amendment_access(): void
    {
        [$user, $firstMunicipality] = $this->userAndMunicipality();
        $secondMunicipality = Municipality::factory()->create();
        $secondMunicipality->users()->attach($user, ['role' => 'manager']);
        $firstAmendment = $this->createAmendment($firstMunicipality, $user, [
            'reference' => 'EM-COMPARTILHADA',
        ]);
        $payload = $this->payloadWithToken('amendment-create', [
            'reference' => 'EM-COMPARTILHADA',
        ]);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $secondMunicipality->id])
            ->post(route('emendas.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('parliamentary_amendments', [
            'municipality_id' => $secondMunicipality->id,
            'reference' => 'EM-COMPARTILHADA',
        ]);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $secondMunicipality->id])
            ->get(route('emendas.show', $firstAmendment))
            ->assertNotFound();
    }

    public function test_user_cannot_view_or_update_another_municipalitys_amendment(): void
    {
        [$user] = $this->userAndMunicipality();
        [$otherUser, $otherMunicipality] = $this->userAndMunicipality();
        $amendment = $this->createAmendment($otherMunicipality, $otherUser);

        $this->actingAs($user)
            ->get(route('emendas.show', $amendment))
            ->assertNotFound();

        $payload = $this->payloadWithToken(
            "amendment-update-{$amendment->id}",
            ['reference' => 'TENTATIVA-ALTERACAO'],
        );

        $this->actingAs($user)
            ->put(route('emendas.update', $amendment), $payload)
            ->assertNotFound();

        $this->assertNotSame('TENTATIVA-ALTERACAO', $amendment->fresh()->reference);
    }

    public function test_reference_cannot_repeat_in_same_sphere_and_year(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();
        $this->createAmendment($municipality, $user, ['reference' => 'EM-DUPLICADA']);
        $payload = $this->payloadWithToken('amendment-create', ['reference' => 'EM-DUPLICADA']);

        $this->actingAs($user)
            ->post(route('emendas.store'), $payload)
            ->assertSessionHasErrors('reference');

        $this->assertDatabaseCount('parliamentary_amendments', 1);
    }

    public function test_repeated_create_request_is_processed_only_once(): void
    {
        [$user] = $this->userAndMunicipality();
        $payload = $this->payloadWithToken('amendment-create');

        $this->actingAs($user)
            ->post(route('emendas.store'), $payload)
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('emendas.store'), array_merge($payload, ['reference' => 'EM-SEGUNDA']))
            ->assertRedirect();

        $this->assertDatabaseCount('parliamentary_amendments', 1);
        $this->assertDatabaseMissing('parliamentary_amendments', ['reference' => 'EM-SEGUNDA']);
    }

    public function test_user_can_update_amendment_status_and_received_amount(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();
        $amendment = $this->createAmendment($municipality, $user);
        $payload = $this->payloadWithToken("amendment-update-{$amendment->id}", [
            'reference' => $amendment->reference,
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
            'received_amount' => '450000.00',
            'received_at' => '2026-07-10',
        ]);

        $this->actingAs($user)
            ->put(route('emendas.update', $amendment), $payload)
            ->assertRedirect(route('emendas.show', $amendment));

        $this->assertDatabaseHas('parliamentary_amendments', [
            'id' => $amendment->id,
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
            'received_amount' => 450000,
        ]);
    }

    public function test_repeated_update_request_does_not_apply_a_second_change(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();
        $amendment = $this->createAmendment($municipality, $user);
        $payload = $this->payloadWithToken("amendment-update-{$amendment->id}", [
            'reference' => $amendment->reference,
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
            'received_amount' => '450000.00',
            'received_at' => '2026-07-10',
            'notes' => 'Primeira alteracao',
        ]);

        $this->actingAs($user)->put(route('emendas.update', $amendment), $payload);

        $this->actingAs($user)->put(
            route('emendas.update', $amendment),
            array_merge($payload, ['notes' => 'Alteracao repetida']),
        )->assertRedirect(route('emendas.show', $amendment));

        $this->assertSame('Primeira alteracao', $amendment->fresh()->notes);
    }

    public function test_execution_status_requires_received_resource_information_in_portuguese(): void
    {
        [$user] = $this->userAndMunicipality();
        $payload = $this->payloadWithToken('amendment-create', [
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
            'received_amount' => null,
            'received_at' => null,
        ]);

        $this->actingAs($user)
            ->post(route('emendas.store'), $payload)
            ->assertSessionHasErrors([
                'received_amount' => 'Informe o valor recebido para a situação selecionada.',
                'received_at' => 'Informe a data do recebimento para a situação selecionada.',
            ]);

        $this->assertDatabaseCount('parliamentary_amendments', 0);
    }

    public function test_core_dates_are_required_with_human_messages(): void
    {
        [$user] = $this->userAndMunicipality();
        $payload = $this->payloadWithToken('amendment-create', [
            'indicated_at' => null,
            'communication_deadline' => null,
            'execution_deadline' => null,
            'accountability_deadline' => null,
        ]);

        $this->actingAs($user)
            ->post(route('emendas.store'), $payload)
            ->assertSessionHasErrors([
                'indicated_at' => 'Informe a data da indicação.',
                'communication_deadline' => 'Informe o prazo de comunicação e publicidade.',
                'execution_deadline' => 'Informe o prazo de execução.',
                'accountability_deadline' => 'Informe o prazo de prestação de contas.',
            ]);

        $this->assertDatabaseCount('parliamentary_amendments', 0);
    }

    public function test_overdue_deadline_stops_alerting_after_completion(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();
        $amendment = $this->createAmendment($municipality, $user, [
            'communication_deadline' => today()->subDay(),
            'execution_deadline' => today()->addMonths(2),
        ]);

        $this->actingAs($user)
            ->get(route('emendas.show', $amendment))
            ->assertOk()
            ->assertSee('Há prazo vencido nesta emenda');

        $amendment->update(['communication_completed_at' => today()]);

        $this->actingAs($user)
            ->get(route('emendas.show', $amendment))
            ->assertOk()
            ->assertDontSee('Há prazo vencido nesta emenda')
            ->assertSee('Concluído');
    }

    public function test_search_finds_amendment_by_object(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();
        $this->createAmendment($municipality, $user, [
            'reference' => 'EM-SAUDE',
            'object' => 'Aquisicao de ambulancia para atendimento rural',
        ]);
        $this->createAmendment($municipality, $user, [
            'reference' => 'EM-EDUCACAO',
            'object' => 'Reforma de escola municipal',
        ]);

        $this->actingAs($user)
            ->get(route('emendas.index', ['search' => 'ambulancia']))
            ->assertOk()
            ->assertSee('EM-SAUDE')
            ->assertDontSee('EM-EDUCACAO');
    }

    public function test_invalid_status_is_rejected(): void
    {
        [$user] = $this->userAndMunicipality();
        $payload = $this->payloadWithToken('amendment-create', ['status' => 'inventado']);

        $this->actingAs($user)
            ->post(route('emendas.store'), $payload)
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('parliamentary_amendments', 0);
    }

    /** @return array{User, Municipality} */
    private function userAndMunicipality(): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => 'manager']);

        return [$user, $municipality];
    }

    private function createAmendment(
        Municipality $municipality,
        User $user,
        array $attributes = [],
    ): ParliamentaryAmendment {
        return ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($user, 'creator')
            ->create($attributes);
    }

    /** @return array<string, mixed> */
    private function payloadWithToken(string $scope, array $overrides = []): array
    {
        $token = (string) Str::uuid();

        $this->withSession([
            'form_submission_tokens' => [
                $scope => [$token => now()->timestamp],
            ],
        ]);

        return $this->validPayload(array_merge(['_submission_token' => $token], $overrides));
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'reference' => 'EM-2026-001',
            'fiscal_year' => 2026,
            'government_sphere' => 'federal',
            'authorship_type' => 'individual',
            'transfer_type' => 'special',
            'author_name' => 'Deputada Exemplo',
            'author_party' => 'PSD',
            'object' => 'Aquisicao de equipamentos para unidade municipal de saude',
            'responsible_department' => 'Secretaria Municipal de Saude',
            'transferegov_code' => '123456',
            'expected_amount' => '500000.00',
            'received_amount' => null,
            'status' => ParliamentaryAmendment::STATUS_IDENTIFIED,
            'indicated_at' => '2026-06-01',
            'received_at' => null,
            'communication_deadline' => '2026-08-01',
            'communication_completed_at' => null,
            'execution_deadline' => '2027-06-30',
            'execution_completed_at' => null,
            'accountability_deadline' => '2027-12-31',
            'accountability_completed_at' => null,
            'notes' => null,
        ], $overrides);
    }
}
