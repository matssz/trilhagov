@extends('layouts.app')

@section('title', $proposal->reference.' | Portal Legislativo')

@section('content')
    @php
        $stepStatuses = [
            'proposal' => true,
            'review' => in_array($proposal->status, ['approved', 'rejected', 'sent', 'received', 'reserved'], true),
            'protocol' => in_array($proposal->status, ['sent', 'received', 'reserved'], true),
            'received' => in_array($proposal->status, ['received', 'reserved'], true),
            'reserved' => $proposal->status === 'reserved',
        ];
        $commitments = $proposal->amendment?->financialCommitments ?? collect();
        $committed = (float) $commitments->where('status', 'active')->sum('amount');
        $paid = (float) $commitments->flatMap->payments->sum('amount');
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

    <section class="legislative-flow" aria-label="Tramitação da proposta">
        @foreach([
            ['proposal', 'Proposição', $proposal->created_at],
            ['review', 'Análise prévia', $proposal->reviewed_at],
            ['protocol', 'Protocolo', $proposal->sent_at],
            ['received', 'Recebimento', $proposal->received_at],
            ['reserved', 'Reserva', $proposal->budget_reserved_at],
        ] as [$key, $label, $date])
            <div class="{{ $stepStatuses[$key] ? 'is-complete' : '' }}">
                <span><i data-lucide="{{ $stepStatuses[$key] ? 'circle-check' : 'circle-dot' }}" aria-hidden="true"></i></span>
                <strong>{{ $label }}</strong>
                <small>{{ $date ? \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') : 'Pendente' }}</small>
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
        <details class="legislative-editor" @if($errors->any()) open @endif>
            <summary><span><i data-lucide="pencil" aria-hidden="true"></i><strong>Editar proposta</strong><small>Disponível enquanto o registro estiver em elaboração ou devolvido.</small></span><i data-lucide="chevron-down" aria-hidden="true"></i></summary>
            <form method="POST" action="{{ route('legislative.update', $proposal) }}" data-prevent-double-submit>
                @csrf
                @method('PATCH')
                <input name="_submission_token" type="hidden" value="{{ $updateToken }}">
                @include('legislative._form')
                <div class="legislative-form-actions"><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar alterações</button></div>
            </form>
        </details>

        <section class="legislative-action-band">
            <div><span><i data-lucide="send" aria-hidden="true"></i></span><div><strong>Encaminhar à comissão técnica</strong><p>A proposta ficará bloqueada para edição até a conclusão da análise.</p></div></div>
            <form method="POST" action="{{ route('legislative.submit', $proposal) }}" data-prevent-double-submit>@csrf<input name="_submission_token" type="hidden" value="{{ $submitToken }}"><button class="btn btn-primary" type="submit"><i data-lucide="send" aria-hidden="true"></i>Enviar para análise</button></form>
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
                <section class="content-panel legislative-review-panel">
                    <div class="content-panel-header"><div><h2 class="h5 mb-1">Análise técnica prévia</h2><p class="small text-secondary mb-0">Comissão de Finanças e Orçamento ou unidade definida no Regimento</p></div></div>
                    <form class="content-panel-body" method="POST" action="{{ route('legislative.review', $proposal) }}" data-prevent-double-submit>
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $reviewToken }}">
                        <div class="legislative-review-checks">
                            @foreach($reviewChecklist as $field => $item)
                                <label><input class="form-check-input" name="{{ $field }}" type="checkbox" value="1" @checked(old($field, $proposal->{$field}))><span><strong>{{ $item['label'] }}</strong><small>{{ $item['guidance'] }}</small></span></label>
                            @endforeach
                        </div>
                        <label class="d-block mt-3"><span class="form-label">Fundamentação <b class="required-mark">*</b></span><textarea class="form-control" name="review_notes" rows="4" minlength="20" maxlength="5000" required>{{ old('review_notes', $proposal->review_notes) }}</textarea></label>
                        <div class="legislative-decision-actions">
                            <button class="btn btn-outline-danger" name="decision" type="submit" value="reject"><i data-lucide="circle-x" aria-hidden="true"></i>Rejeitar</button>
                            <button class="btn btn-outline-secondary" name="decision" type="submit" value="return"><i data-lucide="undo-2" aria-hidden="true"></i>Devolver</button>
                            <button class="btn btn-primary" name="decision" type="submit" value="approve"><i data-lucide="badge-check" aria-hidden="true"></i>Aprovar análise</button>
                        </div>
                    </form>
                </section>
            @elseif($proposal->reviewed_at)
                <section class="content-panel">
                    <div class="content-panel-header"><div><h2 class="h5 mb-1">Parecer da análise prévia</h2><p class="small text-secondary mb-0">{{ $proposal->reviewer?->name }} · {{ $proposal->reviewed_at->format('d/m/Y H:i') }}</p></div><span class="legislative-status status-{{ $proposal->status }}">{{ $proposal->statusLabel() }}</span></div>
                    <div class="content-panel-body"><p class="mb-0">{{ $proposal->review_notes }}</p></div>
                </section>
            @endif

            @if($canReview && $proposal->status === App\Models\LegislativeProposal::STATUS_APPROVED)
                <section class="legislative-action-panel">
                    <div><span><i data-lucide="send" aria-hidden="true"></i></span><div><strong>Protocolo Câmara → Executivo</strong><p>A reserva de saúde será conferida sobre a carteira do autor antes do encaminhamento.</p></div></div>
                    <form method="POST" action="{{ route('legislative.protocol', $proposal) }}" data-prevent-double-submit>
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $protocolToken }}">
                        <label><span>Número do protocolo <b>*</b></span><input class="form-control" name="protocol_number" value="{{ old('protocol_number', $proposal->protocol_number) }}" required></label>
                        <button class="btn btn-primary" type="submit"><i data-lucide="send" aria-hidden="true"></i>Protocolar no Executivo</button>
                    </form>
                </section>
            @endif

            @if($canReceive && $proposal->status === App\Models\LegislativeProposal::STATUS_SENT)
                <section class="legislative-action-panel executive">
                    <div><span><i data-lucide="download" aria-hidden="true"></i></span><div><strong>Recebimento pelo Executivo</strong><p>Abre a emenda no núcleo executivo sem dispensar a reanálise técnica.</p></div></div>
                    <form method="POST" action="{{ route('legislative.receive', $proposal) }}" data-prevent-double-submit>
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $receiveToken }}">
                        <label><span>Processo administrativo <b>*</b></span><input class="form-control" name="executive_process_number" value="{{ old('executive_process_number') }}" required></label>
                        <label class="span-2"><span>Conferência inicial <b>*</b></span><textarea class="form-control" name="executive_notes" rows="3" minlength="20" required>{{ old('executive_notes') }}</textarea></label>
                        <button class="btn btn-primary" type="submit"><i data-lucide="download" aria-hidden="true"></i>Confirmar recebimento</button>
                    </form>
                </section>
            @endif

            @if($canReceive && $proposal->status === App\Models\LegislativeProposal::STATUS_RECEIVED)
                <section class="legislative-action-panel executive">
                    <div><span><i data-lucide="wallet-cards" aria-hidden="true"></i></span><div><strong>Reserva orçamentária</strong><p>Registre o resultado da reanálise antes de solicitar o Plano de Trabalho.</p></div></div>
                    <form method="POST" action="{{ route('legislative.reserve', $proposal) }}" data-prevent-double-submit>
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $reserveToken }}">
                        <label><span>Número da reserva <b>*</b></span><input class="form-control" name="budget_reservation_number" value="{{ old('budget_reservation_number') }}" required></label>
                        <label><span>Valor reservado <b>*</b></span><input class="form-control" name="budget_reserved_amount" type="number" min="0.01" step="0.01" value="{{ old('budget_reserved_amount', $proposal->estimated_amount) }}" required></label>
                        <label><span>Data da reserva <b>*</b></span><input class="form-control" name="budget_reserved_at" type="date" max="{{ now()->toDateString() }}" value="{{ old('budget_reserved_at', now()->toDateString()) }}" required></label>
                        <label class="span-2"><span>Reanálise orçamentária <b>*</b></span><textarea class="form-control" name="executive_notes" rows="3" minlength="20" required>{{ old('executive_notes', $proposal->executive_notes) }}</textarea></label>
                        <button class="btn btn-primary" type="submit"><i data-lucide="wallet-cards" aria-hidden="true"></i>Registrar reserva</button>
                    </form>
                </section>
            @endif

            @if($proposal->amendment)
                <section class="content-panel legislative-execution-panel">
                    <div class="content-panel-header"><div><h2 class="h5 mb-1">Acompanhamento no Executivo</h2><p class="small text-secondary mb-0">Processo {{ $proposal->executive_process_number }}</p></div>@unless(in_array($role, [App\Models\User::ROLE_COUNCILOR, App\Models\User::ROLE_LEGISLATIVE_REVIEWER], true))<a class="btn btn-sm btn-outline-primary" href="{{ route('emendas.show', $proposal->amendment) }}">Abrir emenda</a>@endunless</div>
                    <div class="legislative-execution-metrics">
                        <div><span>Situação</span><strong>{{ $proposal->amendment->statusLabel() }}</strong></div>
                        <div><span>Plano de trabalho</span><strong>{{ $proposal->amendment->municipalWorkPlan?->statusLabel() ?? 'Não iniciado' }}</strong></div>
                        <div><span>Empenhado</span><strong>R$ {{ number_format($committed, 2, ',', '.') }}</strong></div>
                        <div><span>Pago</span><strong>R$ {{ number_format($paid, 2, ',', '.') }}</strong></div>
                        <div><span>Prestação de contas</span><strong>{{ $proposal->amendment->accountabilityProcess?->statusLabel() ?? 'Não iniciada' }}</strong></div>
                    </div>
                </section>
            @endif
        </div>

        <aside class="legislative-side-column">
            <section class="content-panel">
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
