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
            ->assertSee('IDs no inspecionar não concedem permissão')
            ->assertSee('Banco protegido pelo backend')
            ->assertSee('Inventário LGPD')
            ->assertSee('Dados tratados pelo município')
            ->assertSee('Bases de tratamento')
            ->assertSee('Matriz de risco')
            ->assertSee('Retenção e descarte')
            ->assertSee('Resposta a incidente')
            ->assertSee('Roteiro para vazamento')
            ->assertSee('Proteção extra para a sua conta de gestor')
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

    public function test_mfa_cannot_be_enabled_in_production_without_real_mailer(): void
    {
        config([
            'app.env' => 'production',
            'mail.default' => 'smtp',
            'mail.from.address' => 'alertas@trilhagov.local',
            'mail.mailers.smtp.host' => null,
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
        ]);
        [$user, $municipality] = $this->userAndMunicipality(User::ROLE_MANAGER);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('security-privacy.mfa.update'), ['enabled' => '1'])
            ->assertSessionHasErrors('mfa');

        $this->assertFalse($user->fresh()->mfa_enabled);
    }

    public function test_mfa_can_be_enabled_in_production_with_real_smtp_configuration(): void
    {
        config([
            'app.env' => 'production',
            'mail.default' => 'smtp',
            'mail.from.address' => 'alertas@trilhagov.com.br',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'smtp-user',
            'mail.mailers.smtp.password' => 'smtp-password',
        ]);
        [$user, $municipality] = $this->userAndMunicipality(User::ROLE_MANAGER);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('security-privacy.mfa.update'), ['enabled' => '1'])
            ->assertSessionHas('status');

        $this->assertTrue($user->fresh()->mfa_enabled);
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
