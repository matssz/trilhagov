<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_together_with_municipality(): void
    {
        $response = $this->post(route('register'), [
            '_submission_token' => $this->submissionToken('register'),
            'name' => 'Maria Gestora',
            'email' => 'maria@municipio.test',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
            'municipality_name' => 'Municipio de Exemplo',
            'state' => 'sp',
            'cnpj' => '11.222.333/0001-81',
            'ibge_code' => '3550308',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('municipalities', [
            'name' => 'Municipio de Exemplo',
            'state' => 'SP',
            'cnpj' => '11222333000181',
            'ibge_code' => '3550308',
        ]);

        $municipality = Municipality::firstOrFail();
        $this->assertDatabaseHas('municipality_user', [
            'municipality_id' => $municipality->id,
            'user_id' => auth()->id(),
            'role' => 'manager',
        ]);
        $this->assertDatabaseCount('document_types', 5);
        $this->assertSame($municipality->id, session('active_municipality_id'));
    }

    public function test_registration_requires_complete_municipality_information(): void
    {
        $this->post(route('register'), [
            '_submission_token' => $this->submissionToken('register'),
            'name' => 'Maria Gestora',
            'email' => 'maria@municipio.test',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
        ])->assertSessionHasErrors([
            'municipality_name' => 'Informe o nome do município.',
            'state' => 'Informe a UF.',
            'cnpj' => 'Informe o CNPJ.',
            'ibge_code' => 'Informe o código IBGE.',
        ]);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('municipalities', 0);
    }

    public function test_registration_rejects_invalid_cnpj(): void
    {
        $this->post(route('register'), [
            '_submission_token' => $this->submissionToken('register'),
            'name' => 'Maria Gestora',
            'email' => 'maria@municipio.test',
            'password' => 'senha-segura',
            'password_confirmation' => 'senha-segura',
            'municipality_name' => 'Municipio de Exemplo',
            'state' => 'SP',
            'cnpj' => '11.111.111/1111-11',
            'ibge_code' => '3550308',
        ])->assertSessionHasErrors(['cnpj' => 'Informe um CNPJ válido.']);

        $this->assertDatabaseCount('users', 0);
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
        $this->assertSame($municipality->id, session('active_municipality_id'));

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_remember_option_creates_persistent_login_cookie(): void
    {
        $user = User::factory()->create(['password' => 'senha-segura']);
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => 'manager']);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'senha-segura',
            'remember' => '1',
        ])->assertCookie(Auth::guard()->getRecallerName());

        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_user_without_complete_municipality_cannot_login(): void
    {
        $user = User::factory()->create(['password' => 'senha-segura']);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'senha-segura',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_with_multiple_municipalities_must_select_the_active_one(): void
    {
        $user = User::factory()->create(['password' => 'senha-segura']);
        $first = Municipality::factory()->create(['name' => 'Municipio A']);
        $second = Municipality::factory()->create(['name' => 'Municipio B']);
        $first->users()->attach($user, ['role' => 'manager']);
        $second->users()->attach($user, ['role' => 'manager']);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'senha-segura',
        ])->assertRedirect(route('municipalities.select'));

        $this->assertNull(session('active_municipality_id'));

        $this->post(route('municipalities.activate'), [
            'municipality_id' => $second->id,
        ])->assertRedirect(route('dashboard'));

        $this->assertSame($second->id, session('active_municipality_id'));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Municipio B')
            ->assertDontSee('Municipio A');
    }

    public function test_municipality_selection_explains_destination_and_active_role(): void
    {
        $user = User::factory()->create(['password' => 'senha-segura']);
        $executive = Municipality::factory()->create(['name' => 'Itapetininga']);
        $legislative = Municipality::factory()->create(['name' => 'Sorocaba']);
        $executive->users()->attach($user, ['role' => User::ROLE_MANAGER]);
        $legislative->users()->attach($user, ['role' => User::ROLE_COUNCILOR]);

        $this->actingAs($user)
            ->get(route('municipalities.select'))
            ->assertOk()
            ->assertSee('Escolha o vínculo correto')
            ->assertSee('Gestor')
            ->assertSee('Abre Painel municipal')
            ->assertSee('Vereador')
            ->assertSee('Abre Portal Legislativo')
            ->assertSee('Cadastro e acompanhamento das indicações legislativas.');
    }

    private function submissionToken(string $scope): string
    {
        $token = (string) Str::uuid();

        $this->withSession([
            'form_submission_tokens' => [
                $scope => [$token => now()->timestamp],
            ],
        ]);

        return $token;
    }
}
