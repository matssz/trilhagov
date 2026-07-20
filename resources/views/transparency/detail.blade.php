@extends('layouts.app')

@section('title', $amendment->reference.' | Transparência municipal')

@section('content')
    @php
        $plan = $amendment->municipalWorkPlan;
        $lastUpdated = $amendment->transparencyEvents->max('occurred_at') ?? $amendment->updated_at;
    @endphp

    <nav class="public-detail-nav" aria-label="Navegação da transparência">
        <a href="{{ route('transparency.show', $municipality->transparency_slug) }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Voltar às emendas</a>
        <span>Atualizado em {{ $lastUpdated?->format('d/m/Y \à\s H:i') }}</span>
    </nav>

    <header class="public-amendment-hero">
        <div>
            <p class="page-kicker">Emenda {{ $amendment->fiscal_year }}</p>
            <h1>{{ $amendment->reference }}</h1>
            <p>{{ $amendment->object }}</p>
        </div>
        <x-amendment-status-badge :status="$amendment->status" :label="$amendment->statusLabel()" />
    </header>

    <section class="public-value-strip" aria-label="Execução financeira">
        <article><span>Valor autorizado</span><strong>R$ {{ number_format($authorizedAmount, 2, ',', '.') }}</strong></article>
        <article><span>Valor liberado</span><strong>R$ {{ number_format($releasedAmount, 2, ',', '.') }}</strong></article>
        <article><span>Valor executado</span><strong>R$ {{ number_format($executedAmount, 2, ',', '.') }}</strong></article>
    </section>

    <div class="public-detail-grid">
        <main>
            <section class="content-panel public-detail-section">
                <div class="content-panel-header"><p class="panel-kicker">Identificação</p><h2 class="h5 mb-0">Origem e finalidade</h2></div>
                <dl class="public-data-list">
                    <div><dt>Autor</dt><dd>{{ $amendment->author_name }}{{ $amendment->author_party ? ' · '.$amendment->author_party : '' }}</dd></div>
                    <div><dt>Órgão executor ou beneficiário</dt><dd>{{ $plan?->beneficiary_name ?: $amendment->responsible_department }}</dd></div>
                    <div><dt>Destinação</dt><dd>{{ $amendment->expenseDestinationLabel() }}</dd></div>
                    <div><dt>Localidade beneficiada</dt><dd>{{ $amendment->beneficiary_location ?: $municipality->name }}</dd></div>
                    <div><dt>Instrumento jurídico</dt><dd>{{ $amendment->legal_instrument ?: 'Não se aplica ou não informado' }}</dd></div>
                    <div><dt>Processo administrativo</dt><dd>{{ $amendment->administrative_process ?: 'Não informado' }}</dd></div>
                    <div><dt>Prazo para aplicação</dt><dd>{{ $amendment->application_deadline?->format('d/m/Y') ?? 'Não informado' }}</dd></div>
                </dl>
            </section>

            <section class="content-panel public-detail-section">
                <div class="content-panel-header"><p class="panel-kicker">Execução</p><h2 class="h5 mb-0">Cronograma físico-financeiro</h2></div>
                <div class="public-schedule">
                    @forelse ($plan?->stages ?? collect() as $stage)
                        <article>
                            <div><strong>{{ $stage->title }}</strong><span>{{ $stage->physical_delivery }}</span></div>
                            <dl><div><dt>Período</dt><dd>{{ $stage->planned_start_at->format('d/m/Y') }} a {{ $stage->planned_end_at->format('d/m/Y') }}</dd></div><div><dt>Valor</dt><dd>R$ {{ number_format((float) $stage->planned_amount, 2, ',', '.') }}</dd></div></dl>
                        </article>
                    @empty
                        <div class="empty-state">Cronograma ainda não publicado pelo município.</div>
                    @endforelse
                </div>
            </section>

            <section class="content-panel public-detail-section">
                <div class="content-panel-header"><p class="panel-kicker">Tempo real</p><h2 class="h5 mb-0">Histórico de alterações</h2></div>
                <div class="public-timeline">
                    @forelse ($amendment->transparencyEvents as $event)
                        <article>
                            <time datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $event->occurred_at->format('d/m/Y H:i') }}</time>
                            <div><strong>{{ $event->title }}</strong><p>{{ $event->description }}</p>
                                @if ($event->changes)<dl>@foreach ($event->changes as $label => $change)<div><dt>{{ $label }}</dt><dd>@if(is_array($change)){{ $change['anterior'] ?? 'Não informado' }} → {{ $change['atual'] ?? 'Não informado' }}@else{{ $change }}@endif</dd></div>@endforeach</dl>@endif
                            </div>
                        </article>
                    @empty
                        <div class="empty-state">Nenhuma alteração pública registrada.</div>
                    @endforelse
                </div>
            </section>
        </main>

        <aside>
            <section class="content-panel public-detail-section">
                <div class="content-panel-header"><p class="panel-kicker">Rastreabilidade</p><h2 class="h5 mb-0">Identificação financeira</h2></div>
                <dl class="public-data-list public-data-list-single">
                    <div><dt>Forma de controle</dt><dd>{{ $amendment->bankTrackingTypeLabel() }}</dd></div>
                    @if ($amendment->bank_tracking_type === 'specific_account')
                        <div><dt>Conta bancária</dt><dd>{{ $amendment->bank_account_number ?: 'Não informada' }}</dd></div>
                    @else
                        <div><dt>Fonte de Recursos</dt><dd>{{ $amendment->funding_source_code ?: 'Não informada' }}</dd></div>
                        <div><dt>Código de Aplicação Fixo</dt><dd>{{ $amendment->application_code_fixed ?: 'Não informado' }}</dd></div>
                        <div><dt>Código de Aplicação Variável</dt><dd>{{ $amendment->application_code_variable ?: 'Não informado' }}</dd></div>
                    @endif
                </dl>
            </section>
            <div class="public-source-note"><i data-lucide="landmark" aria-hidden="true"></i><p>Dados publicados pelo Município de {{ $municipality->name }} conforme a Resolução TCESP nº 17/2025.</p></div>
        </aside>
    </div>
@endsection
