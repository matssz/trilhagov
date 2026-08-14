<?php

namespace Tests\Feature;

use App\Models\LegislativeProposal;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Database\Seeders\GuapiaraDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PilotPresentationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_and_manager_pilot_screens_are_reachable(): void
    {
        $this->seed(GuapiaraDemoSeeder::class);

        $municipality = Municipality::where('ibge_code', '3517604')->firstOrFail();
        $manager = User::where('email', 'gestor.guapiara@trilhagov.demo')->firstOrFail();
        $amendment = ParliamentaryAmendment::where('municipality_id', $municipality->id)
            ->where('reference', 'EM-GUA-2027-003')
            ->firstOrFail();

        $this->get(route('marketing.home'))
            ->assertOk()
            ->assertSee('TrilhaGov');

        $this->get(route('transparency.show', $municipality))
            ->assertOk()
            ->assertSee('Guapiara');

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id]);

        foreach ($this->managerPilotRoutes($amendment) as $route) {
            $this->get($route)->assertOk();
        }
    }

    public function test_councilor_pilot_screens_are_reachable_and_simple(): void
    {
        $this->seed(GuapiaraDemoSeeder::class);

        $municipality = Municipality::where('ibge_code', '3517604')->firstOrFail();
        $councilor = User::where('email', 'bruno.guapiara@trilhagov.demo')->firstOrFail();
        $proposal = LegislativeProposal::where('municipality_id', $municipality->id)
            ->where('submitted_by', $councilor->id)
            ->firstOrFail();

        $this->actingAs($councilor)
            ->withSession(['active_municipality_id' => $municipality->id]);

        $this->get(route('legislative.index'))
            ->assertOk()
            ->assertSee('Portal Legislativo')
            ->assertSee('Nova proposta')
            ->assertDontSee('Nova emenda');

        $this->get(route('legislative.create'))
            ->assertOk()
            ->assertSee('Modelos rápidos')
            ->assertSee('Salvar proposta');

        $this->get(route('legislative.show', $proposal))
            ->assertOk()
            ->assertSee($proposal->reference)
            ->assertSee('Esteira da proposta')
            ->assertSee('Aguardando tramitação da Câmara');
    }

    /**
     * @return array<int, string>
     */
    private function managerPilotRoutes(ParliamentaryAmendment $amendment): array
    {
        return [
            route('dashboard'),
            route('municipal-onboarding.index'),
            route('legislative.index'),
            route('emendas.index'),
            route('emendas.show', $amendment),
            route('emendas.work-plan', $amendment),
            route('emendas.execution', $amendment),
            route('emendas.accountability', $amendment),
            route('emendas.compliance', $amendment),
            route('work-center.index'),
            route('alerts.index'),
            route('municipal-rules.index'),
            route('municipal-institutions.index'),
            route('users.index'),
            route('spreadsheet-imports.index'),
            route('audesp-homologations.index'),
            route('security-privacy.index'),
        ];
    }
}
