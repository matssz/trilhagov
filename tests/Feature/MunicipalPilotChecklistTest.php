<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MunicipalPilotChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_sees_pilot_checklist_on_onboarding(): void
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP']);
        $municipality->users()->attach($user, ['role' => User::ROLE_MANAGER]);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-onboarding.index'))
            ->assertOk()
            ->assertSee('Piloto municipal')
            ->assertSee('Checklist para validar o sistema inteiro')
            ->assertSee('Município configurado')
            ->assertSee('Norma ativa')
            ->assertSee('Câmara liberada')
            ->assertSee('Proposta legislativa criada')
            ->assertSee('Conferência da Câmara')
            ->assertSee('Prestação preparada')
            ->assertSee('Monitoramento e ocorrências');
    }
}
