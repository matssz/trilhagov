@extends('layouts.app')

@section('title', 'Portal Legislativo | TrilhaGov')

@section('content')
    <div class="page-heading legislative-heading">
        <div>
            <span class="eyebrow">{{ $municipality->name }} · Câmara e Executivo</span>
            <h1>Portal Legislativo</h1>
            <p>{{ $role === App\Models\User::ROLE_COUNCILOR ? ($membership->legislative_name.' · '.$membership->legislative_party) : 'Indicações da Câmara e acompanhamento da execução municipal' }}</p>
        </div>
        @if ($role === App\Models\User::ROLE_COUNCILOR)
            <a class="btn btn-primary" href="{{ route('legislative.create', ['year' => $year]) }}"><i data-lucide="plus" aria-hidden="true"></i>Cadastrar emenda</a>
        @endif
    </div>

    @if (! $profile)
        <div class="legislative-notice is-danger"><i data-lucide="circle-alert" aria-hidden="true"></i><div><strong>Configuração de {{ $year }} não ativada</strong><p>As cotas e a reserva de saúde não podem ser calculadas sem a versão normativa vigente.</p></div></div>
    @elseif ($quota && $quota['legacy_ceiling'])
        <div class="legislative-notice is-warning"><i data-lucide="triangle-alert" aria-hidden="true"></i><div><strong>Cota individual provisória</strong><p>Informe o número de vereadores na configuração municipal para dividir corretamente o teto global da Câmara.</p></div></div>
    @endif

    @if ($quota)
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

    <form class="legislative-filters" method="GET">
        <label><span>Exercício</span><input class="form-control" name="year" type="number" min="2000" max="{{ now()->year + 2 }}" value="{{ $year }}"></label>
        <label><span>Situação</span><select class="form-select" name="status"><option value="">Todas</option>@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="search"><span>Busca</span><input class="form-control" name="search" value="{{ $search }}" placeholder="Referência, autor, objeto ou beneficiário"></label>
        <button class="btn btn-outline-primary" type="submit"><i data-lucide="list-filter" aria-hidden="true"></i>Filtrar</button>
    </form>

    <section class="content-panel legislative-list">
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
            <div class="empty-state py-5">Nenhuma indicação de emenda encontrada neste exercício.</div>
        @endforelse
        @if($proposals->hasPages())<div class="content-panel-body border-top">{{ $proposals->links() }}</div>@endif
    </section>
@endsection
