@extends('layouts.app')

@section('title', $amendment->reference . ' | TrilhaGov')

@section('content')
    <a class="d-inline-block mb-3" href="{{ route('emendas.index') }}">Voltar para emendas</a>

    @if ($amendment->hasOverdueDeadline())
        <div class="alert alert-danger" role="alert">
            Há prazo vencido nesta emenda. Confira o marco e atualize a situação.
        </div>
    @elseif ($amendment->hasUpcomingDeadline())
        <div class="alert alert-warning" role="alert">
            Há prazo previsto para os próximos 30 dias.
        </div>
    @endif

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <p class="page-kicker mb-0">Emenda {{ $amendment->fiscal_year }}</p>
                <x-amendment-status-badge :status="$amendment->status" :label="$amendment->statusLabel()" />
            </div>
            <h1 class="h3 mb-1">{{ $amendment->reference }}</h1>
            <p class="text-secondary mb-0">{{ $amendment->municipality->name }} / {{ $amendment->municipality->state }}</p>
        </div>
        <a class="btn btn-primary" href="{{ route('emendas.edit', $amendment) }}">Editar emenda</a>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <section class="content-panel mb-4">
                <div class="content-panel-header"><h2 class="h5 mb-0">Dados da emenda</h2></div>
                <div class="content-panel-body">
                    <dl class="data-list">
                        <dt>Esfera</dt><dd>{{ $amendment->governmentSphereLabel() }}</dd>
                        <dt>Tipo de autoria</dt><dd>{{ $amendment->authorshipTypeLabel() }}</dd>
                        <dt>Modalidade</dt><dd>{{ $amendment->transferTypeLabel() }}</dd>
                        <dt>Autor</dt><dd>{{ $amendment->author_name }}{{ $amendment->author_party ? ' / '.$amendment->author_party : '' }}</dd>
                        <dt>Código Transferegov</dt><dd>{{ $amendment->transferegov_code ?: 'Não informado' }}</dd>
                        <dt>Órgão responsável</dt><dd>{{ $amendment->responsible_department }}</dd>
                        <dt>Data da indicação</dt><dd>{{ $amendment->indicated_at?->format('d/m/Y') ?: 'Não informada' }}</dd>
                        <dt>Data do recebimento</dt><dd>{{ $amendment->received_at?->format('d/m/Y') ?: 'Não informada' }}</dd>
                        <dt>Valor previsto</dt><dd>R$ {{ number_format($amendment->expected_amount, 2, ',', '.') }}</dd>
                        <dt>Valor recebido</dt><dd>{{ $amendment->received_amount !== null ? 'R$ '.number_format($amendment->received_amount, 2, ',', '.') : 'Não informado' }}</dd>
                    </dl>
                </div>
            </section>

            <section class="content-panel">
                <div class="content-panel-header"><h2 class="h5 mb-0">Objeto</h2></div>
                <div class="content-panel-body">
                    <p class="mb-0" style="white-space: pre-line">{{ $amendment->object }}</p>
                    @if ($amendment->notes)
                        <hr>
                        <h3 class="h6">Observações internas</h3>
                        <p class="mb-0 text-secondary" style="white-space: pre-line">{{ $amendment->notes }}</p>
                    @endif
                </div>
            </section>
        </div>

        <div class="col-lg-4">
            <section class="content-panel">
                <div class="content-panel-header"><h2 class="h5 mb-0">Prazos de controle</h2></div>
                <div class="content-panel-body">
                    @foreach ([
                        ['label' => 'Comunicação e publicidade', 'date' => $amendment->communication_deadline, 'completed_at' => $amendment->communication_completed_at],
                        ['label' => 'Execução', 'date' => $amendment->execution_deadline, 'completed_at' => $amendment->execution_completed_at],
                        ['label' => 'Prestação de contas', 'date' => $amendment->accountability_deadline, 'completed_at' => $amendment->accountability_completed_at],
                    ] as $deadline)
                        <div class="deadline-row">
                            <span>{{ $deadline['label'] }}</span>
                            @if ($deadline['completed_at'])
                                <span class="text-success text-end">Concluído<br><small>{{ $deadline['completed_at']->format('d/m/Y') }}</small></span>
                            @elseif ($deadline['date'])
                                <span class="deadline-date {{ $deadline['date']->isBefore(today()) && $amendment->status !== 'completed' ? 'deadline-overdue' : ($deadline['date']->betweenIncluded(today(), today()->addDays(30)) ? 'deadline-upcoming' : '') }}">
                                    {{ $deadline['date']->format('d/m/Y') }}
                                </span>
                            @else
                                <span class="text-secondary">Não informado</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
@endsection
