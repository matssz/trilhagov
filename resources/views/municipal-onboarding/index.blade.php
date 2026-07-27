@extends('layouts.app')

@section('title', 'Implantação municipal | TrilhaGov')

@section('content')
    @php
        $activeProfile = $summary['activeProfile'];
        $draftProfile = $summary['draftProfile'];
        $health = $summary['health'];
    @endphp

    <header class="onboarding-heading">
        <div>
            <p class="page-kicker mb-2">{{ $municipality->name }} / {{ $municipality->state }}</p>
            <h1>Implantação municipal</h1>
            <p>Guia de liberação do Executivo, Câmara, normas do exercício e primeira operação.</p>
        </div>
        <div class="onboarding-score" style="--score: {{ $summary['score'] }}">
            <span>{{ $summary['score'] }}%</span>
            <small>pronto para operar</small>
        </div>
    </header>

    <section class="onboarding-status {{ $health['ready'] ? 'is-ready' : 'needs-action' }}">
        <span><i data-lucide="{{ $health['ready'] ? 'circle-check' : 'shield-alert' }}" aria-hidden="true"></i></span>
        <div>
            <p class="panel-kicker">Saúde da implantação</p>
            <h2>{{ $health['ready'] ? 'Município liberado para Câmara e Executivo' : 'Ainda há passos para liberar o uso real' }}</h2>
            <p>
                @if ($activeProfile)
                    Exercício {{ $activeProfile->fiscal_year }} vigente. A reserva de saúde, cota individual e instrumentos mínimos já podem ser usados pelo Portal Legislativo.
                @elseif ($draftProfile)
                    Existe uma norma em preparação para {{ $draftProfile->fiscal_year }}. Finalize ou use o assistente abaixo para ativar o exercício.
                @else
                    Nenhum exercício ativo foi encontrado. Use o assistente para configurar a Lei Orgânica e liberar os vereadores.
                @endif
            </p>
        </div>
        <a class="btn btn-outline-primary" href="{{ route('municipal-rules.index') }}"><i data-lucide="landmark" aria-hidden="true"></i>Ver normas</a>
    </section>

    <div class="onboarding-grid">
        <section class="content-panel">
            <div class="content-panel-header">
                <p class="panel-kicker">Checklist guiado</p>
                <h2 class="h5 mb-0">Ordem recomendada</h2>
            </div>
            <div class="onboarding-steps">
                @foreach ($summary['steps'] as $index => $step)
                    <article class="onboarding-step {{ $step['complete'] ? 'complete' : '' }}">
                        <span class="onboarding-step-index">{{ $index + 1 }}</span>
                        <span class="onboarding-step-icon"><i data-lucide="{{ $step['icon'] }}" aria-hidden="true"></i></span>
                        <div>
                            <h3>{{ $step['title'] }}</h3>
                            <p>{{ $step['description'] }}</p>
                        </div>
                        <a href="{{ $step['route'] }}">{{ $step['action'] }}</a>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="content-panel">
            <div class="content-panel-header">
                <p class="panel-kicker">Assistente</p>
                <h2 class="h5 mb-0">Ativar exercício pela Lei Orgânica</h2>
            </div>
            <form class="onboarding-activation-form" method="POST" action="{{ route('municipal-onboarding.activate') }}">
                @csrf
                <input type="hidden" name="_submission_token" value="{{ $activationToken }}">
                <label>
                    <span>Exercício <b>*</b></span>
                    <input class="form-control @error('fiscal_year') is-invalid @enderror" name="fiscal_year" type="number" min="2020" max="2100" value="{{ old('fiscal_year', $summary['year']) }}" required>
                    @error('fiscal_year')<small class="invalid-feedback">{{ $message }}</small>@enderror
                </label>
                <label>
                    <span>RCL do exercício anterior <b>*</b></span>
                    <input class="form-control @error('previous_year_rcl') is-invalid @enderror" name="previous_year_rcl" type="number" min="1" step="0.01" value="{{ old('previous_year_rcl') }}" placeholder="Ex.: 200000000" required>
                    @error('previous_year_rcl')<small class="invalid-feedback">{{ $message }}</small>@enderror
                </label>
                <label>
                    <span>Cadeiras da Câmara <b>*</b></span>
                    <input class="form-control @error('councilor_seats') is-invalid @enderror" name="councilor_seats" type="number" min="1" max="1000" value="{{ old('councilor_seats') }}" placeholder="Ex.: 13" required>
                    @error('councilor_seats')<small class="invalid-feedback">{{ $message }}</small>@enderror
                </label>
                <label>
                    <span>Responsável pela revisão jurídica <b>*</b></span>
                    <input class="form-control @error('legal_review_responsible') is-invalid @enderror" name="legal_review_responsible" value="{{ old('legal_review_responsible') }}" placeholder="Ex.: Procuradoria Municipal" required>
                    @error('legal_review_responsible')<small class="invalid-feedback">{{ $message }}</small>@enderror
                </label>
                <label>
                    <span>Referência da revisão <b>*</b></span>
                    <input class="form-control @error('legal_review_reference') is-invalid @enderror" name="legal_review_reference" value="{{ old('legal_review_reference') }}" placeholder="Ex.: Parecer 001/2027" required>
                    @error('legal_review_reference')<small class="invalid-feedback">{{ $message }}</small>@enderror
                </label>
                <label>
                    <span>Data da revisão <b>*</b></span>
                    <input class="form-control @error('legal_reviewed_at') is-invalid @enderror" name="legal_reviewed_at" type="date" value="{{ old('legal_reviewed_at', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                    @error('legal_reviewed_at')<small class="invalid-feedback">{{ $message }}</small>@enderror
                </label>
                <div class="onboarding-form-note">
                    <i data-lucide="calculator" aria-hidden="true"></i>
                    <span>O TrilhaGov aplica 1,55% da RCL, divide pelas cadeiras da Câmara e reserva 50% da cota para saúde.</span>
                </div>
                @error('activation')<div class="alert alert-danger mb-0">{{ $message }}</div>@enderror
                <button class="btn btn-primary w-100" type="submit" data-icon-submit>
                    <i data-lucide="rocket" aria-hidden="true"></i>
                    Preparar e ativar exercício
                </button>
            </form>
        </section>
    </div>

    <section class="content-panel onboarding-decision-panel">
        <div class="content-panel-header">
            <p class="panel-kicker">Próxima decisão</p>
            <h2 class="h5 mb-0">O que o gestor precisa liberar</h2>
        </div>
        <div class="onboarding-decision-grid">
            <div>
                <span>Portal Legislativo</span>
                <strong>{{ $activeProfile ? 'Liberado pelo exercício '.$activeProfile->fiscal_year : 'Bloqueado sem exercício ativo' }}</strong>
                <small>Vereadores só conseguem enviar propostas quando existe norma vigente.</small>
            </div>
            <div>
                <span>Cota individual</span>
                <strong>
                    @if ($activeProfile && $activeProfile->previous_year_rcl && $activeProfile->individual_limit_percentage && $activeProfile->councilor_seats)
                        R$ {{ number_format(((float) $activeProfile->previous_year_rcl * (float) $activeProfile->individual_limit_percentage / 100) / max(1, (int) $activeProfile->councilor_seats), 2, ',', '.') }}
                    @else
                        A configurar
                    @endif
                </strong>
                <small>Calculada automaticamente a partir da RCL, percentual e cadeiras.</small>
            </div>
            <div>
                <span>Reserva de saúde</span>
                <strong>{{ $activeProfile?->health_reserve_percentage ? number_format((float) $activeProfile->health_reserve_percentage, 2, ',', '.').'% por vereador' : 'A configurar' }}</strong>
                <small>Usada para travar protocolo quando a carteira do vereador não atende a regra.</small>
            </div>
            <div>
                <span>Leitura normativa</span>
                <strong>{{ $health['rules_score'] }}%</strong>
                <small>{{ count($health['blockers']) }} pendência(s) obrigatória(s), {{ count($health['warnings']) }} alerta(s).</small>
            </div>
        </div>
    </section>
@endsection
