@extends('layouts.app')

@section('title', 'Nova proposta legislativa | TrilhaGov')

@section('content')
    <div class="page-heading legislative-heading">
        <div>
            <span class="eyebrow">Câmara Municipal · exercício {{ $year }}</span>
            <h1>Nova proposta legislativa</h1>
            <p>{{ $membership->legislative_name ?: auth()->user()->name }} · {{ $membership->legislative_party ?: 'identificação partidária pendente' }}</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('legislative.index', ['year' => $year]) }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Voltar</a>
    </div>

    <x-validation-summary />

    @if (! $profile)
        <div class="legislative-notice is-danger"><i data-lucide="circle-alert" aria-hidden="true"></i><div><strong>Exercício sem regra vigente</strong><p>A configuração municipal precisa ser ativada antes do cadastro.</p></div></div>
    @elseif ($quota['legacy_ceiling'])
        <div class="legislative-notice is-warning"><i data-lucide="triangle-alert" aria-hidden="true"></i><div><strong>Número de vereadores não informado</strong><p>A divisão igualitária da cota depende desse parâmetro na configuração municipal.</p></div></div>
    @endif

    @if ($quota)
        <section class="legislative-automation-panel"
            data-legislative-automation
            data-remaining="{{ $automation['maximum_amount'] }}"
            data-minimum="{{ $automation['minimum_amount'] }}"
            data-health-gap="{{ $automation['health_gap'] }}"
            data-health-percentage="{{ $automation['health_percentage'] }}">
            <i data-lucide="wallet-cards" aria-hidden="true"></i>
            <div>
                <strong>Saldo disponível antes desta proposta: {{ $quota['remaining'] === null ? 'a configurar' : 'R$ '.number_format($quota['remaining'], 2, ',', '.') }}</strong>
                <p>O limite individual e a reserva de saúde são calculados automaticamente a partir da norma municipal ativa. Marque saúde quando a indicação for destinada a ações e serviços públicos de saúde.</p>
            </div>
            <span>{{ ($quota['health_gap'] ?? 0) > 0 ? 'Faltam R$ '.number_format($quota['health_gap'], 2, ',', '.') : 'Saúde em dia' }}</span>
        </section>
        <section class="legislative-projection-panel" data-legislative-projection>
            <div>
                <span>Depois desta proposta</span>
                <strong data-projection-remaining>{{ $automation['maximum_amount'] === null ? 'Saldo a configurar' : 'Informe o valor' }}</strong>
                <small data-projection-message>
                    @if ($automation['recommended_health'])
                        Priorize saúde: faltam R$ {{ number_format((float) $automation['health_gap'], 2, ',', '.') }} para atender a reserva mínima.
                    @else
                        O sistema vai conferir o saldo automaticamente antes de salvar.
                    @endif
                </small>
            </div>
            <button class="btn btn-outline-primary" type="button" data-fill-available @disabled(($automation['maximum_amount'] ?? 0) <= 0)>
                <i data-lucide="wand-sparkles" aria-hidden="true"></i>Usar saldo disponível
            </button>
            @if ($automation['recommended_health'] && $automation['suggested_health_amount'])
                <button class="btn btn-outline-primary" type="button" data-fill-health data-health-amount="{{ $automation['suggested_health_amount'] }}">
                    <i data-lucide="heart-pulse" aria-hidden="true"></i>Priorizar saúde
                </button>
            @endif
        </section>
        <section class="legislative-readiness-panel" data-legislative-readiness>
            <div>
                <span><i data-lucide="sparkles" aria-hidden="true"></i></span>
                <div>
                    <strong>Assistente automatico de preenchimento</strong>
                    <p data-readiness-message>Preencha objeto, valor e beneficiario. O TrilhaGov sugere saude, secretaria, localidade e fonte da estimativa quando identificar o padrao.</p>
                </div>
            </div>
            <ul>
                <li data-readiness-item="object">Objeto claro</li>
                <li data-readiness-item="amount">Valor dentro da cota</li>
                <li data-readiness-item="beneficiary">Destino informado</li>
                <li data-readiness-item="justification">Justificativa suficiente</li>
            </ul>
        </section>
        <section class="legislative-auto-check-panel">
            <header>
                <span><i data-lucide="shield-check" aria-hidden="true"></i></span>
                <div>
                    <strong>Conferência automática antes da Câmara</strong>
                    <p>Você informa o pedido. O TrilhaGov calcula e sinaliza os pontos técnicos que serão avaliados.</p>
                </div>
            </header>
            <div>
                <article>
                    <small>Orçamento</small>
                    <strong>Cota e saldo</strong>
                    <span>Bloqueia valor acima do disponível e mostra quanto sobra.</span>
                </article>
                <article>
                    <small>Saúde</small>
                    <strong>Reserva mínima</strong>
                    <span>Sugere saúde quando identifica UBS, vacina, hospital ou secretaria de saúde.</span>
                </article>
                <article>
                    <small>Execução</small>
                    <strong>Destino municipal</strong>
                    <span>Preenche localidade, fonte da estimativa e executor quando possível.</span>
                </article>
            </div>
        </section>
    @endif

    <form method="POST" action="{{ route('legislative.store') }}" data-prevent-double-submit>
        @csrf
        <input name="_submission_token" type="hidden" value="{{ $submissionToken }}">
        @include('legislative._form')
        <div class="legislative-form-actions">
            <a class="btn btn-outline-secondary" href="{{ route('legislative.index', ['year' => $year]) }}">Cancelar</a>
            <button class="btn btn-primary" type="submit" @disabled(! $profile || ! ($automation['can_create'] ?? false))><i data-lucide="save" aria-hidden="true"></i>Salvar proposta</button>
        </div>
    </form>
@endsection
