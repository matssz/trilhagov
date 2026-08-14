<?php

namespace Tests\Feature;

use Tests\TestCase;

class MarketingPageTest extends TestCase
{
    public function test_public_home_shows_commercial_positioning(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('O caminho mais claro entre Câmara, Prefeitura, execução e prestação de contas.')
            ->assertSee('Acessar demonstração')
            ->assertSee('Feito para municípios que não têm estrutura própria de tecnologia.');
    }

    public function test_commercial_page_has_direct_public_url(): void
    {
        $this->get(route('marketing.home'))
            ->assertOk()
            ->assertSee('Acessar demonstração')
            ->assertSee('Demonstração pronta');
    }
}
