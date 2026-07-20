@extends('layouts.app')

@section('title', 'Normas municipais | TrilhaGov')

@section('content')
<div class="municipal-rules-page">
    <header class="rules-heading">
        <div>
            <p class="page-kicker mb-2">{{ $municipality->name }} / {{ $municipality->state }}</p>
            <h1>Normas municipais</h1>
            <p>Parâmetros locais que orientam a análise, a execução e o controle das emendas.</p>
        </div>
        @if ($canManage)
            <form class="new-rules-form" method="POST" action="{{ route('municipal-rules.store') }}" data-prevent-double-submit>
                @csrf
                <input type="hidden" name="_submission_token" value="{{ $profileToken }}">
                <label for="new-fiscal-year">Novo exercício</label>
                <div>
                    <input class="form-control" id="new-fiscal-year" name="fiscal_year" type="number" min="2020" max="2100" value="{{ old('fiscal_year', now()->year) }}" required>
                    <button class="btn btn-primary" type="submit"><i data-lucide="file-plus-2" aria-hidden="true"></i>Iniciar</button>
                </div>
            </form>
        @endif
    </header>

    @if ($profiles->isNotEmpty())
        <nav class="rules-versions" aria-label="Versões das normas municipais">
            @foreach ($profiles as $item)
                <a class="rules-version {{ $profile?->id === $item->id ? 'active' : '' }}" href="{{ route('municipal-rules.index', ['perfil' => $item->id]) }}">
                    <strong>{{ $item->fiscal_year }}</strong>
                    <span>v{{ $item->version }} · {{ $item->statusLabel() }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    @if (! $profile)
        <section class="rules-empty">
            <i data-lucide="landmark" aria-hidden="true"></i>
            <h2>Nenhum exercício configurado</h2>
            <p>O gestor municipal pode iniciar o primeiro levantamento normativo.</p>
        </section>
    @else
        <section class="rules-overview">
            <div class="rules-status-copy">
                <span class="rules-state rules-state-{{ $profile->status }}">{{ $profile->statusLabel() }}</span>
                <p>Exercício {{ $profile->fiscal_year }} · revisão {{ $profile->version }}</p>
                <h2>{{ $profile->regimeStatusLabel() }}</h2>
            </div>
            <div class="rules-metric">
                <span>Prontidão</span>
                <strong>{{ $diagnostic['score'] }}%</strong>
                <div class="rules-progress"><span style="width: {{ $diagnostic['score'] }}%"></span></div>
            </div>
            <div class="rules-metric">
                <span>Instrumentos</span>
                <strong>{{ $profile->instruments->count() }}</strong>
                <small>{{ $profile->instruments->pluck('type')->unique()->count() }} tipos registrados</small>
            </div>
            <div class="rules-metric">
                <span>Teto calculado</span>
                <strong>{{ $diagnostic['ceiling'] === null ? 'Pendente' : 'R$ '.number_format($diagnostic['ceiling'], 2, ',', '.') }}</strong>
                <small>RCL anterior × percentual validado</small>
            </div>
        </section>

        <div class="rules-notice">
            <i data-lucide="scale" aria-hidden="true"></i>
            <p><strong>Controle assistido.</strong> A ativação registra a decisão municipal, mas não substitui parecer jurídico nem validação do Tribunal de Contas.</p>
        </div>

        <section class="rules-diagnostic-band">
            <div class="rules-section-title">
                <div><p class="panel-kicker">Diagnóstico</p><h2>Prontidão normativa</h2></div>
                @if ($profile->status === 'active' && $canManage)
                    <form method="POST" action="{{ route('municipal-rules.revise', $profile) }}" data-prevent-double-submit>
                        @csrf
                        <button class="btn btn-outline-primary" type="submit"><i data-lucide="copy-plus" aria-hidden="true"></i>Nova revisão</button>
                    </form>
                @endif
            </div>
            <div class="rules-diagnostic-grid">
                <div>
                    <h3>Requisitos para ativação</h3>
                    <ul class="rules-checklist">
                        @foreach ($diagnostic['checks'] as $check)
                            <li class="{{ $check['complete'] ? 'done' : '' }}">
                                <i data-lucide="{{ $check['complete'] ? 'circle-check' : 'circle-dashed' }}" aria-hidden="true"></i>
                                <span>{{ $check['label'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <h3>Pontos de atenção</h3>
                    @if ($diagnostic['warnings'] === [])
                        <p class="rules-clear"><i data-lucide="shield-check" aria-hidden="true"></i>Nenhum alerta adicional nesta revisão.</p>
                    @else
                        <ul class="rules-warning-list">
                            @foreach ($diagnostic['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </section>

        <section class="rules-instruments-band">
            <div class="rules-section-title">
                <div><p class="panel-kicker">Base legal local</p><h2>Instrumentos normativos</h2></div>
                <span>{{ $profile->instruments->count() }} registrado(s)</span>
            </div>

            @if ($profile->instruments->isEmpty())
                <p class="rules-inline-empty">Nenhum instrumento vinculado a esta revisão.</p>
            @else
                <div class="instrument-list">
                    @foreach ($profile->instruments->sortBy('type') as $instrument)
                        <article class="instrument-row">
                            <span class="instrument-icon"><i data-lucide="scroll-text" aria-hidden="true"></i></span>
                            <div>
                                <small>{{ $instrument->typeLabel() }}</small>
                                <strong>{{ $instrument->title }}</strong>
                                <span>{{ $instrument->reference }}{{ $instrument->enacted_at ? ' · '.$instrument->enacted_at->format('d/m/Y') : '' }}</span>
                            </div>
                            @if ($instrument->url)<a class="icon-button" href="{{ $instrument->url }}" target="_blank" rel="noopener noreferrer" title="Abrir fonte" aria-label="Abrir fonte"><i data-lucide="external-link" aria-hidden="true"></i></a>@endif
                            @if ($canManage && $profile->isDraft())
                                <form method="POST" action="{{ route('municipal-rules.instruments.destroy', [$profile, $instrument]) }}" onsubmit="return confirm('Remover este instrumento da revisão?')">
                                    @csrf @method('DELETE')
                                    <button class="icon-button danger" type="submit" title="Remover" aria-label="Remover"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif

            @if ($canManage && $profile->isDraft())
                <details class="instrument-create" @if($errors->hasAny(['type', 'title', 'reference', 'url', 'enacted_at', 'effective_from', 'effective_until'])) open @endif>
                    <summary><i data-lucide="plus" aria-hidden="true"></i>Adicionar instrumento</summary>
                    <form method="POST" action="{{ route('municipal-rules.instruments.store', $profile) }}" data-prevent-double-submit>
                        @csrf
                        <input type="hidden" name="_submission_token" value="{{ $instrumentToken }}">
                        <label><span>Tipo <b>*</b></span><select class="form-select" name="type" required><option value="">Selecione</option>@foreach(App\Models\MunicipalNormativeInstrument::types() as $value => $label)<option value="{{ $value }}" @selected(old('type') === $value)>{{ $label }}</option>@endforeach</select></label>
                        <label><span>Título <b>*</b></span><input class="form-control" name="title" value="{{ old('title') }}" maxlength="255" required></label>
                        <label><span>Número ou referência <b>*</b></span><input class="form-control" name="reference" value="{{ old('reference') }}" maxlength="180" required></label>
                        <label><span>Data do ato</span><input class="form-control" name="enacted_at" type="date" value="{{ old('enacted_at') }}"></label>
                        <label class="span-2"><span>Fonte oficial</span><input class="form-control" name="url" type="url" value="{{ old('url') }}" placeholder="https://..."></label>
                        <label><span>Início da vigência</span><input class="form-control" name="effective_from" type="date" value="{{ old('effective_from') }}"></label>
                        <label><span>Fim da vigência</span><input class="form-control" name="effective_until" type="date" value="{{ old('effective_until') }}"></label>
                        <label class="span-2"><span>Observações</span><textarea class="form-control" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea></label>
                        @if ($errors->any())<div class="rules-form-errors span-2">{{ $errors->first() }}</div>@endif
                        <div class="span-2 rules-form-actions"><button class="btn btn-primary" type="submit"><i data-lucide="link-2" aria-hidden="true"></i>Vincular</button></div>
                    </form>
                </details>
            @endif
        </section>

        @if ($canManage && $profile->isDraft())
            <form class="rules-parameters" method="POST" action="{{ route('municipal-rules.update', $profile) }}" data-prevent-double-submit>
                @csrf @method('PATCH')
                <div class="rules-section-title">
                    <div><p class="panel-kicker">Parametrização</p><h2>Decisões do município</h2></div>
                    <button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar parâmetros</button>
                </div>

                <fieldset>
                    <legend>Regime e limites</legend>
                    <div class="rules-fields">
                        <label><span>Situação local <b>*</b></span><select class="form-select" name="regime_status" required>@foreach(App\Models\MunicipalRegulatoryProfile::regimeStatuses() as $value => $label)<option value="{{ $value }}" @selected(old('regime_status', $profile->regime_status) === $value)>{{ $label }}</option>@endforeach</select></label>
                        <label><span>RCL do exercício anterior (R$)</span><input class="form-control" name="previous_year_rcl" type="number" min="0" step="0.01" value="{{ old('previous_year_rcl', $profile->previous_year_rcl) }}"></label>
                        <label><span>Limite individual (%)</span><input class="form-control" name="individual_limit_percentage" type="number" min="0.0001" max="10" step="0.0001" value="{{ old('individual_limit_percentage', $profile->individual_limit_percentage) }}"></label>
                        <label><span>Reserva para saúde (%)</span><input class="form-control" name="health_reserve_percentage" type="number" min="0" max="100" step="0.0001" value="{{ old('health_reserve_percentage', $profile->health_reserve_percentage) }}"></label>
                        <label><span>Aplicação da reserva</span><select class="form-select" name="health_reserve_method"><option value="">A confirmar</option>@foreach(App\Models\MunicipalRegulatoryProfile::healthReserveMethods() as $value => $label)<option value="{{ $value }}" @selected(old('health_reserve_method', $profile->health_reserve_method) === $value)>{{ $label }}</option>@endforeach</select></label>
                        <label><span>Máximo por vereador</span><input class="form-control" name="amendments_per_councilor_limit" type="number" min="1" max="1000" value="{{ old('amendments_per_councilor_limit', $profile->amendments_per_councilor_limit) }}"></label>
                        <label><span>Valor mínimo por emenda (R$)</span><input class="form-control" name="minimum_amendment_amount" type="number" min="0" step="0.01" value="{{ old('minimum_amendment_amount', $profile->minimum_amendment_amount) }}"></label>
                    </div>
                </fieldset>

                @php $binaryOptions = ['' => 'A confirmar', '1' => 'Sim', '0' => 'Não']; @endphp
                <fieldset>
                    <legend>Admissibilidade e execução</legend>
                    <div class="rules-fields">
                        @foreach ([
                            'generic_amendments_prohibited' => 'Objetos genéricos são proibidos?',
                            'prior_technical_review_required' => 'Há análise técnica prévia?',
                            'work_plan_required' => 'Plano de trabalho é obrigatório?',
                            'pca_check_required' => 'Há conferência com o PCA?',
                        ] as $field => $label)
                            @php $current = old($field, is_null($profile->{$field}) ? '' : ($profile->{$field} ? '1' : '0')); @endphp
                            <label><span>{{ $label }}</span><select class="form-select" name="{{ $field }}">@foreach($binaryOptions as $value => $option)<option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $option }}</option>@endforeach</select></label>
                        @endforeach
                        <label><span>Prazo para comunicar impedimento (dias)</span><input class="form-control" name="impediment_notice_days" type="number" min="0" max="365" value="{{ old('impediment_notice_days', $profile->impediment_notice_days) }}"></label>
                        <label><span>Prazo para saneamento (dias)</span><input class="form-control" name="impediment_correction_days" type="number" min="0" max="365" value="{{ old('impediment_correction_days', $profile->impediment_correction_days) }}"></label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Transparência, documentos e Audesp</legend>
                    <div class="rules-fields">
                        <label><span>Atualização do portal (dias úteis)</span><input class="form-control" name="publication_business_days" type="number" min="0" max="90" value="{{ old('publication_business_days', $profile->publication_business_days) }}"></label>
                        <label><span>Retenção documental (anos)</span><input class="form-control" name="document_retention_years" type="number" min="1" max="100" value="{{ old('document_retention_years', $profile->document_retention_years) }}"></label>
                        <label><span>Rastreabilidade bancária</span><select class="form-select" name="bank_traceability_rule"><option value="">A confirmar</option>@foreach(App\Models\MunicipalRegulatoryProfile::bankTraceabilityRules() as $value => $label)<option value="{{ $value }}" @selected(old('bank_traceability_rule', $profile->bank_traceability_rule) === $value)>{{ $label }}</option>@endforeach</select></label>
                        <label><span>Preparação Audesp <b>*</b></span><select class="form-select" name="audesp_registration_status" required>@foreach(App\Models\MunicipalRegulatoryProfile::audespStatuses() as $value => $label)<option value="{{ $value }}" @selected(old('audesp_registration_status', $profile->audesp_registration_status) === $value)>{{ $label }}</option>@endforeach</select></label>
                        <label><span>Responsável Audesp</span><select class="form-select" name="audesp_responsible_user_id"><option value="">Não definido</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected((string) old('audesp_responsible_user_id', $profile->audesp_responsible_user_id) === (string) $member->id)>{{ $member->name }}</option>@endforeach</select></label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Revisão jurídica</legend>
                    <div class="rules-fields">
                        <label><span>Responsável</span><input class="form-control" name="legal_review_responsible" value="{{ old('legal_review_responsible', $profile->legal_review_responsible) }}" maxlength="255"></label>
                        <label><span>Parecer ou processo</span><input class="form-control" name="legal_review_reference" value="{{ old('legal_review_reference', $profile->legal_review_reference) }}" maxlength="255"></label>
                        <label><span>Data da revisão</span><input class="form-control" name="legal_reviewed_at" type="date" max="{{ today()->toDateString() }}" value="{{ old('legal_reviewed_at', $profile->legal_reviewed_at?->toDateString()) }}"></label>
                        <label class="span-2"><span>Notas da configuração</span><textarea class="form-control" name="notes" rows="3" maxlength="5000">{{ old('notes', $profile->notes) }}</textarea></label>
                    </div>
                </fieldset>
                @if ($errors->any())<div class="rules-form-errors">{{ $errors->first() }}</div>@endif
            </form>

            <section class="rules-activation">
                <div><i data-lucide="badge-check" aria-hidden="true"></i><span><strong>Ativar revisão {{ $profile->version }}</strong><small>Depois de ativada, esta versão não poderá ser editada.</small></span></div>
                <form method="POST" action="{{ route('municipal-rules.activate', $profile) }}" data-prevent-double-submit>
                    @csrf
                    <button class="btn btn-primary" type="submit" @disabled($diagnostic['blockers'] !== [])><i data-lucide="shield-check" aria-hidden="true"></i>Ativar como vigente</button>
                </form>
                @error('activation')<p class="rules-form-errors">{{ $message }}</p>@enderror
            </section>
        @else
            <section class="rules-readonly">
                <div><span>Revisão jurídica</span><strong>{{ $profile->legal_review_reference ?: 'Não informada' }}</strong><small>{{ $profile->legal_review_responsible ?: 'Responsável não informado' }}{{ $profile->legal_reviewed_at ? ' · '.$profile->legal_reviewed_at->format('d/m/Y') : '' }}</small></div>
                <div><span>Atualização pública</span><strong>{{ is_null($profile->publication_business_days) ? 'Não definida' : $profile->publication_business_days.' dia(s) útil(eis)' }}</strong><small>Parâmetro local registrado</small></div>
                <div><span>Audesp</span><strong>{{ App\Models\MunicipalRegulatoryProfile::audespStatuses()[$profile->audesp_registration_status] }}</strong><small>{{ $profile->audespResponsible?->name ?: 'Sem responsável definido' }}</small></div>
            </section>
        @endif
    @endif
</div>
@endsection
