<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_shows_commercial_page_to_guests(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Emendas do início ao fim, sem retrabalho')
            ->assertSee('Entrar na demo');
    }

    public function test_not_found_page_uses_human_message_and_correct_status(): void
    {
        $this->get('/pagina-que-nao-existe')
            ->assertNotFound()
            ->assertSee('Página não encontrada')
            ->assertDontSee('Not Found');
    }
}
