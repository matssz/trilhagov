<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_refresh_application_state(): void
    {
        $this->post(route('application.refresh'))->assertRedirect(route('login'));
    }

    public function test_refresh_preserves_login_and_municipality_but_clears_temporary_state(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();
        $oldCsrfToken = Str::random(40);
        Cache::put('shared-system-value', 'preserved', now()->addMinute());

        $response = $this->actingAs($user)
            ->withSession([
                '_token' => $oldCsrfToken,
                'active_municipality_id' => $municipality->id,
                'form_submission_tokens' => [
                    'amendment-create' => [(string) Str::uuid() => now()->timestamp],
                ],
                '_old_input' => ['reference' => 'FORMULARIO-ANTIGO'],
                'url.intended' => route('emendas.create'),
            ])
            ->post(route('application.refresh'));

        $response
            ->assertRedirect(route('dashboard'))
            ->assertHeader('Clear-Site-Data', '"cache"')
            ->assertHeader('X-TrilhaGov-Refresh', '1')
            ->assertHeader('Pragma', 'no-cache')
            ->assertSessionHas('status')
            ->assertSessionHas('active_municipality_id', $municipality->id)
            ->assertSessionMissing('form_submission_tokens')
            ->assertSessionMissing('_old_input')
            ->assertSessionMissing('url.intended');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldCsrfToken, session('_token'));
        $this->assertSame('preserved', Cache::get('shared-system-value'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_refresh_repairs_stale_municipality_when_user_has_one_valid_membership(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => 999999])
            ->post(route('application.refresh'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('active_municipality_id', $municipality->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_refresh_requests_selection_when_stale_user_has_multiple_municipalities(): void
    {
        [$user, $firstMunicipality] = $this->userAndMunicipality();
        $secondMunicipality = Municipality::factory()->create();
        $secondMunicipality->users()->attach($user, ['role' => User::ROLE_VIEWER]);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => 999999])
            ->post(route('application.refresh'))
            ->assertRedirect(route('municipalities.select'))
            ->assertSessionMissing('active_municipality_id');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('municipality_user', [
            'municipality_id' => $firstMunicipality->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_refresh_logs_out_account_without_valid_municipality(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('application.refresh'))
            ->assertRedirect(route('login'))
            ->assertHeader('Clear-Site-Data', '"cache"')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_pages_are_not_cached_by_browser(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();

        $response = $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('Pragma', 'no-cache')->assertHeader('Expires', '0');
    }

    public function test_refresh_can_be_used_repeatedly_without_losing_authentication(): void
    {
        [$user, $municipality] = $this->userAndMunicipality();

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('application.refresh'))
            ->assertRedirect(route('dashboard'));

        $this->post(route('application.refresh'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('active_municipality_id', $municipality->id);

        $this->assertAuthenticatedAs($user);
    }

    /** @return array{User, Municipality} */
    private function userAndMunicipality(): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => User::ROLE_MANAGER]);

        return [$user, $municipality];
    }
}
