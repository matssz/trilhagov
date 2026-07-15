<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_together_with_municipality(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Maria Gestora',
            'email' => 'maria@municipio.test',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
            'municipality_name' => 'Município de Exemplo',
            'state' => 'sp',
            'cnpj' => '12.345.678/0001-90',
            'ibge_code' => '3550308',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('municipalities', [
            'name' => 'Município de Exemplo',
            'state' => 'SP',
            'cnpj' => '12345678000190',
        ]);

        $municipality = Municipality::firstOrFail();
        $this->assertDatabaseHas('municipality_user', [
            'municipality_id' => $municipality->id,
            'user_id' => auth()->id(),
            'role' => 'manager',
        ]);
    }

    public function test_registered_user_can_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'senha-segura']);
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => 'manager']);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'senha-segura',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
