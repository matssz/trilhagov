@extends('layouts.app')

@section('title', 'Portal Legislativo | TrilhaGov')

@section('content')
    <div class="page-heading legislative-heading">
        <div>
            <span class="eyebrow">{{ $municipality->name }} · Câmara e Executivo</span>
            <h1>Portal Legislativo</h1>
            <p>{{ $role === App\Models\User::ROLE_COUNCILOR ? ($membership->legislative_name.' · '.$membership->legislative_party) : 'Indicações da Câmara e acompanhamento da execução municipal' }}</p>
        </div>
        @if ($role === App\Models\User::ROLE_COUNCILOR && $profile && ($councilorGuide['canCreate'] ?? false))
            <a class="btn btn-primary" href="{{ route('legislative.create', ['year' => $year]) }}"><i data-lucide="plus" aria-hidden="true"></i>Nova proposta</a>
        @elseif ($role === App\Models\User::ROLE_COUNCILOR)
            <button class="btn btn-outline-secondary" type="button" disabled title="A configuração normativa precisa estar vigente"><i data-lucide="lock-keyhole" aria-hidden="true"></i>Cadastro indisponível</button>
        @endif
    </div>

    @if (! $profile)
        <div class="legislative-notice is-danger"><i data-lucide="circle-alert" aria-hidden="true"></i><div><strong>Configuração de {{ $year }} não ativada</strong><p>As cotas e a reserva de saúde não podem ser calculadas sem a versão normativa vigente.@if($activeYears !== []) Selecione um dos exercícios ativos no filtro abaixo.@else O gestor municipal precisa preparar e ativar as regras do exercício.@endif</p></div></div>
    @elseif ($quota && $quota['legacy_ceiling'])
        <div class="legislative-notice is-warning"><i data-lucide="triangle-alert" aria-hidden="true"></i><div><strong>Cota individual provisória</strong><p>Informe o número de vereadores na configuração municipal para dividir corretamente o teto global da Câmara.</p></div></div>
    @endif

    @if ($quota)
        @if ($role === App\Models\User::ROLE_COUNCILOR)
            <section class="legislative-councilor-home">
                <div class="councilor-home-main">
                    <span class="page-kicker">Minha cota</span>
                    <h2>{{ $quota['remaining'] === null ? 'Cota em configuração' : 'R$ '.number_format($quota['remaining'], 2, ',', '.').' disponíveis' }}</h2>
                    <strong class="councilor-home-next">{{ $councilorGuide['nextTitle'] ?? 'Pronto para indicar' }}</strong>
                    <p>{{ $councilorGuide['nextText'] ?? 'O sistema calcula automaticamente saldo, limite e reserva de saúde.' }}</p>
                    <span class="councilor-home-badge is-{{ $councilorGuide['badgeTone'] ?? 'success' }}">{{ $councilorGuide['badge'] ?? 'Pode indicar' }}</span>
                </div>
                <div class="councilor-home-actions">
                    @if ($councilorGuide['canCreate'] ?? false)
                        <a class="btn btn-primary" href="{{ route('legislative.create', ['year' => $year]) }}"><i data-lucide="plus" aria-hidden="true"></i>Nova proposta</a>
                    @else
                        <button class="btn btn-outline-secondary" type="button" disabled><i data-lucide="lock-keyhole" aria-hidden="true"></i>Aguardando liberação</button>
                    @endif
                    <a class="btn btn-outline-primary" href="#minhas-propostas"><i data-lucide="list-checks" aria-hidden="true"></i>Ver propostas</a>
                </div>
                <div class="councilor-operating-strip" aria-label="Ações principais do vereador">
                    <article>
                        <span>1</span>
                        <div><strong>Indicar pedido</strong><small>Escolha objeto, valor e destino. O sistema confere cota e saúde.</small></div>
                        @if ($councilorGuide['canCreate'] ?? false)
                            <a href="{{ route('legislative.create', ['year' => $year]) }}">Começar</a>
                        @else
                            <em>Bloqueado</em>
                        @endif
                    </article>
                    <article>
                        <span>2</span>
                        <div><strong>Acompanhar resposta</strong><small>Veja se está com você, Câmara, Executivo ou em execução.</small></div>
                        <a href="#minhas-propostas">Ver etapas</a>
                    </article>
                    <article>
                        <span>3</span>
                        <div><strong>Entender saldo</strong><small>Valor indicado fica comprometido, mas só executa depois da reserva.</small></div>
                        <a href="#explicacao-cota">Ver cota</a>
                    </article>
                </div>
                <div class="councilor-home-progress">
                    <div>
                        <span>Uso da cota</span>
                        <strong>{{ $councilorGuide['quotaProgress'] === null ? 'A configurar' : $councilorGuide['quotaProgress'].'%' }}</strong>
                        <div class="councilor-progress-track"><i style="width: {{ $councilorGuide['quotaProgress'] ?? 0 }}%"></i></div>
                    </div>
                    <div>
                        <span>Reserva mínima de saúde</span>
                        <strong>{{ $councilorGuide['healthProgress'] === null ? 'A configurar' : $councilorGuide['healthProgress'].'%' }}</strong>
                        <div class="councilor-progress-track is-health"><i style="width: {{ $councilorGuide['healthProgress'] ?? 0 }}%"></i></div>
                    </div>
                    <div>
                        <span>Em elaboração</span>
                        <strong>{{ $councilorGuide['statusCounts'][App\Models\LegislativeProposal::STATUS_DRAFT] ?? 0 }}</strong>
                    </div>
                    <div>
                        <span>No Executivo</span>
                        <strong>{{ ($councilorGuide['statusCounts'][App\Models\LegislativeProposal::STATUS_SENT] ?? 0) + ($councilorGuide['statusCounts'][App\Models\LegislativeProposal::STATUS_RECEIVED] ?? 0) + ($councilorGuide['statusCounts'][App\Models\LegislativeProposal::STATUS_RESERVED] ?? 0) }}</strong>
                    </div>
                </div>
                <div class="councilor-plain-cards" aria-label="Resumo simples para o vereador">
                    @foreach($councilorGuide['simpleCards'] as $card)
                        <article class="is-{{ $card['tone'] }}">
                            <span><i data-lucide="{{ $card['icon'] }}" aria-hidden="true"></i></span>
                            <div>
                                <small>{{ $card['label'] }}</small>
                                <strong>{{ $card['value'] }}</strong>
                                <p>{{ $card['description'] }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="councilor-simple-checklist" aria-label="Visão simples do vereador">
                    <div>
                        <span class="page-kicker">Visão simples do vereador</span>
                        <strong>Antes de cadastrar, confira estes pontos</strong>
                    </div>
                    <div class="councilor-simple-checklist-items">
                        @foreach($councilorGuide['plainChecklist'] as $item)
                            <article class="{{ $item['done'] ? 'is-done' : 'is-pending' }}">
                                <i data-lucide="{{ $item['done'] ? 'circle-check' : 'circle-dot' }}" aria-hidden="true"></i>
                                <span>{{ $item['label'] }}</span>
                                <small>{{ $item['detail'] }}</small>
                            </article>
                        @endforeach
                    </div>
                </div>
                <div class="councilor-action-summary" aria-label="Resumo de andamento do vereador">
                    <article class="{{ ($councilorGroups['summary']['action']['count'] ?? 0) > 0 ? 'needs-action' : 'is-clear' }}">
                        <span><i data-lucide="pencil-line" aria-hidden="true"></i></span>
                        <div>
                            <small>Depende de você</small>
                            <strong>{{ $councilorGroups['summary']['action']['count'] ?? 0 }} proposta(s)</strong>
                            <p>Rascunhos ou devoluções que precisam de ajuste antes de seguir.</p>
                        </div>
                        <a href="{{ $councilorGroups['summary']['action']['url'] ?? route('legislative.index', ['year' => $year]) }}">Abrir</a>
                    </article>
                    <article>
                        <span><i data-lucide="landmark" aria-hidden="true"></i></span>
                        <div>
                            <small>Com a Câmara</small>
                            <strong>{{ $councilorGroups['summary']['chamber']['count'] ?? 0 }} proposta(s)</strong>
                            <p>Itens em conferência legislativa ou prontos para protocolo.</p>
                        </div>
                        <a href="{{ $councilorGroups['summary']['chamber']['url'] ?? route('legislative.index', ['year' => $year]) }}">Ver</a>
                    </article>
                    <article>
                        <span><i data-lucide="building-2" aria-hidden="true"></i></span>
                        <div>
                            <small>Com o Executivo</small>
                            <strong>{{ $councilorGroups['summary']['executive']['count'] ?? 0 }} proposta(s)</strong>
                            <p>Protocoladas, recebidas ou com reserva orçamentária aberta.</p>
                        </div>
                        <a href="{{ $councilorGroups['summary']['executive']['url'] ?? route('legislative.index', ['year' => $year]) }}">Acompanhar</a>
                    </article>
                    @if($councilorGroups['next_url'] ?? null)
                        <a class="councilor-next-action" href="{{ $councilorGroups['next_url'] }}">
                            <i data-lucide="arrow-right" aria-hidden="true"></i>
                            <span><small>Próxima ação sugerida</small><strong>{{ $councilorGroups['next_label'] }}</strong></span>
                        </a>
                    @endif
                </div>
                <div class="councilor-decision-strip" aria-label="Resumo automático para o vereador">
                    <article>
                        <span><i data-lucide="wallet-cards" aria-hidden="true"></i></span>
                        <div>
                            <small>Quanto ainda posso indicar</small>
                            <strong>{{ $quota['remaining'] === null ? 'A configurar' : 'R$ '.number_format($quota['remaining'], 2, ',', '.') }}</strong>
                            <p>Limite calculado pela norma municipal ativa.</p>
                        </div>
                    </article>
                    <article class="{{ ($quota['health_gap'] ?? 0) > 0 ? 'needs-health' : 'is-ok' }}">
                        <span><i data-lucide="heart-pulse" aria-hidden="true"></i></span>
                        <div>
                            <small>Reserva de saúde</small>
                            <strong>{{ ($quota['health_gap'] ?? 0) > 0 ? 'Faltam R$ '.number_format($quota['health_gap'], 2, ',', '.') : 'Atendida' }}</strong>
                            <p>{{ ($quota['health_gap'] ?? 0) > 0 ? 'Priorize uma proposta marcada para saúde.' : 'Você já preservou a proporção mínima.' }}</p>
                        </div>
                    </article>
                    <article>
                        <span><i data-lucide="route" aria-hidden="true"></i></span>
                        <div>
                            <small>Próximo movimento</small>
                            <strong>{{ $councilorGroups['next_label'] ?? 'Ver propostas' }}</strong>
                            <p>{{ $councilorGuide['nextText'] ?? 'Acompanhe a etapa de cada proposta.' }}</p>
                        </div>
                    </article>
                </div>
                <div class="councilor-money-guide" id="explicacao-cota" aria-label="Como a cota e consumida">
                    <div>
                        <span><i data-lucide="info" aria-hidden="true"></i></span>
                        <div>
                            <strong>O valor não some da cota</strong>
                            <p>Quando você salva uma proposta, o valor fica comprometido nela. Ele ainda não foi pago nem transferido: só vira execução depois da conferência da Câmara, recebimento do Executivo e reserva orçamentária.</p>
                        </div>
                    </div>
                    <ol>
                        <li><span>1</span><strong>Indicado</strong><small>Abate do saldo para evitar ultrapassar a cota.</small></li>
                        <li><span>2</span><strong>Conferido</strong><small>Câmara valida valor, objeto e saúde.</small></li>
                        <li><span>3</span><strong>Reservado</strong><small>Executivo confirma dotação para executar.</small></li>
                        <li><span>4</span><strong>Executado</strong><small>Prefeitura empenha, paga e entrega.</small></li>
                    </ol>
                </div>
                <div class="councilor-home-steps">
                    <span><i data-lucide="edit-3" aria-hidden="true"></i>Vereador indica</span>
                    <span><i data-lucide="badge-check" aria-hidden="true"></i>Câmara confere</span>
                    <span><i data-lucide="send" aria-hidden="true"></i>Executivo recebe</span>
                    <span><i data-lucide="building-2" aria-hidden="true"></i>Prefeitura executa</span>
                </div>
                <div class="councilor-simple-guide" aria-label="Guia simples para o vereador">
                    <article>
                        <span><i data-lucide="mouse-pointer-click" aria-hidden="true"></i></span>
                        <div>
                            <small>O que você faz</small>
                            <strong>Escolhe destino, valor e motivo</strong>
                            <p>O formulário pede o mínimo. Campos técnicos podem ser completados pela equipe municipal depois.</p>
                        </div>
                    </article>
                    <article>
                        <span><i data-lucide="calculator" aria-hidden="true"></i></span>
                        <div>
                            <small>O que o sistema faz</small>
                            <strong>Confere saldo e saúde</strong>
                            <p>A cota vem da RCL e da Lei Orgânica ativa. Saúde é sinalizada automaticamente quando houver indícios.</p>
                        </div>
                    </article>
                    <article>
                        <span><i data-lucide="eye" aria-hidden="true"></i></span>
                        <div>
                            <small>Depois do envio</small>
                            <strong>Você acompanha tudo aqui</strong>
                            <p>Cada proposta mostra se está com você, com a Câmara, com o Executivo ou em execução.</p>
                        </div>
                    </article>
                </div>
            </section>

            <section class="councilor-workspace" aria-label="Painel do vereador">
                <div class="councilor-workspace-header">
                    <div>
                        <span class="page-kicker">Meu acompanhamento</span>
                        <h2>Suas propostas por etapa</h2>
                        <p>Veja o que depende de você, o que está com a Câmara e o que já entrou no Executivo.</p>
                    </div>
                    @if($councilorGroups['next_url'] ?? null)
                        <a class="btn btn-primary" href="{{ $councilorGroups['next_url'] }}">
                            <i data-lucide="arrow-right" aria-hidden="true"></i>{{ $councilorGroups['next_label'] }}
                        </a>
                    @endif
                </div>
                <div class="councilor-stage-grid">
                    @foreach($councilorGroups['groups'] as $group)
                        <article class="councilor-stage-card is-{{ $group['tone'] }}">
                            <header>
                                <span><i data-lucide="{{ $group['icon'] }}" aria-hidden="true"></i></span>
                                <div>
                                    <strong>{{ $group['title'] }}</strong>
                                    <small>{{ $group['count'] }} proposta(s) · R$ {{ number_format($group['amount'], 2, ',', '.') }}</small>
                                </div>
                            </header>
                            <p>{{ $group['description'] }}</p>
                            <div class="councilor-stage-items">
                                @forelse($group['items'] as $item)
                                    <a href="{{ $item->councilor_action_url }}">
                                        <span>{{ $item->reference }} · {{ $item->statusLabel() }}</span>
                                        <strong>{{ $item->object }}</strong>
                                        <small>{{ $item->updated_at->diffForHumans() }}</small>
                                    </a>
                                @empty
                                    <div>{{ $group['empty'] }}</div>
                                @endforelse
                            </div>
                            @if($group['hidden_count'] > 0)
                                <a class="councilor-stage-filter" href="{{ $group['filter_url'] }}">Ver mais {{ $group['hidden_count'] }}</a>
                            @elseif($group['count'] > 0)
                                <a class="councilor-stage-filter" href="{{ $group['filter_url'] }}">Filtrar etapa</a>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="legislative-automation-panel">
            <i data-lucide="calculator" aria-hidden="true"></i>
            <div>
                <strong>Cota calculada automaticamente pelas normas de {{ $year }}</strong>
                <p>O sistema usa RCL anterior, percentual impositivo, número de vereadores e reserva de saúde cadastrados pelo gestor municipal. O vereador só precisa indicar objeto, valor e se a proposta atende saúde.</p>
            </div>
            <span>{{ $quota['health_percentage'] === null ? 'Saúde a configurar' : number_format($quota['health_percentage'], 2, ',', '.').'% para saúde' }}</span>
        </section>
        <section class="legislative-quota-band">
            <div><span>Cota individual</span><strong>{{ $quota['author_ceiling'] === null ? 'A configurar' : 'R$ '.number_format($quota['author_ceiling'], 2, ',', '.') }}</strong><small>{{ $quota['councilor_seats'] ? $quota['councilor_seats'].' cadeiras' : 'divisão pendente' }}</small></div>
            <div><span>Carteira indicada</span><strong>R$ {{ number_format($quota['used'], 2, ',', '.') }}</strong><small>{{ $quota['count'] }} de {{ $quota['count_limit'] ?? '∞' }} propostas</small></div>
            <div><span>Saldo disponível</span><strong>{{ $quota['remaining'] === null ? 'A configurar' : 'R$ '.number_format($quota['remaining'], 2, ',', '.') }}</strong><small>Antes de novos envios</small></div>
            <div class="health"><span>Reserva de saúde</span><strong>R$ {{ number_format($quota['health_allocated'], 2, ',', '.') }}</strong><small>{{ ($quota['health_gap'] ?? 0) > 0 ? 'Faltam R$ '.number_format($quota['health_gap'], 2, ',', '.') : 'Proporção atendida' }}</small></div>
        </section>
    @else
        <section class="metric-strip legislative-metrics">
            <article><span><i data-lucide="file-text" aria-hidden="true"></i></span><div><small>Propostas</small><strong>{{ $summary['total'] }}</strong></div></article>
            <article><span><i data-lucide="wallet-cards" aria-hidden="true"></i></span><div><small>Valor indicado</small><strong>R$ {{ number_format($summary['amount'], 2, ',', '.') }}</strong></div></article>
            <article><span><i data-lucide="clipboard-check" aria-hidden="true"></i></span><div><small>Aguardando ação</small><strong>{{ $summary['pending'] }}</strong></div></article>
            <article><span><i data-lucide="send" aria-hidden="true"></i></span><div><small>Protocoladas</small><strong>{{ $summary['sent'] }}</strong></div></article>
        </section>
    @endif

    @if ($executiveBoard)
        <section class="content-panel legislative-executive-board">
            <div class="content-panel-header">
                <div>
                    <p class="panel-kicker">Mesa do Executivo</p>
                    <h2 class="h5 mb-1">Decisão, recebimento e reserva em uma fila</h2>
                    <p class="small text-secondary mb-0">Veja em uma tela o que veio da Câmara, o que precisa entrar formalmente na Prefeitura e o que depende de reserva para virar execução.</p>
                </div>
                @if ($executiveDesk['focus_url'] ?? null)
                    <a class="btn btn-sm btn-primary" href="{{ $executiveDesk['focus_url'] }}">
                        <i data-lucide="arrow-right" aria-hidden="true"></i>Abrir prioridade
                    </a>
                @endif
            </div>
            <div class="legislative-executive-desk">
                <div class="executive-desk-focus {{ $executiveDesk['focus_class'] ?? '' }}">
                    <span><i data-lucide="{{ $executiveDesk['focus_icon'] ?? 'alert-circle' }}" aria-hidden="true"></i></span>
                    <div>
                        <small>{{ $executiveDesk['focus_kicker'] ?? 'Foco recomendado agora' }}</small>
                        <strong>{{ $executiveDesk['focus_title'] ?? 'Revisar fila' }}</strong>
                        <p>{{ $executiveDesk['focus_text'] ?? 'Abra a fila abaixo para tratar os itens pendentes.' }}</p>
                    </div>
                </div>
                <div class="executive-desk-metrics">
                    <div><span>Ações pendentes</span><strong>{{ $executiveDesk['total'] ?? 0 }}</strong></div>
                    <div><span>Valor sob decisão</span><strong>R$ {{ number_format($executiveDesk['amount'] ?? 0, 2, ',', '.') }}</strong></div>
                    <div><span>Em execução aberta</span><strong>{{ $executiveDesk['done'] ?? 0 }}</strong></div>
                    <div class="{{ ($executiveDesk['stale_count'] ?? 0) > 0 ? 'is-danger' : '' }}"><span>Fora do prazo</span><strong>{{ $executiveDesk['stale_count'] ?? 0 }}</strong></div>
                </div>
            </div>
            <div class="executive-command-panel" aria-label="Painel de comando do Executivo">
                <div class="executive-command-summary">
                    <div>
                        <p class="panel-kicker">Comando municipal</p>
                        <h3>O que o gestor precisa decidir agora</h3>
                        <p>Receba propostas da Câmara, confirme reserva e libere plano, execução e prestação de contas sem sair desta mesa.</p>
                    </div>
                    @if ($executiveDesk['focus_url'] ?? null)
                        <a class="btn btn-outline-primary" href="{{ $executiveDesk['focus_url'] }}"><i data-lucide="arrow-right" aria-hidden="true"></i>Executar foco</a>
                    @endif
                </div>
                <div class="executive-command-cards">
                    @foreach($executiveDesk['command_cards'] as $card)
                        <article class="is-{{ $card['tone'] ?? 'neutral' }}">
                            <span><i data-lucide="{{ $card['icon'] }}" aria-hidden="true"></i></span>
                            <div>
                                <small>{{ $card['label'] }}</small>
                                <strong>{{ $card['value'] }}</strong>
                                <p>{{ $card['description'] }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
                <ol class="executive-flow-lane">
                    @foreach($executiveDesk['flow_steps'] as $index => $step)
                        <li class="{{ ((int) ($step['count'] ?? 0)) > 0 ? 'has-items' : 'is-empty' }}">
                            <span>{{ $index + 1 }}</span>
                            <div>
                                <strong>{{ $step['label'] }}</strong>
                                <small>{{ $step['description'] }}</small>
                                <a href="{{ $step['url'] }}">
                                    <i data-lucide="arrow-right" aria-hidden="true"></i>{{ $step['action'] }}
                                    <em>{{ $step['count'] }}</em>
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ol>
                <div class="executive-next-decisions" aria-label="Decisões executivas priorizadas">
                    <header>
                        <span><i data-lucide="list-checks" aria-hidden="true"></i></span>
                        <div>
                            <strong>3 decisões mais importantes</strong>
                            <small>Atalhos para o gestor resolver o que destrava Câmara, reserva e execução.</small>
                        </div>
                    </header>
                    <div>
                        @forelse(($executiveDesk['next_decisions'] ?? collect()) as $decision)
                            <a class="{{ $decision['late'] ? 'is-late' : '' }}" href="{{ $decision['url'] }}">
                                <span>{{ $decision['label'] }}</span>
                                <strong>{{ $decision['proposal']->reference }} · {{ $decision['proposal']->object }}</strong>
                                <small>{{ $decision['proposal']->author_name }} · {{ $decision['age'] }} dia(s) em {{ $decision['column']['title'] }}</small>
                            </a>
                        @empty
                            <p>Nenhuma decisão executiva pendente agora.</p>
                        @endforelse
                    </div>
                </div>
            </div>
            @if (($executiveDesk['quick_actions'] ?? collect())->isNotEmpty())
                <div class="executive-action-queue" aria-label="Fila rápida do Executivo">
                    <header>
                        <div>
                            <strong>Fila rápida de atendimento</strong>
                            <small>Ordem sugerida pelo prazo e pela etapa atual da proposta.</small>
                        </div>
                        <span>{{ $executiveDesk['quick_actions']->count() }} prioridade(s)</span>
                    </header>
                    <div>
                        @foreach($executiveDesk['quick_actions'] as $action)
                            @if(in_array($role, [App\Models\User::ROLE_MANAGER, App\Models\User::ROLE_EDITOR], true) && $action['column']['key'] === 'receive')
                                <form class="executive-action-card is-{{ $action['column']['tone'] }} {{ $action['late'] ? 'is-late' : '' }}" method="POST" action="{{ route('legislative.receive', $action['proposal']) }}" data-prevent-double-submit>
                                    @csrf
                                    <input name="_submission_token" type="hidden" value="{{ $action['receive_token'] }}">
                                    <span><i data-lucide="{{ $action['late'] ? 'timer-reset' : $action['column']['icon'] }}" aria-hidden="true"></i></span>
                                    <div>
                                        <small>{{ $action['column']['action'] }}{{ $action['late'] ? ' · fora do prazo' : '' }}</small>
                                        <strong>{{ $action['proposal']->reference }} · {{ $action['proposal']->object }}</strong>
                                        <em>{{ $action['proposal']->author_name }} · processo e pendências serão sugeridos automaticamente</em>
                                    </div>
                                    <button type="submit">Confirmar</button>
                                </form>
                            @elseif(in_array($role, [App\Models\User::ROLE_MANAGER, App\Models\User::ROLE_EDITOR], true) && $action['column']['key'] === 'budget')
                                <form class="executive-action-card is-{{ $action['column']['tone'] }} {{ $action['late'] ? 'is-late' : '' }}" method="POST" action="{{ route('legislative.reserve', $action['proposal']) }}" data-prevent-double-submit>
                                    @csrf
                                    <input name="_submission_token" type="hidden" value="{{ $action['reserve_token'] }}">
                                    <span><i data-lucide="{{ $action['late'] ? 'timer-reset' : $action['column']['icon'] }}" aria-hidden="true"></i></span>
                                    <div>
                                        <small>{{ $action['column']['action'] }}{{ $action['late'] ? ' · fora do prazo' : '' }}</small>
                                        <strong>{{ $action['proposal']->reference }} · {{ $action['proposal']->object }}</strong>
                                        <em>{{ $action['proposal']->author_name }} · reserva integral e Plano de Trabalho serão acionados automaticamente</em>
                                    </div>
                                    <button type="submit">Reservar</button>
                                </form>
                            @else
                                <a class="executive-action-card is-{{ $action['column']['tone'] }} {{ $action['late'] ? 'is-late' : '' }}" href="{{ $action['url'] }}">
                                    <span><i data-lucide="{{ $action['late'] ? 'timer-reset' : $action['column']['icon'] }}" aria-hidden="true"></i></span>
                                    <div>
                                        <small>{{ $action['column']['action'] }}{{ $action['late'] ? ' · fora do prazo' : '' }}</small>
                                        <strong>{{ $action['proposal']->reference }} · {{ $action['proposal']->object }}</strong>
                                        <em>{{ $action['proposal']->author_name }} · R$ {{ number_format((float) $action['proposal']->estimated_amount, 2, ',', '.') }} · {{ $action['age'] }} dia(s)</em>
                                    </div>
                                    <b>Abrir</b>
                                </a>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
            @if (($executiveDesk['stale_count'] ?? 0) > 0)
                <div class="executive-stale-strip">
                    <strong><i data-lucide="timer-reset" aria-hidden="true"></i>Atenção imediata</strong>
                    @foreach ($executiveDesk['stale'] as $stale)
                        <a href="{{ $stale['url'] }}">
                            {{ $stale['proposal']->reference }} · {{ $stale['age'] }} dia(s) em {{ $stale['column']['title'] }}
                        </a>
                    @endforeach
                </div>
            @endif
            <div class="executive-triage-strip" aria-label="Triagem rápida do Legislativo">
                @foreach($executiveBoard as $column)
                    <a class="is-{{ $column['tone'] }}" href="{{ $column['items']->first()?->getAttribute('executive_board_url') ?? route('legislative.index', ['year' => $year, 'status' => $column['statuses'][0]]) }}">
                        <span><i data-lucide="{{ $column['icon'] }}" aria-hidden="true"></i></span>
                        <div>
                            <small>{{ $column['action'] }}</small>
                            <strong>{{ $column['count'] }} em {{ $column['title'] }}</strong>
                            <em>R$ {{ number_format($column['amount'], 2, ',', '.') }}{{ ($column['stale_count'] ?? 0) > 0 ? ' · '.$column['stale_count'].' atrasada(s)' : '' }}</em>
                        </div>
                    </a>
                @endforeach
            </div>
            <div class="legislative-board-grid">
                @foreach ($executiveBoard as $column)
                    <article class="legislative-board-column is-{{ $column['tone'] }}">
                        <header>
                            <span><i data-lucide="{{ $column['icon'] }}" aria-hidden="true"></i></span>
                            <div>
                                <strong>{{ $column['title'] }}</strong>
                                <small>{{ $column['count'] }} item(ns) · R$ {{ number_format($column['amount'], 2, ',', '.') }}</small>
                            </div>
                        </header>
                        <p>{{ $column['description'] }}</p>
                        <div class="legislative-board-toolbar">
                            <a href="{{ route('legislative.index', ['year' => $year, 'status' => $column['statuses'][0]]) }}"><i data-lucide="list-filter" aria-hidden="true"></i>Filtrar</a>
                            @if (($column['stale_count'] ?? 0) > 0)
                                <span>{{ $column['stale_count'] }} parada(s)</span>
                            @endif
                        </div>
                        <div class="legislative-board-items">
                            @forelse ($column['items'] as $item)
                                <a href="{{ $item->executive_board_url }}">
                                    <span>{{ $item->reference }}</span>
                                    <strong>{{ $item->object }}</strong>
                                    <small>{{ $item->author_name }} · R$ {{ number_format((float) $item->estimated_amount, 2, ',', '.') }}</small>
                                    <em>{{ $column['action'] }} <b>{{ $item->updated_at->diffForHumans() }}</b></em>
                                </a>
                            @empty
                                <div class="legislative-board-empty">{{ $column['empty'] }}</div>
                            @endforelse
                            @if (($column['hidden_count'] ?? 0) > 0)
                                <a class="legislative-board-more" href="{{ route('legislative.index', ['year' => $year, 'status' => $column['statuses'][0]]) }}">Ver mais {{ $column['hidden_count'] }} nesta etapa</a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <form class="legislative-filters" method="GET">
        <label><span>Exercício</span><select class="form-select" name="year">@foreach(collect([$year, ...$activeYears])->unique()->sortDesc() as $availableYear)<option value="{{ $availableYear }}" @selected($year === $availableYear)>{{ $availableYear }}{{ in_array($availableYear, $activeYears, true) ? ' · ativo' : ' · sem regra ativa' }}</option>@endforeach</select></label>
        <label><span>Situação</span><select class="form-select" name="status"><option value="">Todas</option>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>@endforeach</select></label>
        @if($executiveBoard)
            <label><span>Vereador</span><select class="form-select" name="author"><option value="">Todos</option>@foreach($filterOptions['authors'] as $option)<option value="{{ $option }}" @selected($selectedAuthor === $option)>{{ $option }}</option>@endforeach</select></label>
            <label><span>Secretaria</span><select class="form-select" name="department"><option value="">Todas</option>@foreach($filterOptions['departments'] as $option)<option value="{{ $option }}" @selected($selectedDepartment === $option)>{{ $option }}</option>@endforeach</select></label>
            <label><span>Saúde</span><select class="form-select" name="health"><option value="">Todas</option><option value="yes" @selected($selectedHealth === 'yes')>Somente saúde</option><option value="no" @selected($selectedHealth === 'no')>Não saúde</option></select></label>
        @endif
        <label class="search"><span>Busca</span><input class="form-control" name="search" value="{{ $search }}" placeholder="Referência, autor, objeto ou beneficiário"></label>
        <button class="btn btn-outline-primary" type="submit"><i data-lucide="list-filter" aria-hidden="true"></i>Filtrar</button>
        @if($executiveBoard && ($selectedStatus || $selectedAuthor || $selectedDepartment || $selectedHealth || $search))
            <a class="btn btn-outline-secondary" href="{{ route('legislative.index', ['year' => $year]) }}"><i data-lucide="x" aria-hidden="true"></i>Limpar</a>
        @endif
    </form>

    <section class="content-panel legislative-list" id="minhas-propostas">
        <div class="content-panel-header"><div><h2 class="h5 mb-1">Indicações do exercício</h2><p class="small text-secondary mb-0">{{ $proposals->total() }} registro(s)</p></div></div>
        @forelse($proposals as $proposal)
            <a class="legislative-row" href="{{ route('legislative.show', $proposal) }}">
                <span class="legislative-row-code">{{ $proposal->reference }}</span>
                <span class="legislative-row-main"><strong>{{ $proposal->object }}</strong><small>{{ $proposal->author_name }} · {{ $proposal->author_party }} · {{ $proposal->beneficiary_name }}</small></span>
                <span class="legislative-row-health {{ $proposal->health_related ? 'is-health' : '' }}"><i data-lucide="{{ $proposal->health_related ? 'heart-pulse' : 'circle-dollar-sign' }}" aria-hidden="true"></i>{{ $proposal->health_related ? 'Saúde' : ($proposal->expense_destination === 'investment' ? 'Investimento' : 'Custeio') }}</span>
                <span class="legislative-row-amount">R$ {{ number_format((float) $proposal->estimated_amount, 2, ',', '.') }}</span>
                <span class="legislative-status status-{{ $proposal->status }}">{{ $proposal->statusLabel() }}</span>
                <i data-lucide="chevron-right" aria-hidden="true"></i>
            </a>
        @empty
            <div class="empty-state py-5">Nenhuma proposta legislativa encontrada neste exercício.</div>
        @endforelse
        @if($proposals->hasPages())<div class="content-panel-body border-top">{{ $proposals->links() }}</div>@endif
    </section>
@endsection
