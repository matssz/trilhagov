@extends('layouts.app')

@section('title', 'TrilhaGov | Plataforma municipal de emendas')

@section('content')
    <section class="commercial-hero" aria-label="TrilhaGov para municipios">
        <div class="commercial-hero-content">
            <span class="commercial-kicker"><i data-lucide="landmark" aria-hidden="true"></i> Plataforma municipal de emendas impositivas</span>
            <h1>O caminho mais claro entre Câmara, Prefeitura, execução e prestação de contas.</h1>
            <p>O TrilhaGov automatiza cota do vereador, reserva de saúde, conferência legislativa, fila do Executivo, evidências e dossiê final para municípios que ainda dependem de planilhas, mensagens e pastas soltas.</p>
            <div class="commercial-hero-actions">
                <a class="btn btn-primary btn-lg" href="{{ route('login') }}"><i data-lucide="log-in" aria-hidden="true"></i>Acessar demonstração</a>
                <a class="btn btn-outline-primary btn-lg" href="#produto"><i data-lucide="play" aria-hidden="true"></i>Ver como funciona</a>
            </div>
            <div class="commercial-trust-row" aria-label="Resultados esperados">
                <span><strong>RCL + Lei Orgânica</strong> viram saldo por vereador</span>
                <span><strong>Saúde</strong> calculada sem planilha paralela</span>
                <span><strong>TCESP/Audesp</strong> preparado para municípios paulistas</span>
            </div>
        </div>
        <div class="commercial-product-stage" aria-label="Demonstração visual do produto">
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
                        <span>Pronto para operar</span>
                    </header>
                    <div class="commercial-metrics">
                        <article><small>Cota individual</small><strong>R$ 103,6 mil</strong></article>
                        <article><small>Reserva saúde</small><strong>50%</strong></article>
                        <article><small>Fila crítica</small><strong>3 ações</strong></article>
                    </div>
                    <div class="commercial-flow">
                        <span class="is-done">Câmara indica</span>
                        <span class="is-done">Executivo recebe</span>
                        <span>Reserva orçamento</span>
                        <span>Presta contas</span>
                    </div>
                    <div class="commercial-work-card">
                        <i data-lucide="sparkles" aria-hidden="true"></i>
                        <div>
                            <small>Automação municipal</small>
                            <strong>Proposta de saúde identificada</strong>
                            <p>Saldo, secretaria, reserva mínima e próximo passo já sugeridos.</p>
                        </div>
                    </div>
                </main>
            </div>
        </div>
    </section>

    <section class="commercial-proof-strip" aria-label="Dores resolvidas pelo TrilhaGov">
        <article><i data-lucide="calculator" aria-hidden="true"></i><strong>Cota automática</strong><span>RCL, percentual e cadeiras calculados por exercício.</span></article>
        <article><i data-lucide="heart-pulse" aria-hidden="true"></i><strong>Saúde sem retrabalho</strong><span>Reserva mínima sinalizada no pedido do vereador.</span></article>
        <article><i data-lucide="route" aria-hidden="true"></i><strong>Fluxo único</strong><span>Câmara, Executivo, execução e prestação em uma esteira.</span></article>
        <article><i data-lucide="file-check-2" aria-hidden="true"></i><strong>Dossiê final</strong><span>Documentos, evidências e protocolo prontos para controle.</span></article>
    </section>

    <section class="commercial-section commercial-problem" id="produto">
        <div>
            <span class="commercial-kicker"><i data-lucide="triangle-alert" aria-hidden="true"></i> Dor real do município</span>
            <h2>O problema não é só cadastrar emenda. É saber o que pode, o que falta e quem precisa agir.</h2>
            <p>Prefeituras pequenas não precisam de mais um cadastro genérico. Precisam de uma operação guiada: vereador indica com limite claro, Câmara confere requisitos mínimos, Executivo reserva orçamento e a equipe fecha execução e prestação sem perder evidência.</p>
        </div>
        <div class="commercial-decision-grid">
            <article><span>01</span><strong>Vereador vê saldo antes de pedir</strong><p>O sistema mostra quanto ainda pode indicar e explica para onde o valor foi comprometido.</p></article>
            <article><span>02</span><strong>Câmara protocola com segurança</strong><p>Checklist mínimo reduz indicação genérica, ausência de beneficiário e inconsistência de saúde.</p></article>
            <article><span>03</span><strong>Executivo decide por fila</strong><p>Receber, reservar e acompanhar deixam de depender de mensagem perdida.</p></article>
            <article><span>04</span><strong>Controle interno ganha rastreabilidade</strong><p>Histórico, documentos, alertas e dossiês ficam vinculados ao processo.</p></article>
        </div>
    </section>

    <section class="commercial-section commercial-pipeline">
        <div class="commercial-section-heading">
            <span class="commercial-kicker"><i data-lucide="waypoints" aria-hidden="true"></i> Esteira operacional</span>
            <h2>Da indicação à prestação final, sem módulos soltos competindo pela atenção.</h2>
        </div>
        <div class="commercial-pipeline-track">
            @foreach ([
                ['Câmara indica', 'Vereador cadastra pedido com valor, destino e justificativa.'],
                ['Conferência legislativa', 'Câmara valida objeto, teto, saúde e beneficiário.'],
                ['Executivo recebe', 'Prefeitura abre processo, secretaria e responsável.'],
                ['Reserva orçamentária', 'Valor fica reservado para execução municipal.'],
                ['Execução simplificada', 'Etapas, empenho, liquidação, pagamento e evidências.'],
                ['Prestação final', 'Checklist, dossiê, protocolo, decisão e arquivo.'],
            ] as $index => [$title, $description])
                <article>
                    <span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                    <strong>{{ $title }}</strong>
                    <p>{{ $description }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="commercial-section commercial-market">
        <div>
            <span class="commercial-kicker"><i data-lucide="rocket" aria-hidden="true"></i> Posicionamento comercial</span>
            <h2>Feito para municípios que não têm estrutura própria de tecnologia.</h2>
            <p>O TrilhaGov não tenta vender complexidade. Ele transforma norma municipal, TCESP, Audesp, controle interno e prestação de contas em uma rotina simples para gestor, vereador e equipe técnica.</p>
        </div>
        <div class="commercial-market-list">
            <span><i data-lucide="check" aria-hidden="true"></i> Onboarding do município e ativação do exercício</span>
            <span><i data-lucide="check" aria-hidden="true"></i> Portal Legislativo com linguagem simples para vereador</span>
            <span><i data-lucide="check" aria-hidden="true"></i> Fila do Executivo para receber, reservar e executar</span>
            <span><i data-lucide="check" aria-hidden="true"></i> Importação CSV para bases existentes</span>
            <span><i data-lucide="check" aria-hidden="true"></i> LGPD, MFA, logs e central de ocorrências</span>
        </div>
    </section>

    <section class="commercial-cta" aria-label="Acessar demonstracao">
        <div>
            <span class="commercial-kicker"><i data-lucide="sparkles" aria-hidden="true"></i> Demonstração pronta</span>
            <h2>Mostre o fluxo completo em poucos minutos.</h2>
            <p>Use a base de Guapiara para apresentar o papel do vereador, da Câmara, do Executivo e da prestação de contas com dados realistas.</p>
        </div>
        <a class="btn btn-primary btn-lg" href="{{ route('login') }}"><i data-lucide="arrow-right" aria-hidden="true"></i>Entrar no TrilhaGov</a>
    </section>
@endsection
