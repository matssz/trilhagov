<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_shows_commercial_positioning(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Emendas do início ao fim, sem retrabalho')
            ->assertSee('Entrar na demo')
            ->assertSee('Feito para municípios que não têm estrutura própria de tecnologia.');
    }

    public function test_commercial_page_has_direct_public_url(): void
    {
        $this->get(route('marketing.home'))
            ->assertOk()
            ->assertSee('Entrar na demo')
            ->assertSee('Apresente o fluxo completo em poucos minutos.');
    }

    public function test_commercial_page_stays_public_without_workspace_sidebar_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('marketing.home'))
            ->assertOk()
            ->assertSee('Entrar na demo')
            ->assertDontSee('app-sidebar', false);
    }
}
