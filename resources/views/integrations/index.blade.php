@extends('layouts.app')

@section('title', 'Integrações e conferência | TrilhaGov')

@section('content')
    @php
        $pendingCount = (int) ($counts['new'] ?? 0) + (int) ($counts['divergent'] ?? 0);
        $syncSucceeded = $latestSync?->status === App\Models\ExternalDataSync::STATUS_SUCCESS;
    @endphp

    <header class="integration-heading">
        <div>
            <p class="page-kicker mb-2">Dados oficiais e proveniência</p>
            <h1>Caixa de conferência</h1>
            <p>Compare a base municipal com transferências especiais publicadas pelo Transferegov.</p>
        </div>
        @if ($canEdit)
            <form method="POST" action="{{ route('integrations.sync') }}">
                @csrf
                <input type="hidden" name="_submission_token" value="{{ $syncToken }}">
                <button class="btn btn-primary" type="submit"><i data-lucide="refresh-cw" aria-hidden="true"></i>Consultar fonte oficial</button>
            </form>
        @endif
    </header>

    <section class="source-health {{ $latestSync?->status === 'failed' ? 'source-failed' : '' }}">
        <span class="source-health-icon"><i data-lucide="database-zap" aria-hidden="true"></i></span>
        <div class="source-health-main">
            <div><strong>Transferegov · Transferências Especiais</strong><span class="source-badge {{ $syncSucceeded ? 'is-online' : '' }}">{{ $latestSync ? ($syncSucceeded ? 'Consulta concluída' : ($latestSync->status === 'failed' ? 'Falha registrada' : 'Em processamento')) : 'Ainda não consultada' }}</span></div>
            <p>Consulta pelo CNPJ {{ preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $municipality->cnpj) }}. Nenhum dado local é alterado automaticamente.</p>
        </div>
        <div class="source-health-meta">
            <span>Última consulta<strong>{{ $latestSync?->completed_at?->format('d/m/Y H:i') ?? 'Não realizada' }}</strong></span>
            <span>Base oficial<strong>{{ $latestSync?->source_updated_at?->format('d/m/Y') ?? 'Não informada' }}</strong></span>
        </div>
    </section>

    <section class="integration-metrics" aria-label="Resumo da conferência">
        <article><span class="integration-metric-icon new"><i data-lucide="sparkles" aria-hidden="true"></i></span><div><span>Novas na fonte</span><strong>{{ (int) ($counts['new'] ?? 0) }}</strong><small>Aguardam cadastro ou vínculo</small></div></article>
        <article><span class="integration-metric-icon divergent"><i data-lucide="git-compare-arrows" aria-hidden="true"></i></span><div><span>Com divergência</span><strong>{{ (int) ($counts['divergent'] ?? 0) }}</strong><small>Exigem decisão campo a campo</small></div></article>
        <article><span class="integration-metric-icon matched"><i data-lucide="badge-check" aria-hidden="true"></i></span><div><span>Dados conferem</span><strong>{{ (int) ($counts['matched'] ?? 0) }}</strong><small>Correspondências confirmadas</small></div></article>
        <article><span class="integration-metric-icon reviewed"><i data-lucide="clipboard-check" aria-hidden="true"></i></span><div><span>Revisadas</span><strong>{{ (int) ($counts['imported'] ?? 0) + (int) ($counts['ignored'] ?? 0) }}</strong><small>Importadas ou justificadas</small></div></article>
    </section>

    <section class="integration-workflow" aria-label="Fluxo de conferência">
        <span class="is-done"><i data-lucide="circle-check" aria-hidden="true"></i><strong>1. Consultar</strong><small>CNPJ municipal</small></span>
        <i data-lucide="chevron-right" aria-hidden="true"></i>
        <span class="{{ $latestSync ? 'is-done' : '' }}"><i data-lucide="scan-search" aria-hidden="true"></i><strong>2. Comparar</strong><small>Códigos e campos</small></span>
        <i data-lucide="chevron-right" aria-hidden="true"></i>
        <span class="{{ $pendingCount === 0 && $latestSync ? 'is-done' : '' }}"><i data-lucide="user-round-check" aria-hidden="true"></i><strong>3. Decidir</strong><small>Revisão humana</small></span>
        <i data-lucide="chevron-right" aria-hidden="true"></i>
        <span><i data-lucide="history" aria-hidden="true"></i><strong>4. Auditar</strong><small>Origem preservada</small></span>
    </section>

    <nav class="integration-tabs" aria-label="Filtros da conferência">
        <a class="{{ $selectedStatus === '' ? 'active' : '' }}" href="{{ route('integrations.index') }}">Todos <span>{{ $counts->sum() }}</span></a>
        @foreach ($statuses as $value => $label)
            @if (($counts[$value] ?? 0) > 0 || in_array($value, ['new', 'divergent', 'matched'], true))
                <a class="{{ $selectedStatus === $value ? 'active' : '' }}" href="{{ route('integrations.index', ['status' => $value]) }}">{{ $label }} <span>{{ (int) ($counts[$value] ?? 0) }}</span></a>
            @endif
        @endforeach
    </nav>

    <div class="candidate-list">
        @forelse ($candidates as $candidate)
            @php
                $tokens = $actionTokens[$candidate->id] ?? [];
                $suggestedReference = $candidate->amendment_code ?: $candidate->external_code;
                $suggestedObject = $candidate->object ?: '';
            @endphp
            <article class="candidate-card candidate-{{ $candidate->match_status }}">
                <header class="candidate-header">
                    <div class="candidate-identity">
                        <span class="candidate-status">{{ $candidate->statusLabel() }}</span>
                        <h2>{{ $candidate->external_code ?? 'Plano sem código' }}</h2>
                        <p>{{ $candidate->amendment_code ? 'Emenda '.$candidate->amendment_code.' · ' : '' }}{{ $candidate->fiscal_year ?? 'Exercício não informado' }}</p>
                    </div>
                    <div class="candidate-source">
                        <span>Fonte oficial</span><strong>Transferegov</strong><small>Visto em {{ $candidate->last_seen_at->format('d/m/Y H:i') }}</small>
                    </div>
                </header>

                <div class="candidate-summary">
                    <div><span>Parlamentar</span><strong>{{ $candidate->author_name ?: 'Não informado' }}</strong></div>
                    <div><span>Valor oficial</span><strong>{{ $candidate->expected_amount !== null ? 'R$ '.number_format((float) $candidate->expected_amount, 2, ',', '.') : 'Não informado' }}</strong></div>
                    <div><span>Situação oficial</span><strong>{{ $candidate->external_status ?: 'Não informada' }}</strong></div>
                    <div><span>Conta bancária</span><strong>{{ $candidate->bank_status ?: 'Não informada' }}</strong></div>
                </div>

                @if ($candidate->object)
                    <div class="candidate-object"><span>Objeto oficial</span><p>{{ $candidate->object }}</p></div>
                @endif

                @if ($candidate->amendment)
                    <div class="candidate-link-line">
                        <span><i data-lucide="link-2" aria-hidden="true"></i>Vinculada a <a href="{{ route('emendas.show', $candidate->amendment) }}">{{ $candidate->amendment->reference }}</a></span>
                        <span>{{ count($candidate->differences ?? []) }} diferença(s)</span>
                    </div>
                @endif

                @if ($candidate->match_status === 'divergent' && $candidate->amendment)
                    <form class="difference-review" method="POST" action="{{ route('integrations.candidates.apply', $candidate) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="_submission_token" value="{{ $tokens['apply'] ?? '' }}">
                        <div class="difference-heading"><div><p class="panel-kicker">Decisão assistida</p><h3>Escolha quais dados oficiais aplicar</h3></div><small>O valor municipal permanece até sua confirmação.</small></div>
                        <div class="difference-table">
                            <div class="difference-table-head"><span></span><span>Campo</span><span>TrilhaGov</span><span>Fonte oficial</span></div>
                            @foreach ($candidate->differences as $field => $difference)
                                <label class="difference-row">
                                    <input class="form-check-input" type="checkbox" name="fields[]" value="{{ $field }}">
                                    <strong>{{ $difference['label'] }}</strong>
                                    <span>{{ $field === 'expected_amount' ? 'R$ '.number_format((float) $difference['local'], 2, ',', '.') : ($difference['local'] ?: 'Não informado') }}</span>
                                    <span>{{ $field === 'expected_amount' ? 'R$ '.number_format((float) $difference['external'], 2, ',', '.') : $difference['external'] }}</span>
                                </label>
                            @endforeach
                        </div>
                        @if ($canEdit)<button class="btn btn-primary" type="submit"><i data-lucide="check-check" aria-hidden="true"></i>Aplicar selecionados</button>@endif
                    </form>
                @endif

                @if ($canEdit && ! $candidate->amendment && $candidate->match_status !== 'ignored')
                    <div class="candidate-actions">
                        <details>
                            <summary><i data-lucide="link-2" aria-hidden="true"></i>Vincular existente</summary>
                            <form class="candidate-inline-form" method="POST" action="{{ route('integrations.candidates.link', $candidate) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="_submission_token" value="{{ $tokens['link'] ?? '' }}">
                                <label><span>Emenda municipal</span><select class="form-select" name="parliamentary_amendment_id" required><option value="">Selecione</option>@foreach ($amendments as $amendment)<option value="{{ $amendment->id }}">{{ $amendment->reference }} · {{ $amendment->fiscal_year }}</option>@endforeach</select></label>
                                <button class="btn btn-primary" type="submit">Comparar</button>
                            </form>
                        </details>
                        <details>
                            <summary><i data-lucide="file-input" aria-hidden="true"></i>Importar nova emenda</summary>
                            <form class="candidate-import-form" method="POST" action="{{ route('integrations.candidates.import', $candidate) }}">
                                @csrf
                                <input type="hidden" name="_submission_token" value="{{ $tokens['import'] ?? '' }}">
                                <label><span>Identificação *</span><input class="form-control" name="reference" value="{{ $suggestedReference }}" required maxlength="100"></label>
                                <label><span>Partido *</span><input class="form-control" name="author_party" required maxlength="20"></label>
                                <label class="span-2"><span>Objeto *</span><textarea class="form-control" name="object" rows="3" required maxlength="5000">{{ $suggestedObject }}</textarea></label>
                                <label><span>Órgão responsável *</span><input class="form-control" name="responsible_department" list="integration-departments" required maxlength="255"></label>
                                <label><span>Responsável operacional</span><select class="form-select" name="responsible_user_id"><option value="">Definir depois</option>@foreach ($responsibleUsers as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach</select></label>
                                <label><span>Data da indicação *</span><input class="form-control" type="date" name="indicated_at" value="{{ $candidate->accepted_at?->format('Y-m-d') ?? today()->format('Y-m-d') }}" required></label>
                                <label><span>Prazo de comunicação *</span><input class="form-control" type="date" name="communication_deadline" value="{{ today()->addDays(30)->format('Y-m-d') }}" required></label>
                                <label><span>Prazo de execução *</span><input class="form-control" type="date" name="execution_deadline" value="{{ today()->addMonths(6)->format('Y-m-d') }}" required></label>
                                <label><span>Prazo da prestação *</span><input class="form-control" type="date" name="accountability_deadline" value="{{ today()->addMonths(9)->format('Y-m-d') }}" required></label>
                                <div class="span-2 candidate-import-submit"><small>O registro entrará como identificado e manterá a origem oficial na auditoria.</small><button class="btn btn-primary" type="submit"><i data-lucide="file-input" aria-hidden="true"></i>Importar conferido</button></div>
                            </form>
                        </details>
                    </div>
                @endif

                @if ($canEdit && ! in_array($candidate->match_status, ['ignored', 'imported'], true))
                    <details class="candidate-ignore">
                        <summary><i data-lucide="ban" aria-hidden="true"></i>Ignorar candidato</summary>
                        <form method="POST" action="{{ route('integrations.candidates.ignore', $candidate) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="_submission_token" value="{{ $tokens['ignore'] ?? '' }}">
                            <label><span>Justificativa *</span><input class="form-control" name="review_notes" required minlength="5" maxlength="1000"></label>
                            <button class="btn btn-outline-danger" type="submit">Registrar</button>
                        </form>
                    </details>
                @elseif ($candidate->review_notes)
                    <div class="candidate-review-note"><strong>Justificativa:</strong> {{ $candidate->review_notes }} · {{ $candidate->reviewer?->name }}</div>
                @endif
            </article>
        @empty
            <section class="integration-empty">
                <span><i data-lucide="database-zap" aria-hidden="true"></i></span>
                <h2>{{ $latestSync ? 'Nenhum registro neste filtro' : 'A fonte oficial ainda não foi consultada' }}</h2>
                <p>{{ $latestSync ? 'Altere o filtro para consultar os demais resultados.' : 'A primeira consulta localizará o município pelo CNPJ e preparará os planos para conferência.' }}</p>
            </section>
        @endforelse
    </div>

    {{ $candidates->links() }}
    <datalist id="integration-departments">@foreach ($departments as $department)<option value="{{ $department }}">@endforeach</datalist>
@endsection
