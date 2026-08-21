@extends('layouts.app')

@section('title', 'TrilhaGov | Plataforma municipal de emendas')

@section('content')
    <section class="commercial-page" aria-label="TrilhaGov">
        <div class="commercial-nav" data-commercial-nav>
            <a class="commercial-nav-brand" href="#" aria-label="TrilhaGov">
                <span class="commercial-nav-logo"><img src="{{ asset('images/trilhagov-symbol.svg') }}" alt=""></span>
                <span class="commercial-nav-wordmark">TrilhaGov</span>
            </a>
            <div class="commercial-nav-links">
                <a href="#problema">Problema</a>
                <a href="#esteira">Esteira</a>
                <a href="#rotina">Rotina</a>
                <a href="#sao-paulo">São Paulo</a>
            </div>
            <a class="commercial-nav-cta" href="{{ route('login') }}">
                Entrar na demo
                <i data-lucide="arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <section class="commercial-hero" aria-label="Plataforma municipal de emendas">
            <div class="commercial-hero-copy">
                <span class="commercial-kicker">
                    <i data-lucide="sparkles" aria-hidden="true"></i>
                    Emendas impositivas municipais
                </span>
                <h1>Emendas do início ao fim, sem retrabalho</h1>
                <p>
                    O TrilhaGov transforma a relação entre Câmara e Prefeitura em uma esteira clara:
                    vereador indica, a Câmara confere, o Executivo reserva, executa e presta contas com
                    rastreabilidade.
                </p>
                <div class="commercial-hero-actions">
                    <a class="btn btn-primary btn-lg" href="{{ route('login') }}">
                        Entrar na demo
                        <i data-lucide="arrow-right" aria-hidden="true"></i>
                    </a>
                    <a class="btn btn-outline-primary btn-lg" href="#esteira">
                        <i data-lucide="play-circle" aria-hidden="true"></i>
                        Ver como funciona
                    </a>
                </div>
                <div class="commercial-trust-row" aria-label="Principais automações">
                    <span>Lei Orgânica parametrizada</span>
                    <span>Cota e saúde automáticas</span>
                    <span>TCESP/Audesp quando aplicável</span>
                </div>
            </div>

            <div class="commercial-product-stage" aria-label="Prévia do painel do TrilhaGov">
                <div class="commercial-stage-card">
                    <div class="commercial-stage-stats">
                        <article>
                            <small>Saldo do vereador</small>
                            <strong>R$ 103,6 mil</strong>
                        </article>
                        <article>
                            <small>Comprometido</small>
                            <strong>R$ 51,8 mil</strong>
                        </article>
                        <article>
                            <small>Em execução</small>
                            <strong>3 ações</strong>
                        </article>
                    </div>

                    <div class="commercial-stage-pills">
                        <span>Câmara indica</span>
                        <span>Executivo recebe</span>
                        <span>Reserva automática</span>
                        <span>Prestação final</span>
                    </div>

                    <div class="commercial-assistant-card">
                        <span class="commercial-assistant-icon"><i data-lucide="sparkles" aria-hidden="true"></i></span>
                        <div>
                            <small>Assistente automático</small>
                            <strong>O sistema já sabe o que falta</strong>
                            <p>Saldo, saúde, secretaria, pendências e próximo passo aparecem antes da equipe perguntar.</p>
                        </div>
                    </div>
                </div>
                <p class="commercial-stage-footer"><i data-lucide="shield-check" aria-hidden="true"></i>Dossiê rastreável ponta a ponta</p>
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

        <section class="commercial-problem" id="problema">
            <div>
                <span class="commercial-eyebrow">O problema real</span>
                <h2>Emenda não é uma tela. Precisa de clareza operacional.</h2>
                <p>
                    A dor não é somente registrar uma emenda. A dor é controlar orçamento disponível,
                    reserva legal de saúde, documentos, impedimentos, execução, resposta à Câmara e
                    prestação de contas sem perder o controle.
                </p>
            </div>
            <div class="commercial-decision-grid">
                <article>
                    <span>01</span>
                    <strong>Cadeiras e cotas</strong>
                    <p>A norma da cidade define o teto. O sistema calcula o que o vereador pode usar.</p>
                </article>
                <article>
                    <span>02</span>
                    <strong>Reserva legal de saúde</strong>
                    <p>Percentuais aplicados na hora: cálculos rápidos, saldo visível e bloqueio de erro.</p>
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

        <section class="commercial-showcase-band" id="rotina" aria-label="Demonstração de rotina municipal">
            <div>
                <span class="commercial-kicker commercial-kicker-light">
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

        <section class="commercial-sp-map-section" id="sao-paulo" aria-label="Mapa de oportunidade em São Paulo">
            <div class="commercial-sp-map-copy">
                <span class="commercial-kicker">
                    <i data-lucide="map-pin-check" aria-hidden="true"></i>
                    Oportunidade em São Paulo
                </span>
                <h2>Muitos municípios ainda operam emendas com planilhas, mensagens e memória da equipe.</h2>
                <p>
                    São 645 cidades com regras próprias de Lei Orgânica. O TrilhaGov parametriza cada uma
                    e entrega a mesma esteira auditável, do interior à região metropolitana.
                </p>
                <div class="commercial-sp-map-list">
                    <article>
                        <strong>645</strong>
                        <span>municípios no estado</span>
                    </article>
                    <article>
                        <strong>Prioridade</strong>
                        <span>municípios sem fluxo digital consolidado</span>
                    </article>
                    <article>
                        <strong>Piloto</strong>
                        <span>implantação guiada por exercício e Câmara</span>
                    </article>
                </div>
            </div>

            <div class="commercial-sp-map-card" data-sp-map>
                <svg viewBox="0 0 640 451" role="img" aria-label="Mapa do estado de São Paulo com o contorno geográfico real e municípios-alvo do piloto">
                    <defs>
                        <linearGradient id="spMapLand" x1="80" x2="600" y1="40" y2="420" gradientUnits="userSpaceOnUse">
                            <stop offset="0" stop-color="#eaf4fb" />
                            <stop offset="0.55" stop-color="#c9e4f5" />
                            <stop offset="1" stop-color="#a9cfe8" />
                        </linearGradient>
                        <radialGradient id="spMapGlow" cx="42%" cy="38%" r="65%">
                            <stop offset="0" stop-color="#ffffff" stop-opacity=".65" />
                            <stop offset="1" stop-color="#7ab2d4" stop-opacity="0" />
                        </radialGradient>
                    </defs>
                    <path class="sp-map-shadow" d="M444.48 176.51 L448.32 171.73 L449.35 165.36 L452.55 165.51 L455.92 157.66 L455.46 149.6 L449.25 148.3 L447.24 144.79 L441.39 145.69 L439.75 143.44 L435.18 144.18 L428.99 147.94 L423.86 147.53 L424.91 143.25 L417.12 131.38 L415.5 117.28 L409.34 110.56 L412.79 99.9 L413.87 97.96 L416.59 98.37 L418.46 93.84 L414.81 85.23 L406.08 80.44 L405.62 73.37 L409.91 64.43 L407.95 60.32 L398.59 54.8 L396.53 52.62 L397.55 50.3 L395.06 46.88 L387.35 49.02 L386.66 51.04 L383.78 52.51 L379.11 47.73 L370.27 48.54 L367.17 57.95 L361.93 51.76 L359.44 57.83 L353.98 59.38 L349.31 55.91 L346.11 51.2 L344.52 54.64 L346.75 58.1 L343.77 59.2 L340.56 57.13 L334.77 57.1 L329.32 58.81 L322.79 58.65 L319.35 60.81 L314.06 59.65 L308.14 60.43 L306.01 61.72 L303.99 68.31 L305.27 76.87 L302.89 79.63 L299.13 77.16 L297.66 61.07 L292.55 59.87 L289.04 67.98 L285.02 71.04 L282.35 70.39 L280.15 67.17 L277.63 60.82 L277.11 53.42 L277.75 50.46 L280.88 49.22 L280.77 47.12 L267.74 47.73 L263.89 43.31 L261.49 42.72 L256.18 44.53 L248.83 44.03 L242.16 45.54 L232.6 44.15 L226.01 40.54 L211.22 40.21 L202.78 34 L195.83 36.53 L190.7 42.81 L182.68 44.89 L170.81 51.76 L164.41 66.56 L161.84 68.64 L146.86 73.87 L136.84 88.6 L130.24 95.07 L128.22 101.52 L128.94 114.65 L123.18 116.53 L120.24 120.04 L118.32 125.57 L112.61 128.76 L114.56 136.97 L113.75 141.82 L107.29 152.67 L104.84 154.28 L99.89 154.18 L98.33 157.97 L100.46 159.93 L101.3 165.4 L98.23 170.18 L94.55 171.89 L92.75 176.98 L84.46 185.83 L83.17 191.07 L78.76 197.57 L73.67 202.8 L53.8 213.96 L50.19 218.17 L40.99 221.92 L36.18 225.78 L34.17 230.83 L35.05 232.42 L42.67 227.27 L45.72 226.93 L49.96 229.87 L52.71 229.11 L60.28 231.18 L67.17 226.97 L72.96 231.68 L76.36 229.89 L85.65 231.77 L88.85 230.38 L90.63 234.54 L94.84 232.41 L95.13 227.18 L96.19 224.25 L97.88 223.52 L108.67 227.05 L114.09 231.51 L119.48 230.29 L121.95 231.3 L122.89 234.09 L128.67 233.35 L133.04 236.05 L138.45 234.8 L140.28 232.99 L151.87 234 L155.16 237.88 L160.65 240.4 L168.55 242.73 L175.53 242.77 L178.91 245.34 L182.07 250.45 L181.89 253.64 L187.4 253.79 L189.17 250.52 L194.18 251.7 L195.88 250.37 L199.1 252.5 L205.57 253.15 L206.16 250.98 L209.82 251.21 L211.04 253.42 L215.17 252.28 L219.76 253.75 L233.68 249.92 L235.27 252.23 L234.56 254.14 L239.09 256.97 L238.45 260.56 L243.44 261.58 L246.28 264.17 L250.35 264.6 L250.93 267.3 L254.94 271.7 L256.65 276.54 L255.96 281.22 L258.68 282.44 L258.23 285.31 L259.55 286.73 L257.02 291.18 L256.84 293.26 L258.37 294.11 L257.04 299.34 L261.63 305.78 L260.13 314.29 L257.87 315.15 L259.22 318.58 L262.49 321.3 L263.36 320.5 L270.62 333.68 L275.23 335.81 L274.33 341.57 L277.23 343.69 L278.41 347.23 L283.9 350.18 L280.55 354.33 L281.07 358.49 L277.85 360.93 L278.86 363.39 L276.55 364.75 L276.81 372.23 L278.98 374.27 L283.47 374.82 L284.89 372.87 L289.63 373.95 L296.11 371.6 L299.18 372.97 L306.97 372.33 L311.02 374.46 L315.71 373.4 L317.55 374.88 L321.29 372.59 L324.13 374.11 L328.63 377.94 L325.18 382.11 L324.44 393.62 L322.47 395.76 L325.15 401.37 L326.82 402.52 L330.41 396.41 L334.38 394.18 L338.27 398.06 L342.53 398.25 L343.48 395.36 L345.4 394.94 L347.39 397.74 L346.91 400.85 L350.59 405.92 L349.06 409.73 L353.5 412.91 L359.23 411.53 L354.58 416.93 L365.6 406.65 L366.52 399.2 L370.78 393.91 L384.85 381.62 L424.47 354.6 L423.53 352.51 L425.09 348.81 L434.64 341.4 L465.95 324.27 L469.07 325.8 L468.19 327.96 L473.33 325.64 L476.01 325.92 L480.14 316.34 L482.99 313.96 L490.41 311.41 L498.74 309.53 L503.8 309.86 L517.12 312.08 L520.49 315.28 L522.88 313.75 L525.06 314.68 L527.28 312.99 L525.31 306.08 L526.62 300.23 L529.8 300.15 L532.71 296.89 L537.01 298.14 L539.03 296.23 L538.59 293.65 L542.44 294.31 L541.5 291.39 L548.05 291.45 L549.18 285.88 L554.09 284.74 L558.66 280.15 L563.06 282.62 L562.85 283.9 L570.27 282.48 L559.71 272.71 L560.64 269.81 L563.86 268.29 L565.94 255.76 L584.96 246.41 L586.73 249.05 L589.53 246.66 L592.21 247.21 L599.48 245.18 L601.23 242.57 L600.12 240.88 L605.47 236.39 L604.8 231.9 L594.71 228.57 L593.51 230.13 L592.13 227.48 L583.93 232.09 L581.67 229.8 L575.28 229.59 L575.37 226.24 L573.87 226.14 L568.28 216.52 L564.82 215.85 L554.81 220.67 L549.12 220.35 L544.67 222.2 L536.35 228.04 L535.41 230.1 L531.33 230.53 L527.04 233.03 L521.9 228.66 L521.36 231.31 L519.05 232.56 L515.5 232.98 L516.81 231.23 L515.75 229.43 L510.01 232.86 L509.06 231.83 L510.42 229.25 L509.21 227.47 L506.14 228.41 L505.93 230.61 L507.98 232.76 L500.71 236.15 L501.13 238.82 L505.77 237.76 L507.07 241.07 L503.32 243.58 L503.5 245.66 L498.38 245.78 L496.91 248.12 L494.48 245.22 L488.12 249.37 L482.57 249.7 L479.76 251.67 L479.62 247.88 L472.2 249.46 L470.82 248.59 L466.6 250.45 L464.59 248.51 L464.71 244.57 L467.39 240.82 L458.4 236.59 L458.66 234.38 L463.52 233.68 L461.52 229.27 L462.14 226.4 L459.46 223.89 L453.99 222 L453.17 218.69 L446.03 216.51 L446.39 213.11 L442.4 208.99 L445.69 200.11 L447.37 200.27 L449.45 198.17 L447.29 194.12 L442.64 193.53 L446.14 192.53 L444.82 190.35 L449.14 187.41 L445.56 181.6 L447.24 179.48 L444.48 176.51 Z" transform="translate(4 6)" />
                    <path class="sp-map-shape" d="M444.48 176.51 L448.32 171.73 L449.35 165.36 L452.55 165.51 L455.92 157.66 L455.46 149.6 L449.25 148.3 L447.24 144.79 L441.39 145.69 L439.75 143.44 L435.18 144.18 L428.99 147.94 L423.86 147.53 L424.91 143.25 L417.12 131.38 L415.5 117.28 L409.34 110.56 L412.79 99.9 L413.87 97.96 L416.59 98.37 L418.46 93.84 L414.81 85.23 L406.08 80.44 L405.62 73.37 L409.91 64.43 L407.95 60.32 L398.59 54.8 L396.53 52.62 L397.55 50.3 L395.06 46.88 L387.35 49.02 L386.66 51.04 L383.78 52.51 L379.11 47.73 L370.27 48.54 L367.17 57.95 L361.93 51.76 L359.44 57.83 L353.98 59.38 L349.31 55.91 L346.11 51.2 L344.52 54.64 L346.75 58.1 L343.77 59.2 L340.56 57.13 L334.77 57.1 L329.32 58.81 L322.79 58.65 L319.35 60.81 L314.06 59.65 L308.14 60.43 L306.01 61.72 L303.99 68.31 L305.27 76.87 L302.89 79.63 L299.13 77.16 L297.66 61.07 L292.55 59.87 L289.04 67.98 L285.02 71.04 L282.35 70.39 L280.15 67.17 L277.63 60.82 L277.11 53.42 L277.75 50.46 L280.88 49.22 L280.77 47.12 L267.74 47.73 L263.89 43.31 L261.49 42.72 L256.18 44.53 L248.83 44.03 L242.16 45.54 L232.6 44.15 L226.01 40.54 L211.22 40.21 L202.78 34 L195.83 36.53 L190.7 42.81 L182.68 44.89 L170.81 51.76 L164.41 66.56 L161.84 68.64 L146.86 73.87 L136.84 88.6 L130.24 95.07 L128.22 101.52 L128.94 114.65 L123.18 116.53 L120.24 120.04 L118.32 125.57 L112.61 128.76 L114.56 136.97 L113.75 141.82 L107.29 152.67 L104.84 154.28 L99.89 154.18 L98.33 157.97 L100.46 159.93 L101.3 165.4 L98.23 170.18 L94.55 171.89 L92.75 176.98 L84.46 185.83 L83.17 191.07 L78.76 197.57 L73.67 202.8 L53.8 213.96 L50.19 218.17 L40.99 221.92 L36.18 225.78 L34.17 230.83 L35.05 232.42 L42.67 227.27 L45.72 226.93 L49.96 229.87 L52.71 229.11 L60.28 231.18 L67.17 226.97 L72.96 231.68 L76.36 229.89 L85.65 231.77 L88.85 230.38 L90.63 234.54 L94.84 232.41 L95.13 227.18 L96.19 224.25 L97.88 223.52 L108.67 227.05 L114.09 231.51 L119.48 230.29 L121.95 231.3 L122.89 234.09 L128.67 233.35 L133.04 236.05 L138.45 234.8 L140.28 232.99 L151.87 234 L155.16 237.88 L160.65 240.4 L168.55 242.73 L175.53 242.77 L178.91 245.34 L182.07 250.45 L181.89 253.64 L187.4 253.79 L189.17 250.52 L194.18 251.7 L195.88 250.37 L199.1 252.5 L205.57 253.15 L206.16 250.98 L209.82 251.21 L211.04 253.42 L215.17 252.28 L219.76 253.75 L233.68 249.92 L235.27 252.23 L234.56 254.14 L239.09 256.97 L238.45 260.56 L243.44 261.58 L246.28 264.17 L250.35 264.6 L250.93 267.3 L254.94 271.7 L256.65 276.54 L255.96 281.22 L258.68 282.44 L258.23 285.31 L259.55 286.73 L257.02 291.18 L256.84 293.26 L258.37 294.11 L257.04 299.34 L261.63 305.78 L260.13 314.29 L257.87 315.15 L259.22 318.58 L262.49 321.3 L263.36 320.5 L270.62 333.68 L275.23 335.81 L274.33 341.57 L277.23 343.69 L278.41 347.23 L283.9 350.18 L280.55 354.33 L281.07 358.49 L277.85 360.93 L278.86 363.39 L276.55 364.75 L276.81 372.23 L278.98 374.27 L283.47 374.82 L284.89 372.87 L289.63 373.95 L296.11 371.6 L299.18 372.97 L306.97 372.33 L311.02 374.46 L315.71 373.4 L317.55 374.88 L321.29 372.59 L324.13 374.11 L328.63 377.94 L325.18 382.11 L324.44 393.62 L322.47 395.76 L325.15 401.37 L326.82 402.52 L330.41 396.41 L334.38 394.18 L338.27 398.06 L342.53 398.25 L343.48 395.36 L345.4 394.94 L347.39 397.74 L346.91 400.85 L350.59 405.92 L349.06 409.73 L353.5 412.91 L359.23 411.53 L354.58 416.93 L365.6 406.65 L366.52 399.2 L370.78 393.91 L384.85 381.62 L424.47 354.6 L423.53 352.51 L425.09 348.81 L434.64 341.4 L465.95 324.27 L469.07 325.8 L468.19 327.96 L473.33 325.64 L476.01 325.92 L480.14 316.34 L482.99 313.96 L490.41 311.41 L498.74 309.53 L503.8 309.86 L517.12 312.08 L520.49 315.28 L522.88 313.75 L525.06 314.68 L527.28 312.99 L525.31 306.08 L526.62 300.23 L529.8 300.15 L532.71 296.89 L537.01 298.14 L539.03 296.23 L538.59 293.65 L542.44 294.31 L541.5 291.39 L548.05 291.45 L549.18 285.88 L554.09 284.74 L558.66 280.15 L563.06 282.62 L562.85 283.9 L570.27 282.48 L559.71 272.71 L560.64 269.81 L563.86 268.29 L565.94 255.76 L584.96 246.41 L586.73 249.05 L589.53 246.66 L592.21 247.21 L599.48 245.18 L601.23 242.57 L600.12 240.88 L605.47 236.39 L604.8 231.9 L594.71 228.57 L593.51 230.13 L592.13 227.48 L583.93 232.09 L581.67 229.8 L575.28 229.59 L575.37 226.24 L573.87 226.14 L568.28 216.52 L564.82 215.85 L554.81 220.67 L549.12 220.35 L544.67 222.2 L536.35 228.04 L535.41 230.1 L531.33 230.53 L527.04 233.03 L521.9 228.66 L521.36 231.31 L519.05 232.56 L515.5 232.98 L516.81 231.23 L515.75 229.43 L510.01 232.86 L509.06 231.83 L510.42 229.25 L509.21 227.47 L506.14 228.41 L505.93 230.61 L507.98 232.76 L500.71 236.15 L501.13 238.82 L505.77 237.76 L507.07 241.07 L503.32 243.58 L503.5 245.66 L498.38 245.78 L496.91 248.12 L494.48 245.22 L488.12 249.37 L482.57 249.7 L479.76 251.67 L479.62 247.88 L472.2 249.46 L470.82 248.59 L466.6 250.45 L464.59 248.51 L464.71 244.57 L467.39 240.82 L458.4 236.59 L458.66 234.38 L463.52 233.68 L461.52 229.27 L462.14 226.4 L459.46 223.89 L453.99 222 L453.17 218.69 L446.03 216.51 L446.39 213.11 L442.4 208.99 L445.69 200.11 L447.37 200.27 L449.45 198.17 L447.29 194.12 L442.64 193.53 L446.14 192.53 L444.82 190.35 L449.14 187.41 L445.56 181.6 L447.24 179.48 L444.48 176.51 Z" />
                    <path class="sp-map-island" d="M534.05 317.21 L537.74 311.39 L535.78 308.37 L530.88 307.4 L529.23 313.05 L523.18 318.67 L524.21 321.71 L526.54 322.02 L533.25 319.97 L535.42 323.85 L537.7 322.67 L537.43 319.98 L534.05 317.21 Z" />
                    <g class="sp-map-points">
                        <g class="sp-map-city" transform="translate(399.07 86.55)">
                            <title>Franca - município-alvo</title>
                            <circle r="6" />
                            <text x="12" y="4">Franca</text>
                        </g>
                        <g class="sp-map-city" transform="translate(372.86 130.8)">
                            <title>Ribeirão Preto - município-alvo</title>
                            <circle r="6" />
                            <text x="12" y="4">Ribeirão Preto</text>
                        </g>
                        <g class="sp-map-city" transform="translate(292.88 209.56)">
                            <title>Bauru - município-alvo</title>
                            <circle r="6" />
                            <text x="-14" y="-11" text-anchor="end">Bauru</text>
                        </g>
                        <g class="sp-map-city" transform="translate(420.69 250.78)">
                            <title>Campinas - município-alvo</title>
                            <circle r="6" />
                            <text x="12" y="4">Campinas</text>
                        </g>
                        <g class="sp-map-city" transform="translate(357.33 298)">
                            <title>Itapetininga - município-alvo</title>
                            <circle r="6" />
                            <text x="-14" y="4" text-anchor="end">Itapetininga</text>
                        </g>
                        <g class="sp-map-city sp-map-city-home" transform="translate(327.77 339.51)">
                            <title>Guapiara - município demonstrativo</title>
                            <circle r="7" />
                            <text x="-14" y="4" text-anchor="end">Guapiara</text>
                        </g>
                        <g class="sp-map-city" transform="translate(370.73 360.75)">
                            <title>Registro - município-alvo</title>
                            <circle r="6" />
                            <text x="12" y="16">Registro</text>
                        </g>
                    </g>
                    <g class="sp-map-points sp-map-city-secondary">
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(395.74 291.75)">
                            <title>Sorocaba</title>
                            <circle />
                            <text x="9" y="4">Sorocaba</text>
                        </g>
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(272.73 105.43)">
                            <title>São José do Rio Preto</title>
                            <circle />
                            <text x="-9" y="-8" text-anchor="end">S. J. Rio Preto</text>
                        </g>
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(143.96 196.46)">
                            <title>Presidente Prudente</title>
                            <circle />
                            <text x="9" y="4">Pres. Prudente</text>
                        </g>
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(236.28 202.8)">
                            <title>Marília</title>
                            <circle />
                            <text x="9" y="15">Marília</text>
                        </g>
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(205.11 132.97)">
                            <title>Araçatuba</title>
                            <circle />
                            <text x="-9" y="-8" text-anchor="end">Araçatuba</text>
                        </g>
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(467.3 323.56)">
                            <title>Santos</title>
                            <circle />
                            <text x="9" y="4">Santos</text>
                        </g>
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(448.15 295.4)">
                            <title>São Paulo (capital)</title>
                            <circle />
                            <text x="9" y="-7">São Paulo</text>
                        </g>
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(383.03 238.46)">
                            <title>Piracicaba</title>
                            <circle />
                            <text x="-9" y="-7" text-anchor="end">Piracicaba</text>
                        </g>
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(432.2 270.41)">
                            <title>Jundiaí</title>
                            <circle />
                            <text x="-9" y="-7" text-anchor="end">Jundiaí</text>
                        </g>
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(367.74 189.19)">
                            <title>São Carlos</title>
                            <circle />
                            <text x="9" y="4">São Carlos</text>
                        </g>
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(516.57 259.35)">
                            <title>Taubaté</title>
                            <circle />
                            <text x="9" y="4">Taubaté</text>
                        </g>
                        <g class="sp-map-city sp-map-city-secondary" transform="translate(332.52 249.54)">
                            <title>Botucatu</title>
                            <circle />
                            <text x="-9" y="17" text-anchor="end">Botucatu</text>
                        </g>
                    </g>
                </svg>
            </div>
        </section>

        <section class="commercial-section commercial-pipeline" id="esteira">
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
