@extends('layouts.app')

@section('title', 'TrilhaGov | Plataforma municipal de emendas')

@section('content')
    <section class="commercial-page" aria-label="TrilhaGov">
        <div class="commercial-nav">
            <a href="#produto">Produto</a>
            <a href="#prova">Dores</a>
            <a href="#fluxo">Fluxo</a>
            <a href="#implantacao">Piloto</a>
            <a class="commercial-nav-cta" href="{{ route('login') }}">
                <i data-lucide="log-in" aria-hidden="true"></i>
                Acessar demo
            </a>
        </div>

        <section class="commercial-hero" aria-label="Plataforma municipal de emendas">
            <div class="commercial-hero-copy">
                <span class="commercial-kicker">
                    <i data-lucide="sparkles" aria-hidden="true"></i>
                    Plataforma SaaS para municípios
                </span>
                <h1>Emendas municipais sem planilha, sem retrabalho e sem apagar incêndio.</h1>
                <p>
                    O TrilhaGov transforma a relação entre Câmara e Prefeitura em uma esteira clara:
                    vereador indica, a Câmara confere, o Executivo reserva, executa e presta contas com
                    rastreabilidade.
                </p>
                <div class="commercial-hero-actions">
                    <a class="btn btn-primary btn-lg" href="{{ route('login') }}">
                        <i data-lucide="arrow-right" aria-hidden="true"></i>
                        Entrar na demo
                    </a>
                    <a class="btn btn-outline-primary btn-lg" href="#produto">
                        <i data-lucide="play-circle" aria-hidden="true"></i>
                        Ver como funciona
                    </a>
                </div>
                <div class="commercial-trust-row" aria-label="Principais automações">
                    <span><strong>Lei Orgânica</strong> parametrizada</span>
                    <span><strong>Cota e saúde</strong> automáticas</span>
                    <span><strong>TCESP/Audesp</strong> quando aplicável</span>
                </div>
            </div>

            <div class="commercial-product-stage" aria-label="Prévia do painel do TrilhaGov">
                <span class="commercial-float-card commercial-float-card-one" data-commercial-float>
                    <i data-lucide="heart-pulse" aria-hidden="true"></i>
                    Saúde preservada
                </span>
                <span class="commercial-float-card commercial-float-card-two" data-commercial-float>
                    <i data-lucide="shield-check" aria-hidden="true"></i>
                    Dossiê rastreável
                </span>
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
                                <strong>Mesa de decisões</strong>
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
                                <strong>R$ 51,8 mil</strong>
                            </article>
                            <article>
                                <i data-lucide="clock-3" aria-hidden="true"></i>
                                <small>Decisões hoje</small>
                                <strong>3 ações</strong>
                            </article>
                        </div>

                        <div class="commercial-mini-flow">
                            <span class="is-done">Câmara indica</span>
                            <span class="is-done">Executivo recebe</span>
                            <span>Reserva automática</span>
                            <span>Prestação final</span>
                        </div>

                        <div class="commercial-work-card">
                            <i data-lucide="sparkles" aria-hidden="true"></i>
                            <div>
                                <small>Assistente automático</small>
                                <strong>O sistema já sabe o que falta</strong>
                                <p>Saldo, saúde, secretaria, pendências e próximo passo aparecem antes da equipe errar o fluxo.</p>
                            </div>
                        </div>
                    </main>
                </div>
            </div>
        </section>

        <section class="commercial-impact-strip" id="produto" aria-label="Impacto do TrilhaGov">
            <article>
                <span>01</span>
                <strong>Vereador entende o limite antes de pedir</strong>
            </article>
            <article>
                <span>02</span>
                <strong>Executivo recebe uma fila pronta para decidir</strong>
            </article>
            <article>
                <span>03</span>
                <strong>Controle interno acompanha documento, prazo e evidência</strong>
            </article>
        </section>

        <section class="commercial-proof-strip" id="prova" aria-label="Dores resolvidas">
            <article>
                <i data-lucide="calculator" aria-hidden="true"></i>
                <strong>Fim da conta manual</strong>
                <span>Cotas, saldo restante e reserva de saúde vêm da norma do exercício.</span>
            </article>
            <article>
                <i data-lucide="mouse-pointer-click" aria-hidden="true"></i>
                <strong>Menos orientação por telefone</strong>
                <span>O vereador recebe modelos rápidos e validações antes de enviar.</span>
            </article>
            <article>
                <i data-lucide="file-check-2" aria-hidden="true"></i>
                <strong>Prestação com histórico</strong>
                <span>Decisões, documentos, protocolos e evidências não ficam espalhados.</span>
            </article>
            <article>
                <i data-lucide="radar" aria-hidden="true"></i>
                <strong>Alerta antes do problema</strong>
                <span>Prazos, pendências e inconsistências viram ação para a equipe.</span>
            </article>
        </section>

        <section class="commercial-section commercial-problem">
            <div>
                <span class="commercial-kicker">
                    <i data-lucide="triangle-alert" aria-hidden="true"></i>
                    Dor real do município
                </span>
                <h2>O município não precisa de mais uma tela. Precisa de clareza operacional.</h2>
                <p>
                    A dor não é somente registrar uma emenda. A dor é controlar orçamento disponível,
                    reserva legal de saúde, documentos, impedimentos, execução, resposta à Câmara e
                    prestação de contas sem perder o controle.
                </p>
            </div>
            <div class="commercial-decision-grid">
                <article>
                    <span>01</span>
                    <strong>Lei municipal como motor</strong>
                    <p>O gestor ativa a regra do exercício e o sistema calcula o que o vereador pode usar.</p>
                </article>
                <article>
                    <span>02</span>
                    <strong>Portal Legislativo simples</strong>
                    <p>O vereador cria proposta com modelos rápidos, saldo visível e bloqueio de erro.</p>
                </article>
                <article>
                    <span>03</span>
                    <strong>Mesa do Executivo</strong>
                    <p>O gestor vê o que precisa receber, reservar, executar e prestar contas.</p>
                </article>
                <article>
                    <span>04</span>
                    <strong>Defesa documental</strong>
                    <p>O pacote final reúne trilha, responsáveis, comprovantes e decisões relevantes.</p>
                </article>
            </div>
        </section>

        <section class="commercial-section commercial-pipeline" id="fluxo">
            <div class="commercial-section-heading">
                <span class="commercial-kicker">
                    <i data-lucide="waypoints" aria-hidden="true"></i>
                    Esteira do trabalho
                </span>
                <h2>Uma linha de operação que gestor, vereador e controle interno conseguem entender.</h2>
            </div>
            <div class="commercial-pipeline-track">
                @foreach ([
                    ['Norma ativa', 'Lei Orgânica, cadeiras, orçamento e percentuais configurados.'],
                    ['Vereador indica', 'Modelos rápidos, saldo claro e validação automática.'],
                    ['Câmara confere', 'Checklist legislativo antes de protocolar ao Executivo.'],
                    ['Executivo reserva', 'Processo, secretaria, dotação e valor comprometido.'],
                    ['Execução simplificada', 'Etapas, empenho, liquidação, pagamento e evidências.'],
                    ['Prestação final', 'Dossiê, protocolo, decisão, arquivo e histórico.'],
                ] as $index => [$title, $description])
                    <article>
                        <span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <strong>{{ $title }}</strong>
                        <p>{{ $description }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="commercial-showcase-band" aria-label="Demonstração de rotina municipal">
            <div>
                <span class="commercial-kicker">
                    <i data-lucide="route" aria-hidden="true"></i>
                    Rotina guiada
                </span>
                <h2>O sistema fala a próxima ação, não joga a responsabilidade para o usuário descobrir.</h2>
            </div>
            <div class="commercial-showcase-grid">
                <article>
                    <i data-lucide="wallet" aria-hidden="true"></i>
                    <strong>Usar saldo disponível</strong>
                    <p>Preenche o valor compatível com a cota do vereador.</p>
                </article>
                <article>
                    <i data-lucide="heart-pulse" aria-hidden="true"></i>
                    <strong>Marcar saúde automaticamente</strong>
                    <p>Identifica UBS, vacina, hospital, atendimento e secretaria.</p>
                </article>
                <article>
                    <i data-lucide="package-check" aria-hidden="true"></i>
                    <strong>Gerar pacote de controle</strong>
                    <p>Separa PDF executivo, anexos, pendências e histórico.</p>
                </article>
            </div>
        </section>

        <section class="commercial-section commercial-market" id="implantacao">
            <div>
                <span class="commercial-kicker">
                    <i data-lucide="shield-check" aria-hidden="true"></i>
                    Piloto municipal
                </span>
                <h2>Feito para municípios que não têm estrutura própria de tecnologia.</h2>
                <p>
                    O foco é entregar uma implantação assistida: ativar exercício, liberar Câmara,
                    importar dados, convidar usuários, acompanhar execução e gerar prestação sem depender
                    de planilhas paralelas.
                </p>
            </div>
            <div class="commercial-market-list">
                <span><i data-lucide="check" aria-hidden="true"></i> Onboarding do município e ativação do exercício</span>
                <span><i data-lucide="check" aria-hidden="true"></i> Câmara liberada somente quando a norma permite operar</span>
                <span><i data-lucide="check" aria-hidden="true"></i> Recursos estaduais/federais por parâmetro, sem poluir o menu</span>
                <span><i data-lucide="check" aria-hidden="true"></i> Audesp e TCESP mantidos para municípios paulistas</span>
                <span><i data-lucide="check" aria-hidden="true"></i> LGPD, MFA, logs, ocorrências e segurança operacional</span>
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
