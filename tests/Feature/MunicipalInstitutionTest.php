<?php

namespace Tests\Feature;

use App\Models\MunicipalInstitution;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MunicipalInstitutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_creates_and_updates_municipal_institution_once(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $token = $this->sessionFor($municipality, 'municipal-institution-create');
        $payload = [
            '_submission_token' => $token,
            'type' => MunicipalInstitution::TYPE_DEPARTMENT,
            'name' => 'Secretaria Municipal de Saude',
            'legal_name' => 'Fundo Municipal de Saude',
            'document' => '12.345.678/0001-90',
            'email' => 'SAUDE@EXEMPLO.GOV.BR',
            'city' => $municipality->name,
            'state' => $municipality->state,
        ];

        $this->actingAs($manager)
            ->post(route('municipal-institutions.store'), $payload)
            ->assertRedirect(route('municipal-institutions.index', ['type' => MunicipalInstitution::TYPE_DEPARTMENT]));
        $this->post(route('municipal-institutions.store'), $payload)->assertSessionHas('warning');

        $institution = MunicipalInstitution::firstOrFail();
        $this->assertSame('12345678000190', $institution->document);
        $this->assertSame('saude@exemplo.gov.br', $institution->email);
        $this->assertDatabaseCount('municipal_institutions', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'municipal_institution_created']);

        $updateToken = $this->sessionFor($municipality, "municipal-institution-update-{$institution->id}");
        $this->patch(route('municipal-institutions.update', $institution), [
            '_submission_token' => $updateToken,
            'type' => MunicipalInstitution::TYPE_DEPARTMENT,
            'name' => 'Secretaria Municipal de Saude',
            'role_title' => 'Ordenador da despesa',
            'is_active' => 0,
        ])->assertSessionHas('status');

        $this->assertFalse($institution->fresh()->is_active);
        $this->assertSame('Ordenador da despesa', $institution->fresh()->role_title);
        $this->assertDatabaseHas('audit_logs', ['action' => 'municipal_institution_updated']);
    }

    public function test_viewer_can_consult_but_cannot_change_institutional_base(): void
    {
        [$viewer, $municipality] = $this->memberWithMunicipality(User::ROLE_VIEWER);
        $institution = $municipality->institutions()->create([
            'type' => MunicipalInstitution::TYPE_COUNCILOR,
            'name' => 'Vereador Bruno Almeida',
            'party' => 'PSD',
            'is_active' => true,
        ]);

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-institutions.index'))
            ->assertOk()
            ->assertSee('Cadastros municipais')
            ->assertSee($institution->name)
            ->assertDontSee('Novo cadastro');

        $this->post(route('municipal-institutions.store'), [
            'type' => MunicipalInstitution::TYPE_SUPPLIER,
            'name' => 'Fornecedor Teste',
        ])->assertForbidden();
    }

    public function test_institutional_records_are_scoped_to_active_municipality(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$otherManager, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $own = $municipality->institutions()->create([
            'type' => MunicipalInstitution::TYPE_SUPPLIER,
            'name' => 'Fornecedor do Municipio Correto',
            'is_active' => true,
        ]);
        $other = $otherMunicipality->institutions()->create([
            'type' => MunicipalInstitution::TYPE_SUPPLIER,
            'name' => 'Fornecedor de Outro Municipio',
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('municipal-institutions.index'))
            ->assertOk()
            ->assertSee($own->name)
            ->assertDontSee($other->name);

        $token = $this->sessionFor($municipality, "municipal-institution-update-{$other->id}");
        $this->patch(route('municipal-institutions.update', $other), [
            '_submission_token' => $token,
            'type' => MunicipalInstitution::TYPE_SUPPLIER,
            'name' => 'Tentativa indevida',
        ])->assertNotFound();

        $this->actingAs($otherManager)
            ->withSession(['active_municipality_id' => $otherMunicipality->id])
            ->get(route('municipal-institutions.index'))
            ->assertSee($other->name)
            ->assertDontSee($own->name);
    }

    public function test_amendment_form_uses_active_institutional_records_as_suggestions(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $municipality->institutions()->create([
            'type' => MunicipalInstitution::TYPE_COUNCILOR,
            'name' => 'Vereador Bruno Almeida',
            'party' => 'PSD',
            'is_active' => true,
        ]);
        $municipality->institutions()->create([
            'type' => MunicipalInstitution::TYPE_DEPARTMENT,
            'name' => 'Secretaria Municipal de Saude',
            'is_active' => true,
        ]);
        $municipality->institutions()->create([
            'type' => MunicipalInstitution::TYPE_BENEFICIARY,
            'name' => 'UBS Vila Nova',
            'city' => $municipality->name,
            'state' => $municipality->state,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.create'))
            ->assertOk()
            ->assertSee('list="institution-authors"', false)
            ->assertSee('list="institution-departments"', false)
            ->assertSee('list="institution-beneficiaries"', false)
            ->assertSee('data-institution-party-target', false)
            ->assertSee('data-institution-source="institution-authors"', false)
            ->assertSee('Vereador Bruno Almeida')
            ->assertSee('Secretaria Municipal de Saude')
            ->assertSee('UBS Vila Nova');
    }

    /** @return array{User, Municipality} */
    private function memberWithMunicipality(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
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
