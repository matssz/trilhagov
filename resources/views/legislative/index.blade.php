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
                    <h2>{{ $quota['remaining'] === null ? 'Cota em configuracao' : 'R$ '.number_format($quota['remaining'], 2, ',', '.').' disponiveis' }}</h2>
                    <strong class="councilor-home-next">{{ $councilorGuide['nextTitle'] ?? 'Pronto para indicar' }}</strong>
                    <p>{{ $councilorGuide['nextText'] ?? 'O sistema calcula automaticamente saldo, limite e reserva de saude.' }}</p>
                    <span class="councilor-home-badge is-{{ $councilorGuide['badgeTone'] ?? 'success' }}">{{ $councilorGuide['badge'] ?? 'Pode indicar' }}</span>
                </div>
                <div class="councilor-home-actions">
                    @if ($councilorGuide['canCreate'] ?? false)
                        <a class="btn btn-primary" href="{{ route('legislative.create', ['year' => $year]) }}"><i data-lucide="plus" aria-hidden="true"></i>Nova proposta</a>
                    @else
                        <button class="btn btn-outline-secondary" type="button" disabled><i data-lucide="lock-keyhole" aria-hidden="true"></i>Aguardando liberacao</button>
                    @endif
                    <a class="btn btn-outline-primary" href="#minhas-propostas"><i data-lucide="list-checks" aria-hidden="true"></i>Ver propostas</a>
                </div>
                <div class="councilor-home-progress">
                    <div>
                        <span>Uso da cota</span>
                        <strong>{{ $councilorGuide['quotaProgress'] === null ? 'A configurar' : $councilorGuide['quotaProgress'].'%' }}</strong>
                        <div class="councilor-progress-track"><i style="width: {{ $councilorGuide['quotaProgress'] ?? 0 }}%"></i></div>
                    </div>
                    <div>
                        <span>Reserva minima de saude</span>
                        <strong>{{ $councilorGuide['healthProgress'] === null ? 'A configurar' : $councilorGuide['healthProgress'].'%' }}</strong>
                        <div class="councilor-progress-track is-health"><i style="width: {{ $councilorGuide['healthProgress'] ?? 0 }}%"></i></div>
                    </div>
                    <div>
                        <span>Em elaboracao</span>
                        <strong>{{ $councilorGuide['statusCounts'][App\Models\LegislativeProposal::STATUS_DRAFT] ?? 0 }}</strong>
                    </div>
                    <div>
                        <span>No Executivo</span>
                        <strong>{{ ($councilorGuide['statusCounts'][App\Models\LegislativeProposal::STATUS_SENT] ?? 0) + ($councilorGuide['statusCounts'][App\Models\LegislativeProposal::STATUS_RECEIVED] ?? 0) + ($councilorGuide['statusCounts'][App\Models\LegislativeProposal::STATUS_RESERVED] ?? 0) }}</strong>
                    </div>
                </div>
                <div class="councilor-home-steps">
                    <span><i data-lucide="edit-3" aria-hidden="true"></i>Vereador indica</span>
                    <span><i data-lucide="badge-check" aria-hidden="true"></i>Camara confere</span>
                    <span><i data-lucide="send" aria-hidden="true"></i>Executivo recebe</span>
                    <span><i data-lucide="building-2" aria-hidden="true"></i>Prefeitura executa</span>
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
                    <div><span>Acoes pendentes</span><strong>{{ $executiveDesk['total'] ?? 0 }}</strong></div>
                    <div><span>Valor sob decisao</span><strong>R$ {{ number_format($executiveDesk['amount'] ?? 0, 2, ',', '.') }}</strong></div>
                    <div><span>Em execucao aberta</span><strong>{{ $executiveDesk['done'] ?? 0 }}</strong></div>
                    <div class="{{ ($executiveDesk['stale_count'] ?? 0) > 0 ? 'is-danger' : '' }}"><span>Fora do prazo</span><strong>{{ $executiveDesk['stale_count'] ?? 0 }}</strong></div>
                </div>
            </div>
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
        <label class="search"><span>Busca</span><input class="form-control" name="search" value="{{ $search }}" placeholder="Referência, autor, objeto ou beneficiário"></label>
        <button class="btn btn-outline-primary" type="submit"><i data-lucide="list-filter" aria-hidden="true"></i>Filtrar</button>
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
