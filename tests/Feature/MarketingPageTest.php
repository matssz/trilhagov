<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketingPageTest extends TestCase
{
    public function test_public_home_shows_commercial_positioning(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Controle simples para Câmara, Prefeitura e prestação de contas.')
            ->assertSee('Entrar na demonstração')
            ->assertSee('Construído para cidades que precisam operar bem sem montar uma equipe de software.');
    }

    public function test_commercial_page_has_direct_public_url(): void
    {
        $this->get(route('marketing.home'))
            ->assertOk()
            ->assertSee('Acessar demo')
            ->assertSee('Apresente o fluxo completo em poucos minutos.');
    }
}
