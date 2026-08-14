@extends('layouts.app')

@section('title', 'TrilhaGov | Plataforma municipal de emendas')

@section('content')
    <section class="commercial-page" aria-label="TrilhaGov">
        <div class="commercial-nav">
            <a href="#fluxo">Fluxo</a>
            <a href="#municipios">Municípios</a>
            <a href="#controle">Controle</a>
            <a class="commercial-nav-cta" href="{{ route('login') }}">
                <i data-lucide="log-in" aria-hidden="true"></i>
                Acessar demo
            </a>
        </div>

        <section class="commercial-hero" aria-label="Plataforma municipal de emendas">
            <div class="commercial-hero-copy">
                <span class="commercial-kicker">
                    <i data-lucide="landmark" aria-hidden="true"></i>
                    SaaS municipal para emendas impositivas
                </span>
                <h1>Controle simples para Câmara, Prefeitura e prestação de contas.</h1>
                <p>
                    O TrilhaGov organiza o ciclo completo das emendas municipais: saldo do vereador,
                    reserva de saúde, conferência legislativa, fila do Executivo, execução, evidências
                    e dossiê final.
                </p>
                <div class="commercial-hero-actions">
                    <a class="btn btn-primary btn-lg" href="{{ route('login') }}">
                        <i data-lucide="arrow-right" aria-hidden="true"></i>
                        Entrar na demonstração
                    </a>
                    <a class="btn btn-outline-primary btn-lg" href="#produto">
                        <i data-lucide="play-circle" aria-hidden="true"></i>
                        Ver o produto
                    </a>
                </div>
                <div class="commercial-trust-row" aria-label="Principais automações">
                    <span><strong>Cota automática</strong> por vereador</span>
                    <span><strong>Saúde</strong> calculada pela norma</span>
                    <span><strong>TCESP/Audesp</strong> para municípios paulistas</span>
                </div>
            </div>

            <div class="commercial-product-stage" aria-label="Prévia do painel do TrilhaGov">
                <div class="commercial-browser-bar">
                    <span></span><span></span><span></span>
                    <em>trilhagov.onrender.com</em>
                </div>
                <div class="commercial-product-shell">
                    <aside>
                        <strong>Trilha<span>Gov</span></strong>
                        <small>Portal de Emendas</small>
                        <b class="is-active"><i data-lucide="layout-dashboard" aria-hidden="true"></i>Painel</b>
                        <b><i data-lucide="landmark" aria-hidden="true"></i>Legislativo</b>
                        <b><i data-lucide="wallet-cards" aria-hidden="true"></i>Execução</b>
                        <b><i data-lucide="shield-check" aria-hidden="true"></i>Controle</b>
                    </aside>
                    <main>
                        <header>
                            <div>
                                <small>Guapiara / SP</small>
                                <strong>Comando Municipal</strong>
                            </div>
                            <span>Ativo</span>
                        </header>

                        <div class="commercial-command-row">
                            <article>
                                <i data-lucide="wallet" aria-hidden="true"></i>
                                <small>Cota individual</small>
                                <strong>R$ 103,6 mil</strong>
                            </article>
                            <article>
                                <i data-lucide="heart-pulse" aria-hidden="true"></i>
                                <small>Reserva saúde</small>
                                <strong>50%</strong>
                            </article>
                            <article>
                                <i data-lucide="clock-3" aria-hidden="true"></i>
                                <small>Fila crítica</small>
                                <strong>3 ações</strong>
                            </article>
                        </div>

                        <div class="commercial-mini-flow">
                            <span class="is-done">Câmara indica</span>
                            <span class="is-done">Executivo recebe</span>
                            <span>Reserva orçamento</span>
                            <span>Presta contas</span>
                        </div>

                        <div class="commercial-work-card">
                            <i data-lucide="sparkles" aria-hidden="true"></i>
                            <div>
                                <small>Assistente automático</small>
                                <strong>Proposta de saúde identificada</strong>
                                <p>Saldo, secretaria, reserva mínima e próximo passo sugeridos antes do protocolo.</p>
                            </div>
                        </div>
                    </main>
                </div>
            </div>
        </section>

        <section class="commercial-proof-strip" id="produto" aria-label="Dores resolvidas">
            <article>
                <i data-lucide="calculator" aria-hidden="true"></i>
                <strong>Orçamento sem planilha paralela</strong>
                <span>A norma do exercício calcula limite, saldo e reserva automaticamente.</span>
            </article>
            <article>
                <i data-lucide="route" aria-hidden="true"></i>
                <strong>Menos tela, mais fluxo</strong>
                <span>Vereador, Câmara e Executivo seguem a mesma esteira operacional.</span>
            </article>
            <article>
                <i data-lucide="file-check-2" aria-hidden="true"></i>
                <strong>Dossiê pronto para controle</strong>
                <span>Documentos, decisões, evidências e protocolos ficam rastreáveis.</span>
            </article>
        </section>

        <section class="commercial-section commercial-problem" id="municipios">
            <div>
                <span class="commercial-kicker">
                    <i data-lucide="triangle-alert" aria-hidden="true"></i>
                    Dor real do município
                </span>
                <h2>O problema não é cadastrar. É saber o que pode, o que falta e quem decide agora.</h2>
                <p>
                    Prefeituras pequenas normalmente operam com planilhas, mensagens soltas e muita dependência
                    de memória da equipe. O TrilhaGov transforma isso em rotina guiada, com responsabilidades
                    claras entre Legislativo, Executivo, controle interno e prestação final.
                </p>
            </div>
            <div class="commercial-decision-grid">
                <article>
                    <span>01</span>
                    <strong>Vereador pede com saldo claro</strong>
                    <p>Antes de enviar, ele vê limite, saldo restante e sinais de compatibilidade.</p>
                </article>
                <article>
                    <span>02</span>
                    <strong>Câmara confere requisitos mínimos</strong>
                    <p>Objeto, beneficiário, teto, saúde e justificativa passam por checklist simples.</p>
                </article>
                <article>
                    <span>03</span>
                    <strong>Executivo trabalha por fila</strong>
                    <p>Receber, reservar, executar e prestar contas aparecem como decisões do dia.</p>
                </article>
                <article>
                    <span>04</span>
                    <strong>Controle interno acompanha tudo</strong>
                    <p>Alertas, ocorrências, evidências e dossiês reduzem risco de perda documental.</p>
                </article>
            </div>
        </section>

        <section class="commercial-section commercial-pipeline" id="fluxo">
            <div class="commercial-section-heading">
                <span class="commercial-kicker">
                    <i data-lucide="waypoints" aria-hidden="true"></i>
                    Fluxo completo
                </span>
                <h2>Da indicação à prestação final em uma linha de trabalho fácil de explicar.</h2>
            </div>
            <div class="commercial-pipeline-track">
                @foreach ([
                    ['Câmara indica', 'Vereador registra valor, destino e justificativa.'],
                    ['Conferência legislativa', 'Câmara valida requisitos antes de protocolar.'],
                    ['Executivo recebe', 'Prefeitura cria processo e define responsável.'],
                    ['Reserva orçamentária', 'Sistema marca o valor dentro da dotação.'],
                    ['Execução simplificada', 'Etapas, empenho, liquidação e pagamento.'],
                    ['Prestação final', 'Dossiê, protocolo, decisão e arquivamento.'],
                ] as $index => [$title, $description])
                    <article>
                        <span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <strong>{{ $title }}</strong>
                        <p>{{ $description }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="commercial-section commercial-market" id="controle">
            <div>
                <span class="commercial-kicker">
                    <i data-lucide="shield-check" aria-hidden="true"></i>
                    Piloto municipal
                </span>
                <h2>Construído para cidades que precisam operar bem sem montar uma equipe de software.</h2>
                <p>
                    A proposta é entregar clareza operacional: menos módulos desnecessários, mais automação,
                    parâmetros por município e linguagem adequada para gestor, vereador e equipe técnica.
                </p>
            </div>
            <div class="commercial-market-list">
                <span><i data-lucide="check" aria-hidden="true"></i> Onboarding do município e ativação do exercício</span>
                <span><i data-lucide="check" aria-hidden="true"></i> Portal Legislativo com linguagem simples para vereador</span>
                <span><i data-lucide="check" aria-hidden="true"></i> Fila do Executivo para receber, reservar e executar</span>
                <span><i data-lucide="check" aria-hidden="true"></i> Importação CSV para bases existentes</span>
                <span><i data-lucide="check" aria-hidden="true"></i> LGPD, MFA, logs e central de ocorrências</span>
            </div>
        </section>

        <section class="commercial-cta" aria-label="Acessar demonstração">
            <div>
                <span class="commercial-kicker">
                    <i data-lucide="sparkles" aria-hidden="true"></i>
                    Demonstração
                </span>
                <h2>Apresente o fluxo completo em poucos minutos.</h2>
                <p>Use a base demo para mostrar vereador, Câmara, Executivo, execução e prestação de contas com dados realistas.</p>
            </div>
            <a class="btn btn-primary btn-lg" href="{{ route('login') }}">
                <i data-lucide="arrow-right" aria-hidden="true"></i>
                Entrar no TrilhaGov
            </a>
        </section>
    </section>
@endsection
