@extends('layouts.app')

@section('title', 'TrilhaGov | Gestão municipal de emendas')

@section('content')
    <section class="marketing-hero">
        <div class="marketing-hero-copy">
            <span class="marketing-kicker">SaaS municipal para emendas impositivas</span>
            <h1>Controle Câmara, Executivo, execução e prestação de contas em um só fluxo.</h1>
            <p>O TrilhaGov foi desenhado para municípios que ainda controlam emendas por planilhas, mensagens e pastas soltas. A plataforma guia vereador, gestor, controle interno e contabilidade sem exigir que cada usuário entenda toda a norma.</p>
            <div class="marketing-actions">
                <a class="btn btn-primary" href="{{ route('login') }}"><i data-lucide="log-in" aria-hidden="true"></i>Acessar demonstração</a>
                <a class="btn btn-outline-primary" href="#fluxo"><i data-lucide="route" aria-hidden="true"></i>Ver fluxo</a>
            </div>
        </div>
        <div class="marketing-product-panel" aria-label="Resumo visual do TrilhaGov">
            <div class="marketing-product-top">
                <span><i data-lucide="landmark" aria-hidden="true"></i>Guapiara / SP</span>
                <strong>Portal de Emendas</strong>
            </div>
            <div class="marketing-metric-row">
                <article><small>Cotas</small><strong>11 vereadores</strong></article>
                <article><small>Saúde</small><strong>50% reservado</strong></article>
                <article><small>TCESP</small><strong>Audesp ativo</strong></article>
            </div>
            <div class="marketing-flow-card">
                <span>1</span><div><strong>Câmara indica</strong><small>Vereador vê saldo, saúde e status.</small></div>
            </div>
            <div class="marketing-flow-card">
                <span>2</span><div><strong>Executivo decide</strong><small>Recebe, reserva, executa e acompanha.</small></div>
            </div>
            <div class="marketing-flow-card">
                <span>3</span><div><strong>Prestação fecha</strong><small>Dossiê, evidências, protocolo e histórico.</small></div>
            </div>
        </div>
    </section>

    <section class="marketing-proof" aria-label="Principais dores resolvidas">
        <article><i data-lucide="wallet-cards" aria-hidden="true"></i><strong>Cota automática</strong><span>RCL, percentual, cadeiras e reserva de saúde viram saldo por vereador.</span></article>
        <article><i data-lucide="shield-check" aria-hidden="true"></i><strong>Controle TCESP</strong><span>Matriz, dossiê e manifesto reduzem risco de pacote incompleto.</span></article>
        <article><i data-lucide="file-spreadsheet" aria-hidden="true"></i><strong>Importação assistida</strong><span>CSV para bases antigas e validação antes de gravar dados.</span></article>
        <article><i data-lucide="bell-ring" aria-hidden="true"></i><strong>Alertas de prazo</strong><span>Pendências por perfil, vencimentos e diligências no sistema.</span></article>
    </section>

    <section class="marketing-section" id="fluxo">
        <div class="marketing-section-heading">
            <span class="marketing-kicker">Fluxo municipal</span>
            <h2>Menos módulos soltos, mais esteira operacional.</h2>
            <p>O sistema organiza a rotina real: o vereador só indica e acompanha; o Executivo recebe, reserva e executa; a prestação consolida documentos e protocolo.</p>
        </div>
        <div class="marketing-timeline">
            @foreach ([
                ['Câmara indica', 'Vereador cadastra proposta com saldo e saúde calculados.'],
                ['Conferência legislativa', 'Câmara verifica requisitos mínimos antes de protocolar.'],
                ['Executivo recebe', 'Prefeitura abre processo, secretaria e responsáveis.'],
                ['Reserva orçamentária', 'Valor fica formalmente reservado para execução.'],
                ['Execução simplificada', 'Etapas, empenho, liquidação, pagamento e evidências.'],
                ['Prestação final', 'Checklist, dossiê, protocolo, aprovação e arquivo.'],
            ] as $index => [$title, $description])
                <article>
                    <span>{{ $index + 1 }}</span>
                    <strong>{{ $title }}</strong>
                    <p>{{ $description }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="marketing-section marketing-two-columns">
        <div>
            <span class="marketing-kicker">Para vender como piloto</span>
            <h2>O diferencial está na simplicidade para municípios pequenos.</h2>
            <p>Estados e União têm estrutura técnica. O TrilhaGov nasce para prefeitura e Câmara que precisam cumprir norma, responder controle e evitar multa sem montar uma equipe de TI.</p>
        </div>
        <div class="marketing-check-list">
            <span><i data-lucide="check" aria-hidden="true"></i>Portal Legislativo com saldo individual</span>
            <span><i data-lucide="check" aria-hidden="true"></i>Norma municipal ativa por exercício</span>
            <span><i data-lucide="check" aria-hidden="true"></i>Audesp e TCESP para municípios paulistas</span>
            <span><i data-lucide="check" aria-hidden="true"></i>Prestação de contas com pacote final</span>
            <span><i data-lucide="check" aria-hidden="true"></i>LGPD, MFA e defesa contra acesso indevido</span>
        </div>
    </section>
@endsection
