@extends('layouts.app')

@section('title', $proposal->reference.' | Portal Legislativo')

@section('content')
    @php
        $amendment = $proposal->amendment;
        $commitments = $amendment?->financialCommitments ?? collect();
        $liquidations = $commitments->flatMap->liquidations;
        $payments = $commitments->flatMap->payments;
        $committed = (float) $commitments->where('status', 'active')->sum('committed_amount');
        $liquidated = (float) $liquidations->sum('amount');
        $paid = (float) $payments->sum('amount');
        $physicalProgress = $amendment?->physicalExecutionPercentage() ?? 0;
        $executionStarted = $physicalProgress > 0
            || $commitments->isNotEmpty()
            || in_array($amendment?->status, ['executing', 'accountability_pending', 'completed'], true);
        $stepStatuses = [
            'proposal' => true,
            'protocol' => in_array($proposal->status, ['sent', 'received', 'reserved'], true),
            'analysis' => $proposal->status === 'reserved',
            'planning' => $amendment?->municipalWorkPlan !== null,
            'execution' => $executionStarted,
            'payment' => $paid > 0,
        ];
        $executionDate = $commitments->min('committed_at')
            ?? $amendment?->executionStages?->min('planned_start_at');
        $paymentDate = $payments->min('paid_at');
        $isCouncilor = $role === App\Models\User::ROLE_COUNCILOR;
        $isLegislativeReviewer = $role === App\Models\User::ROLE_LEGISLATIVE_REVIEWER;
        $healthGap = (float) ($quota['health_gap'] ?? 0);
        $nextAction = match (true) {
            $canEdit => ['icon' => 'send', 'title' => 'Enviar para conferência legislativa', 'description' => 'Revise os dados e encaminhe a proposta para a conferência mínima da Câmara.', 'href' => '#enviar-conferencia', 'label' => 'Ir para envio'],
            $canReview && $proposal->status === App\Models\LegislativeProposal::STATUS_SUBMITTED => ['icon' => 'badge-check', 'title' => 'Conferir requisitos da Câmara', 'description' => 'Valide compatibilidade, teto, saúde, objeto e beneficiário antes do protocolo.', 'href' => '#conferencia-legislativa', 'label' => 'Analisar proposta'],
            $canReview && $proposal->status === App\Models\LegislativeProposal::STATUS_APPROVED => ['icon' => 'send', 'title' => 'Protocolar no Executivo', 'description' => $healthGap > 0 ? 'Ainda existe déficit de saúde na carteira do vereador; resolva antes do protocolo.' : 'Informe o protocolo da Câmara para encaminhar formalmente ao Executivo.', 'href' => '#protocolo-executivo', 'label' => 'Ir para protocolo'],
            $canReceive && $proposal->status === App\Models\LegislativeProposal::STATUS_SENT => ['icon' => 'download', 'title' => 'Receber no Executivo', 'description' => 'Informe o processo administrativo e abra a emenda no núcleo executivo.', 'href' => '#recebimento-executivo', 'label' => 'Receber proposta'],
            $canReceive && $proposal->status === App\Models\LegislativeProposal::STATUS_RECEIVED => ['icon' => 'wallet-cards', 'title' => 'Registrar reserva orçamentária', 'description' => 'Confirme a reanálise orçamentária e avance para o Plano de Trabalho.', 'href' => '#reserva-orcamentaria', 'label' => 'Registrar reserva'],
            $proposal->status === App\Models\LegislativeProposal::STATUS_RESERVED && $proposal->amendment && ! in_array($role, [App\Models\User::ROLE_COUNCILOR, App\Models\User::ROLE_LEGISLATIVE_REVIEWER], true) => ['icon' => 'gauge', 'title' => 'Acompanhar no fluxo executivo', 'description' => 'A proposta já virou emenda executiva. Continue pelo plano, execução e prestação de contas.', 'href' => route('emendas.show', $proposal->amendment), 'label' => 'Abrir fluxo executivo'],
            $isCouncilor && in_array($proposal->status, [App\Models\LegislativeProposal::STATUS_SUBMITTED, App\Models\LegislativeProposal::STATUS_APPROVED], true) => ['icon' => 'eye', 'title' => 'Aguardando tramitação da Câmara', 'description' => 'Sua proposta já foi enviada. Acompanhe o status e o histórico nesta página.', 'href' => '#historico-proposta', 'label' => 'Ver histórico'],
            $isCouncilor && in_array($proposal->status, [App\Models\LegislativeProposal::STATUS_SENT, App\Models\LegislativeProposal::STATUS_RECEIVED, App\Models\LegislativeProposal::STATUS_RESERVED], true) => ['icon' => 'building-2', 'title' => 'Acompanhando o Executivo', 'description' => 'A proposta entrou no fluxo executivo. Veja processo, reserva e execução abaixo.', 'href' => '#acompanhamento-executivo', 'label' => 'Ver Executivo'],
            $proposal->status === App\Models\LegislativeProposal::STATUS_RETURNED => ['icon' => 'undo-2', 'title' => 'Proposta devolvida para ajuste', 'description' => $isCouncilor ? 'Ajuste os pontos indicados e envie novamente.' : 'Aguardando ajuste pelo vereador.', 'href' => $isCouncilor ? '#editor-proposta' : '#historico-proposta', 'label' => $isCouncilor ? 'Editar proposta' : 'Ver histórico'],
            $proposal->status === App\Models\LegislativeProposal::STATUS_REJECTED => ['icon' => 'circle-x', 'title' => 'Proposta rejeitada na análise prévia', 'description' => 'Consulte a fundamentação registrada e o histórico institucional da decisão.', 'href' => '#historico-proposta', 'label' => 'Ver decisão'],
            default => ['icon' => 'eye', 'title' => 'Acompanhar andamento', 'description' => 'Consulte a situação atual, documentos e histórico da proposta.', 'href' => '#historico-proposta', 'label' => 'Ver histórico'],
        };
        $decisionPanel = match (true) {
            $canReview && $proposal->status === App\Models\LegislativeProposal::STATUS_SUBMITTED => [
                'scope' => 'Câmara',
                'title' => 'Decisão da conferência legislativa',
                'description' => 'Aprovar, devolver ou rejeitar com base nos requisitos mínimos antes de mandar ao Executivo.',
                'icon' => 'clipboard-check',
                'href' => '#conferencia-legislativa',
                'label' => 'Abrir decisão',
                'checks' => collect($reviewChecklist)->map(fn ($item, $field) => [
                    'label' => $item['label'],
                    'state' => $proposal->{$field} ? 'ok' : 'pending',
                    'value' => $proposal->{$field} ? 'conferido' : 'pendente',
                ])->values()->all(),
            ],
            $canReview && $proposal->status === App\Models\LegislativeProposal::STATUS_APPROVED => [
                'scope' => 'Câmara',
                'title' => 'Encaminhamento ao Executivo',
                'description' => 'A proposta já foi aprovada na conferência. Falta registrar o protocolo formal.',
                'icon' => 'send',
                'href' => '#protocolo-executivo',
                'label' => 'Protocolar',
                'checks' => [
                    ['label' => 'Conferência legislativa', 'state' => 'ok', 'value' => 'aprovada'],
                    ['label' => 'Reserva de saúde', 'state' => $healthGap > 0 ? 'attention' : 'ok', 'value' => $healthGap > 0 ? 'déficit R$ '.number_format($healthGap, 2, ',', '.') : 'atendida'],
                    ['label' => 'Protocolo da Câmara', 'state' => blank($proposal->protocol_number) ? 'pending' : 'ok', 'value' => $proposal->protocol_number ?: 'a informar'],
                ],
            ],
            $canReceive && $proposal->status === App\Models\LegislativeProposal::STATUS_SENT => [
                'scope' => 'Executivo',
                'title' => 'Recebimento pela Prefeitura',
                'description' => 'Confirme o processo administrativo para transformar a proposta em emenda executiva.',
                'icon' => 'inbox',
                'href' => '#recebimento-executivo',
                'label' => 'Receber agora',
                'checks' => [
                    ['label' => 'Protocolo da Câmara', 'state' => filled($proposal->protocol_number) ? 'ok' : 'attention', 'value' => $proposal->protocol_number ?: 'não informado'],
                    ['label' => 'Valor indicado', 'state' => 'ok', 'value' => 'R$ '.number_format((float) $proposal->estimated_amount, 2, ',', '.')],
                    ['label' => 'Processo executivo', 'state' => 'pending', 'value' => 'a abrir'],
                ],
            ],
            $canReceive && $proposal->status === App\Models\LegislativeProposal::STATUS_RECEIVED => [
                'scope' => 'Executivo',
                'title' => 'Reserva orçamentária',
                'description' => 'Registre a reserva integral para liberar plano de trabalho, execução e prestação de contas.',
                'icon' => 'wallet-cards',
                'href' => '#reserva-orcamentaria',
                'label' => 'Registrar reserva',
                'checks' => [
                    ['label' => 'Processo executivo', 'state' => filled($proposal->executive_process_number) ? 'ok' : 'attention', 'value' => $proposal->executive_process_number ?: 'pendente'],
                    ['label' => 'Emenda aberta', 'state' => $proposal->amendment ? 'ok' : 'attention', 'value' => $proposal->amendment ? $proposal->amendment->reference : 'pendente'],
                    ['label' => 'Valor a reservar', 'state' => 'pending', 'value' => 'R$ '.number_format((float) $proposal->estimated_amount, 2, ',', '.')],
                ],
            ],
            default => null,
        };
        $currentProcessIndex = match (true) {
            $proposal->status === App\Models\LegislativeProposal::STATUS_DRAFT,
            $proposal->status === App\Models\LegislativeProposal::STATUS_RETURNED => 0,
            $proposal->status === App\Models\LegislativeProposal::STATUS_SUBMITTED => 1,
            $proposal->status === App\Models\LegislativeProposal::STATUS_APPROVED => 2,
            $proposal->status === App\Models\LegislativeProposal::STATUS_SENT => 3,
            $proposal->status === App\Models\LegislativeProposal::STATUS_RECEIVED => 4,
            $proposal->status === App\Models\LegislativeProposal::STATUS_RESERVED && ! $executionStarted => 5,
            $proposal->status === App\Models\LegislativeProposal::STATUS_RESERVED && $paid <= 0 => 6,
            default => 7,
        };
        $trackingSteps = [
            ['title' => 'Proposta salva', 'owner' => 'Vereador', 'description' => 'Pedido criado ou ajustado antes do envio.', 'date' => $proposal->created_at, 'href' => '#editor-proposta'],
            ['title' => 'Conferencia da Camara', 'owner' => 'Camara', 'description' => 'Analise minima de objeto, valor, saude e beneficiario.', 'date' => $proposal->submitted_at, 'href' => '#conferencia-legislativa'],
            ['title' => 'Protocolo ao Executivo', 'owner' => 'Camara', 'description' => 'Encaminhamento formal para a Prefeitura.', 'date' => $proposal->reviewed_at, 'href' => '#protocolo-executivo'],
            ['title' => 'Recebimento municipal', 'owner' => 'Executivo', 'description' => 'Abertura do processo administrativo.', 'date' => $proposal->sent_at, 'href' => '#recebimento-executivo'],
            ['title' => 'Reserva orcamentaria', 'owner' => 'Executivo', 'description' => 'Confirmacao de dotacao para executar.', 'date' => $proposal->received_at, 'href' => '#reserva-orcamentaria'],
            ['title' => 'Plano de trabalho', 'owner' => 'Executivo', 'description' => 'Planejamento tecnico e cronograma.', 'date' => $amendment?->municipalWorkPlan?->created_at, 'href' => '#acompanhamento-executivo'],
            ['title' => 'Execucao', 'owner' => 'Prefeitura', 'description' => 'Entrega fisica e liquidacao acompanhadas.', 'date' => $executionDate, 'href' => '#acompanhamento-executivo'],
            ['title' => 'Pagamento', 'owner' => 'Prefeitura', 'description' => 'Pagamento registrado e prestacao de contas.', 'date' => $paymentDate, 'href' => '#acompanhamento-executivo'],
        ];
        $stepSlaDays = [0 => 2, 1 => 3, 2 => 2, 3 => 2, 4 => 5, 5 => 5, 6 => 7, 7 => 7];
        $currentTrackingStep = $trackingSteps[$currentProcessIndex] ?? $trackingSteps[0];
        $currentStepAgeDays = (int) floor($proposal->updated_at->diffInHours(now()) / 24);
        $currentStepSlaDays = $stepSlaDays[$currentProcessIndex] ?? 3;
        $currentStepDelayed = ! in_array($proposal->status, [
            App\Models\LegislativeProposal::STATUS_REJECTED,
        ], true) && $currentStepAgeDays >= $currentStepSlaDays;
    @endphp

    <div class="legislative-detail-heading">
        <div>
            <a href="{{ route('legislative.index', ['year' => $proposal->fiscal_year]) }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Portal Legislativo</a>
            <span class="eyebrow">{{ $proposal->reference }} · exercício {{ $proposal->fiscal_year }}</span>
            <h1>{{ $proposal->object }}</h1>
            <p>{{ $proposal->author_name }} · {{ $proposal->author_party }} · {{ $proposal->beneficiary_name }}</p>
        </div>
        <div class="legislative-detail-summary">
            <span class="legislative-status status-{{ $proposal->status }}">{{ $proposal->statusLabel() }}</span>
            <strong>R$ {{ number_format((float) $proposal->estimated_amount, 2, ',', '.') }}</strong>
            @if($proposal->protocol_sha256)<small title="{{ $proposal->protocol_sha256 }}"><i data-lucide="fingerprint" aria-hidden="true"></i>{{ substr($proposal->protocol_sha256, 0, 12) }}…</small>@endif
        </div>
    </div>

    <x-validation-summary />

    <section class="legislative-smart-summary">
        <div class="legislative-next-action">
            <span><i data-lucide="{{ $nextAction['icon'] }}" aria-hidden="true"></i></span>
            <div>
                <small>{{ $isCouncilor ? 'Próximo passo para o vereador' : ($isLegislativeReviewer ? 'Próximo passo da Câmara' : 'Próximo passo do Executivo') }}</small>
                <h2>{{ $nextAction['title'] }}</h2>
                <p>{{ $nextAction['description'] }}</p>
            </div>
            <a class="btn btn-primary" href="{{ $nextAction['href'] }}">{{ $nextAction['label'] }}</a>
        </div>
        <div class="legislative-smart-grid">
            <div><span>Valor solicitado</span><strong>R$ {{ number_format((float) $proposal->estimated_amount, 2, ',', '.') }}</strong><small>{{ $proposal->health_related ? 'Marcada para saúde' : 'Demais áreas' }}</small></div>
            <div><span>Saldo do vereador</span><strong>{{ $quota['remaining'] === null ? 'A configurar' : 'R$ '.number_format($quota['remaining'], 2, ',', '.') }}</strong><small>{{ $quota['count'] }} proposta(s) na carteira</small></div>
            <div class="{{ $healthGap > 0 ? 'needs-attention' : 'is-ok' }}"><span>Reserva de saúde</span><strong>{{ $healthGap > 0 ? 'Pendente' : 'Atendida' }}</strong><small>{{ $healthGap > 0 ? 'Faltam R$ '.number_format($healthGap, 2, ',', '.') : 'Proporção mínima preservada' }}</small></div>
            <div><span>Executivo</span><strong>{{ $proposal->executive_process_number ?: ($proposal->protocol_number ?: 'Aguardando protocolo') }}</strong><small>{{ $proposal->budget_reservation_number ? 'Reserva '.$proposal->budget_reservation_number : 'Reserva pendente' }}</small></div>
        </div>
    </section>

    @if($currentStepDelayed)
        <section class="legislative-stale-alert">
            <span><i data-lucide="timer-reset" aria-hidden="true"></i></span>
            <div>
                <small>Alerta interno de prazo</small>
                <strong>{{ $currentTrackingStep['title'] }} parada ha {{ $currentStepAgeDays }} dia(s)</strong>
                <p>Responsavel atual: {{ $currentTrackingStep['owner'] }}. O prazo operacional recomendado para esta etapa e de {{ $currentStepSlaDays }} dia(s).</p>
            </div>
            <a class="btn btn-outline-primary" href="{{ $currentTrackingStep['href'] }}"><i data-lucide="arrow-right" aria-hidden="true"></i>Ir para etapa</a>
        </section>
    @endif

    <section class="legislative-process-map" aria-label="Esteira de acompanhamento da proposta">
        <header>
            <div>
                <span class="page-kicker">Esteira da proposta</span>
                <h2>Onde esta e quem precisa agir</h2>
                <p>O vereador acompanha em uma unica tela o caminho entre Camara, Executivo, reserva, execucao e pagamento.</p>
            </div>
            <a class="btn btn-outline-primary" href="{{ $nextAction['href'] }}"><i data-lucide="arrow-right" aria-hidden="true"></i>{{ $nextAction['label'] }}</a>
        </header>
        <div class="legislative-process-steps">
            @foreach($trackingSteps as $index => $step)
                @php
                    $state = $proposal->status === App\Models\LegislativeProposal::STATUS_REJECTED && $index === 1
                        ? 'blocked'
                        : ($index < $currentProcessIndex ? 'complete' : ($index === $currentProcessIndex ? 'current' : 'pending'));
                    $date = $step['date'] ? \Illuminate\Support\Carbon::parse($step['date']) : null;
                    $stateLabel = match ($state) {
                        'complete' => 'concluido',
                        'current' => 'acao atual',
                        'blocked' => 'interrompido',
                        default => 'pendente',
                    };
                @endphp
                <a class="is-{{ $state }}" href="{{ $step['href'] }}">
                    <span><i data-lucide="{{ $state === 'complete' ? 'circle-check' : ($state === 'blocked' ? 'circle-x' : ($state === 'current' ? 'radio' : 'circle-dot')) }}" aria-hidden="true"></i></span>
                    <div>
                        <small>{{ $step['owner'] }} · {{ $stateLabel }}</small>
                        <strong>{{ $step['title'] }}</strong>
                        <p>{{ $step['description'] }}</p>
                        <em>{{ $date ? $date->format('d/m/Y H:i') : ($state === 'current' ? 'Parado ha '.$proposal->updated_at->diffForHumans(null, true) : 'Aguardando etapa anterior') }}</em>
                    </div>
                </a>
            @endforeach
        </div>
    </section>

    @if($decisionPanel)
        <section class="legislative-decision-panel" aria-label="Painel de decisão">
            <div class="legislative-decision-panel-main">
                <span><i data-lucide="{{ $decisionPanel['icon'] }}" aria-hidden="true"></i></span>
                <div>
                    <small>{{ $decisionPanel['scope'] }}</small>
                    <h2>{{ $decisionPanel['title'] }}</h2>
                    <p>{{ $decisionPanel['description'] }}</p>
                </div>
                <a class="btn btn-primary" href="{{ $decisionPanel['href'] }}">{{ $decisionPanel['label'] }}</a>
            </div>
            <div class="legislative-decision-checks">
                @foreach($decisionPanel['checks'] as $check)
                    <div class="is-{{ $check['state'] }}">
                        <span><i data-lucide="{{ $check['state'] === 'ok' ? 'circle-check' : ($check['state'] === 'attention' ? 'triangle-alert' : 'circle-dot') }}" aria-hidden="true"></i></span>
                        <div>
                            <strong>{{ $check['label'] }}</strong>
                            <small>{{ $check['value'] }}</small>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="legislative-flow" aria-label="Tramitação da proposta">
        @foreach([
            ['proposal', 'Cadastro Câmara', $proposal->created_at],
            ['protocol', 'Protocolo', $proposal->sent_at],
            ['analysis', 'Análise executiva', $proposal->budget_reserved_at],
            ['planning', 'Planejamento', $amendment?->municipalWorkPlan?->created_at],
            ['execution', 'Execução', $executionDate],
            ['payment', 'Pagamento', $paymentDate],
        ] as [$key, $label, $date])
            <div class="{{ $stepStatuses[$key] ? 'is-complete' : '' }}">
                <span><i data-lucide="{{ $stepStatuses[$key] ? 'circle-check' : 'circle-dot' }}" aria-hidden="true"></i></span>
                <strong>{{ $label }}</strong>
                <small>{{ $date ? \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') : ($stepStatuses[$key] ? 'Em andamento' : 'Pendente') }}</small>
            </div>
        @endforeach
    </section>

    <section class="legislative-quota-band compact">
        <div><span>Cota individual</span><strong>{{ $quota['author_ceiling'] === null ? 'A configurar' : 'R$ '.number_format($quota['author_ceiling'], 2, ',', '.') }}</strong><small>{{ $quota['councilor_seats'] ?: 'Nº de cadeiras pendente' }}</small></div>
        <div><span>Carteira indicada</span><strong>R$ {{ number_format($quota['used'], 2, ',', '.') }}</strong><small>{{ $quota['count'] }} proposta(s)</small></div>
        <div><span>Saldo</span><strong>{{ $quota['remaining'] === null ? 'A configurar' : 'R$ '.number_format($quota['remaining'], 2, ',', '.') }}</strong><small>Após esta proposta</small></div>
        <div class="health"><span>Saúde</span><strong>R$ {{ number_format($quota['health_allocated'], 2, ',', '.') }}</strong><small>{{ ($quota['health_gap'] ?? 0) > 0 ? 'Déficit R$ '.number_format($quota['health_gap'], 2, ',', '.') : 'Proporção atendida' }}</small></div>
    </section>

    @if($canEdit)
        <details class="legislative-editor" id="editor-proposta" @if($errors->any()) open @endif>
            <summary><span><i data-lucide="pencil" aria-hidden="true"></i><strong>Editar proposta</strong><small>Disponível enquanto o registro estiver em elaboração ou devolvido.</small></span><i data-lucide="chevron-down" aria-hidden="true"></i></summary>
            <form method="POST" action="{{ route('legislative.update', $proposal) }}" data-prevent-double-submit>
                @csrf
                @method('PATCH')
                <input name="_submission_token" type="hidden" value="{{ $updateToken }}">
                @include('legislative._form')
                <div class="legislative-form-actions"><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar alterações</button></div>
            </form>
        </details>

        <section class="legislative-action-band" id="enviar-conferencia">
            <div><span><i data-lucide="send" aria-hidden="true"></i></span><div><strong>Encaminhar à conferência legislativa</strong><p>A indicação ficará bloqueada para edição até a conferência dos requisitos mínimos.</p></div></div>
            <form method="POST" action="{{ route('legislative.submit', $proposal) }}" data-prevent-double-submit>@csrf<input name="_submission_token" type="hidden" value="{{ $submitToken }}"><button class="btn btn-primary" type="submit"><i data-lucide="send" aria-hidden="true"></i>Enviar para conferência</button></form>
        </section>
    @endif

    <div class="legislative-detail-grid">
        <div class="legislative-main-column">
            <section class="content-panel">
                <div class="content-panel-header"><div><h2 class="h5 mb-1">Indicação legislativa</h2><p class="small text-secondary mb-0">Conteúdo apresentado pelo parlamentar</p></div></div>
                <div class="legislative-data-grid">
                    <div class="span-2"><span>Objeto</span><strong>{{ $proposal->object }}</strong></div>
                    <div class="span-2"><span>Justificativa</span><p>{{ $proposal->justification }}</p></div>
                    <div><span>Natureza</span><strong>{{ App\Models\ParliamentaryAmendment::expenseDestinations()[$proposal->expense_destination] }}</strong></div>
                    <div><span>Forma de execução</span><strong>{{ App\Models\ParliamentaryAmendment::transferTypes()[$proposal->transfer_type] }}</strong></div>
                    <div><span>Prioridade</span><strong>{{ $proposal->priorityLabel() }}</strong></div>
                    <div><span>Destinação</span><strong>{{ $proposal->health_related ? 'Saúde' : 'Demais áreas' }}</strong></div>
                    <div><span>Programa</span><strong>{{ $proposal->program_reference ?: 'A confirmar' }}</strong></div>
                    <div><span>Ação</span><strong>{{ $proposal->action_reference ?: 'A confirmar' }}</strong></div>
                    <div class="span-2"><span>Necessidade pública</span><p>{{ $proposal->public_need }}</p></div>
                    <div><span>População atendida</span><strong>{{ $proposal->target_population ?: 'Não informada' }}</strong></div>
                    <div><span>Entrega estimada</span><strong>{{ $proposal->estimated_quantity ?: 'Não informada' }}</strong></div>
                    <div><span>Fonte da estimativa</span><strong>{{ $proposal->estimate_source }}</strong></div>
                    <div><span>Data pretendida</span><strong>{{ $proposal->desired_contract_at?->format('d/m/Y') ?: 'Não informada' }}</strong></div>
                </div>
            </section>

            @if($canReview && $proposal->status === App\Models\LegislativeProposal::STATUS_SUBMITTED)
                <section class="content-panel legislative-review-panel" id="conferencia-legislativa">
                    <div class="content-panel-header"><div><h2 class="h5 mb-1">Conferência mínima da Câmara</h2><p class="small text-secondary mb-0">Comissão de Finanças e Orçamento ou unidade definida no Regimento</p></div></div>
                    <form class="content-panel-body" method="POST" action="{{ route('legislative.review', $proposal) }}" data-prevent-double-submit>
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $reviewToken }}">
                        <div class="legislative-review-checks">
                            @foreach($reviewChecklist as $field => $item)
                                <label><input class="form-check-input" name="{{ $field }}" type="checkbox" value="1" @checked(old($field, $proposal->{$field}))><span><strong>{{ $item['label'] }}</strong><small>{{ $item['guidance'] }}</small></span></label>
                            @endforeach
                        </div>
                        @error('review')<p class="field-error mt-3 mb-0">{{ $message }}</p>@enderror
                        <label class="d-block mt-3">
                            <span class="form-label">Fundamentação <b class="required-mark">*</b></span>
                            <textarea class="form-control @error('review_notes') is-invalid @enderror" name="review_notes" rows="4" minlength="20" maxlength="5000" required>{{ old('review_notes', $proposal->review_notes) }}</textarea>
                            @error('review_notes')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </label>
                        <div class="legislative-decision-actions">
                            <button class="btn btn-outline-danger" name="decision" type="submit" value="reject"><i data-lucide="circle-x" aria-hidden="true"></i>Rejeitar</button>
                            <button class="btn btn-outline-secondary" name="decision" type="submit" value="return"><i data-lucide="undo-2" aria-hidden="true"></i>Devolver</button>
                            <button class="btn btn-primary" name="decision" type="submit" value="approve"><i data-lucide="badge-check" aria-hidden="true"></i>Aprovar análise</button>
                        </div>
                    </form>
                </section>
            @elseif($proposal->reviewed_at)
                <section class="content-panel">
                    <div class="content-panel-header"><div><h2 class="h5 mb-1">Registro da conferência legislativa</h2><p class="small text-secondary mb-0">{{ $proposal->reviewer?->name }} · {{ $proposal->reviewed_at->format('d/m/Y H:i') }}</p></div><span class="legislative-status status-{{ $proposal->status }}">{{ $proposal->statusLabel() }}</span></div>
                    <div class="content-panel-body"><p class="mb-0">{{ $proposal->review_notes }}</p></div>
                </section>
            @endif

            @if($canReview && $proposal->status === App\Models\LegislativeProposal::STATUS_APPROVED)
                <section class="legislative-action-panel" id="protocolo-executivo">
                    <div><span><i data-lucide="send" aria-hidden="true"></i></span><div><strong>Protocolo Câmara → Executivo</strong><p>A reserva de saúde será conferida sobre a carteira do autor antes do encaminhamento.</p></div></div>
                    <form method="POST" action="{{ route('legislative.protocol', $proposal) }}" data-prevent-double-submit>
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $protocolToken }}">
                        @error('protocol')<p class="field-error span-2 mb-0">{{ $message }}</p>@enderror
                        <label>
                            <span>Número do protocolo <b>*</b></span>
                            <input class="form-control @error('protocol_number') is-invalid @enderror" name="protocol_number" value="{{ old('protocol_number', $proposal->protocol_number) }}" required>
                            @error('protocol_number')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </label>
                        <button class="btn btn-primary" type="submit"><i data-lucide="send" aria-hidden="true"></i>Protocolar no Executivo</button>
                    </form>
                </section>
            @endif

            @if($canReceive && $proposal->status === App\Models\LegislativeProposal::STATUS_SENT)
                <section class="legislative-action-panel executive" id="recebimento-executivo">
                    <div><span><i data-lucide="download" aria-hidden="true"></i></span><div><strong>Recebimento pelo Executivo</strong><p>Abre a emenda no núcleo executivo sem dispensar a reanálise técnica.</p></div></div>
                    <div class="executive-receive-assistant">
                        <div>
                            <span><i data-lucide="sparkles" aria-hidden="true"></i></span>
                            <div>
                                <strong>Recebimento automatico preparado</strong>
                                <p>O TrilhaGov sugere processo, secretaria, classificacao e observacao inicial. Confirme para abrir a emenda executiva e gerar pendencias na Central de Trabalho.</p>
                            </div>
                        </div>
                        <dl>
                            @foreach($executiveReceiveSuggestion['items'] as $item)
                                <div><dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd></div>
                            @endforeach
                        </dl>
                    </div>
                    <form method="POST" action="{{ route('legislative.receive', $proposal) }}" data-prevent-double-submit>
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $receiveToken }}">
                        <label>
                            <span>Processo administrativo <b>*</b></span>
                            <input class="form-control @error('executive_process_number') is-invalid @enderror" name="executive_process_number" value="{{ old('executive_process_number', $executiveReceiveSuggestion['process_number']) }}" required>
                            @error('executive_process_number')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </label>
                        <label class="span-2">
                            <span>Conferência inicial <b>*</b></span>
                            <textarea class="form-control @error('executive_notes') is-invalid @enderror" name="executive_notes" rows="3" minlength="20" required>{{ old('executive_notes', $executiveReceiveSuggestion['notes']) }}</textarea>
                            @error('executive_notes')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </label>
                        <button class="btn btn-primary" type="submit"><i data-lucide="download" aria-hidden="true"></i>Confirmar recebimento</button>
                    </form>
                </section>
            @endif

            @if($canReceive && $proposal->status === App\Models\LegislativeProposal::STATUS_RECEIVED)
                <section class="legislative-action-panel executive" id="reserva-orcamentaria">
                    <div><span><i data-lucide="wallet-cards" aria-hidden="true"></i></span><div><strong>Reserva orçamentária</strong><p>Registre o resultado da reanálise antes de solicitar o Plano de Trabalho.</p></div></div>
                    <form method="POST" action="{{ route('legislative.reserve', $proposal) }}" data-prevent-double-submit>
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $reserveToken }}">
                        <label>
                            <span>Número da reserva <b>*</b></span>
                            <input class="form-control @error('budget_reservation_number') is-invalid @enderror" name="budget_reservation_number" value="{{ old('budget_reservation_number') }}" required>
                            @error('budget_reservation_number')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </label>
                        <label>
                            <span>Valor reservado <b>*</b></span>
                            <input class="form-control @error('budget_reserved_amount') is-invalid @enderror" name="budget_reserved_amount" type="number" min="0.01" step="0.01" value="{{ old('budget_reserved_amount', $proposal->estimated_amount) }}" required>
                            @error('budget_reserved_amount')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </label>
                        <label>
                            <span>Data da reserva <b>*</b></span>
                            <input class="form-control @error('budget_reserved_at') is-invalid @enderror" name="budget_reserved_at" type="date" max="{{ now()->toDateString() }}" value="{{ old('budget_reserved_at', now()->toDateString()) }}" required>
                            @error('budget_reserved_at')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </label>
                        <label class="span-2">
                            <span>Reanálise orçamentária <b>*</b></span>
                            <textarea class="form-control @error('executive_notes') is-invalid @enderror" name="executive_notes" rows="3" minlength="20" required>{{ old('executive_notes', $proposal->executive_notes) }}</textarea>
                            @error('executive_notes')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </label>
                        <button class="btn btn-primary" type="submit"><i data-lucide="wallet-cards" aria-hidden="true"></i>Registrar reserva</button>
                    </form>
                </section>
            @endif

            @if($proposal->amendment)
                <section class="content-panel legislative-execution-panel" id="acompanhamento-executivo">
                    <div class="content-panel-header"><div><h2 class="h5 mb-1">Acompanhamento no Executivo</h2><p class="small text-secondary mb-0">Processo {{ $proposal->executive_process_number }}</p></div>@unless(in_array($role, [App\Models\User::ROLE_COUNCILOR, App\Models\User::ROLE_LEGISLATIVE_REVIEWER], true))<a class="btn btn-sm btn-outline-primary" href="{{ route('emendas.show', $proposal->amendment) }}">Abrir fluxo executivo</a>@endunless</div>
                    <div class="legislative-execution-metrics">
                        <div><span>Situação</span><strong>{{ $proposal->amendment->statusLabel() }}</strong></div>
                        <div><span>Plano de trabalho</span><strong>{{ $proposal->amendment->municipalWorkPlan?->statusLabel() ?? 'Não iniciado' }}</strong></div>
                        <div><span>Execução física</span><strong>{{ $physicalProgress }}%</strong></div>
                        <div><span>Empenhado</span><strong>R$ {{ number_format($committed, 2, ',', '.') }}</strong></div>
                        <div><span>Liquidado</span><strong>R$ {{ number_format($liquidated, 2, ',', '.') }}</strong></div>
                        <div><span>Pago</span><strong>R$ {{ number_format($paid, 2, ',', '.') }}</strong></div>
                        <div><span>Prestação de contas</span><strong>{{ $proposal->amendment->accountabilityProcess?->statusLabel() ?? 'Não iniciada' }}</strong></div>
                    </div>
                </section>
            @endif
        </div>

        <aside class="legislative-side-column">
            <section class="content-panel" id="historico-proposta">
                <div class="content-panel-header"><h2 class="h6 mb-0">Beneficiário</h2></div>
                <dl class="legislative-side-data">
                    <dt>Tipo</dt><dd>{{ $proposal->beneficiaryTypeLabel() }}</dd>
                    <dt>Nome</dt><dd>{{ $proposal->beneficiary_name }}</dd>
                    <dt>CNPJ</dt><dd>{{ $proposal->beneficiary_cnpj ?: 'Não se aplica' }}</dd>
                    <dt>Localidade</dt><dd>{{ $proposal->beneficiary_location }}</dd>
                    <dt>Declaração</dt><dd>{{ $proposal->third_sector_conflict_declaration ? 'Registrada' : 'Não aplicável ou pendente' }}</dd>
                </dl>
            </section>

            <section class="content-panel">
                <div class="content-panel-header"><h2 class="h6 mb-0">Protocolo institucional</h2></div>
                <dl class="legislative-side-data">
                    <dt>Câmara</dt><dd>{{ $proposal->protocol_number ?: 'Pendente' }}</dd>
                    <dt>Processo executivo</dt><dd>{{ $proposal->executive_process_number ?: 'Pendente' }}</dd>
                    <dt>Reserva</dt><dd>{{ $proposal->budget_reservation_number ?: 'Pendente' }}</dd>
                    <dt>Valor reservado</dt><dd>{{ $proposal->budget_reserved_amount === null ? 'Pendente' : 'R$ '.number_format((float) $proposal->budget_reserved_amount, 2, ',', '.') }}</dd>
                </dl>
            </section>

            <section class="content-panel">
                <div class="content-panel-header"><h2 class="h6 mb-0">Histórico</h2></div>
                <div class="legislative-timeline">
                    @foreach($proposal->events->sortByDesc('created_at') as $event)
                        <div><span></span><div><strong>{{ $event->to_status ? (App\Models\LegislativeProposal::statuses()[$event->to_status] ?? $event->event_type) : $event->event_type }}</strong><p>{{ $event->notes }}</p><small>{{ $event->actor?->name ?? 'Sistema' }} · {{ $event->created_at->format('d/m/Y H:i') }}</small></div></div>
                    @endforeach
                </div>
            </section>
        </aside>
    </div>
@endsection
