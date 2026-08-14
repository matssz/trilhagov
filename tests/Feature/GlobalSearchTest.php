<?php

namespace Tests\Feature;

use App\Models\LegislativeProposal;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_finds_amendment_from_global_search(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        ParliamentaryAmendment::factory()->for($municipality)->create([
            'created_by' => $manager->id,
            'government_sphere' => 'municipal',
            'reference' => 'EM-PILOTO-001',
            'object' => 'Aquisição de ambulância para o bairro central',
            'author_name' => 'Vereadora Ana',
            'responsible_department' => 'Secretaria Municipal de Saúde',
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('search.index', ['search' => 'ambulância']))
            ->assertOk()
            ->assertSee('Busca Global')
            ->assertSee('EM-PILOTO-001')
            ->assertSee('Secretaria Municipal de Saúde');
    }

    public function test_councilor_finds_only_own_legislative_proposals(): void
    {
        [$councilor, $municipality] = $this->member(User::ROLE_COUNCILOR);
        $otherCouncilor = User::factory()->create();
        $municipality->users()->attach($otherCouncilor, ['role' => User::ROLE_COUNCILOR]);

        $this->proposal($municipality, $councilor, [
            'reference' => 'LEG-MINHA-001',
            'object' => 'Reforma da praça do bairro Primavera',
            'beneficiary_name' => 'Praça Primavera',
        ]);
        $this->proposal($municipality, $otherCouncilor, [
            'reference' => 'LEG-OUTRA-001',
            'object' => 'Reforma da praça do bairro Primavera',
            'beneficiary_name' => 'Praça de outro vereador',
        ]);

        $this->actingAs($councilor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('search.index', ['search' => 'Primavera']))
            ->assertOk()
            ->assertSee('LEG-MINHA-001')
            ->assertDontSee('LEG-OUTRA-001');
    }

    public function test_topbar_uses_global_search_route(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('search.index'), false)
            ->assertSee('Pesquisar emendas, propostas, documentos ou protocolos');
    }

    /** @return array{User, Municipality} */
    private function member(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP']);
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }

    /** @param array<string, mixed> $overrides */
    private function proposal(Municipality $municipality, User $submitter, array $overrides = []): LegislativeProposal
    {
        return $municipality->legislativeProposals()->create([
            'submitted_by' => $submitter->id,
            'reference' => 'LEG-2027-001',
            'fiscal_year' => 2027,
            'author_name' => $submitter->name,
            'author_party' => 'PSD',
            'object' => 'Aquisição de equipamento para unidade municipal',
            'justification' => 'Atendimento de necessidade pública local.',
            'priority' => 'normal',
            'beneficiary_type' => 'municipal_body',
            'beneficiary_name' => 'Unidade Municipal',
            'beneficiary_location' => 'Centro',
            'expense_destination' => 'investment',
            'transfer_type' => 'direct_execution',
            'health_related' => false,
            'responsible_department' => 'Secretaria Municipal de Obras',
            'public_need' => 'Melhorar a estrutura de atendimento municipal.',
            'estimated_amount' => 10000,
            'estimate_source' => 'Pesquisa preliminar',
            'status' => LegislativeProposal::STATUS_DRAFT,
            ...$overrides,
        ]);
    }
}
