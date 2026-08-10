<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_root_shows_commercial_page_to_guests(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Gestão municipal de emendas')
            ->assertSee('Acessar demonstração');
    }

    public function test_not_found_page_uses_human_message_and_correct_status(): void
    {
        $this->get('/pagina-que-nao-existe')
            ->assertNotFound()
            ->assertSee('Página não encontrada')
            ->assertDontSee('Not Found');
    }
}
