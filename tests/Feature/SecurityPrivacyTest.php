<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_view_security_privacy_panel(): void
    {
        [$user, $municipality] = $this->userAndMunicipality(User::ROLE_MANAGER);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('security-privacy.index'))
            ->assertOk()
            ->assertSee('LGPD e defesa')
            ->assertSee('IDs no inspecionar nao concedem permissao')
            ->assertSee('Banco protegido pelo backend')
            ->assertSee('Inventario LGPD')
            ->assertSee('Dados tratados pelo municipio')
            ->assertSee('Bases de tratamento')
            ->assertSee('Matriz de risco')
            ->assertSee('Retencao e descarte')
            ->assertSee('Resposta a incidente')
            ->assertSee('Roteiro para vazamento')
            ->assertSee('Protecao extra para a sua conta de gestor')
            ->assertSee('Ativar MFA');
    }

    public function test_manager_can_toggle_own_mfa_from_security_panel(): void
    {
        [$user, $municipality] = $this->userAndMunicipality(User::ROLE_MANAGER);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('security-privacy.mfa.update'), ['enabled' => '1'])
            ->assertSessionHas('status');

        $this->assertTrue($user->fresh()->mfa_enabled);

        $this->patch(route('security-privacy.mfa.update'), ['enabled' => '0'])
            ->assertSessionHas('status');

        $this->assertFalse($user->fresh()->mfa_enabled);
    }

    public function test_non_manager_cannot_view_security_privacy_panel(): void
    {
        [$user, $municipality] = $this->userAndMunicipality(User::ROLE_VIEWER);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('security-privacy.index'))
            ->assertForbidden();
    }

    public function test_web_responses_include_security_headers(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->assertHeader('Content-Security-Policy', "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'");
    }

    /** @return array{User, Municipality} */
    private function userAndMunicipality(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }
}
