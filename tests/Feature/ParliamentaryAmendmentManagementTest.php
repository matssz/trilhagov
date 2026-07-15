<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $response = $this->actingAs($user)->post(route('emendas.store'), $this->validPayload());

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

    public function test_user_cannot_view_or_update_another_municipalitys_amendment(): void
    {
        [$user] = $this->userAndMunicipality();
        [$otherUser, $otherMunicipality] = $this->userAndMunicipality();
        $amendment = $this->createAmendment($otherMunicipality, $otherUser);

        $this->actingAs($user)
            ->get(route('emendas.show', $amendment))
            ->assertNotFound();

        $payload = $this->validPayload(['reference' => 'TENTATIVA-ALTERACAO']);

        $this->actingAs($user)
            ->put(route('emendas.update', $amendment), $payload)
            ->assertNotFound();

        $this->assertNotSame('TENTATIVA-ALTERACAO', $amendment->fresh()->reference);
    }

    public function test_reference_cannot_repeat_in_same_sphere_and_year(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();
        $this->createAmendment($municipality, $user, ['reference' => 'EM-DUPLICADA']);

        $this->actingAs($user)->post(
            route('emendas.store'),
            $this->validPayload(['reference' => 'EM-DUPLICADA']),
        )->assertSessionHasErrors('reference');

        $this->assertDatabaseCount('parliamentary_amendments', 1);
    }

    public function test_user_can_update_amendment_status_and_received_amount(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();
        $amendment = $this->createAmendment($municipality, $user);

        $this->actingAs($user)->put(route('emendas.update', $amendment), $this->validPayload([
            'reference' => $amendment->reference,
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
            'received_amount' => '450000.00',
            'received_at' => '2026-07-10',
        ]))->assertRedirect(route('emendas.show', $amendment));

        $this->assertDatabaseHas('parliamentary_amendments', [
            'id' => $amendment->id,
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
            'received_amount' => 450000,
        ]);
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
            'reference' => 'EM-SAÚDE',
            'object' => 'Aquisição de ambulância para atendimento rural',
        ]);
        $this->createAmendment($municipality, $user, [
            'reference' => 'EM-EDUCAÇÃO',
            'object' => 'Reforma de escola municipal',
        ]);

        $this->actingAs($user)
            ->get(route('emendas.index', ['search' => 'ambulância']))
            ->assertOk()
            ->assertSee('EM-SAÚDE')
            ->assertDontSee('EM-EDUCAÇÃO');
    }

    public function test_invalid_status_is_rejected(): void
    {
        [$user] = $this->userAndMunicipality();

        $this->actingAs($user)->post(
            route('emendas.store'),
            $this->validPayload(['status' => 'inventado']),
        )->assertSessionHasErrors('status');

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
            'object' => 'Aquisição de equipamentos para unidade municipal de saúde',
            'responsible_department' => 'Secretaria Municipal de Saúde',
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
