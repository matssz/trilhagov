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
            ->assertSee('Banco protegido pelo backend');
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
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
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
