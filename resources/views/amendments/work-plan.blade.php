@extends('layouts.app')

@section('title', 'Plano de trabalho '.$amendment->reference.' | TrilhaGov')

@section('content')
    <a class="d-inline-block mb-3" href="{{ route('emendas.show', $amendment) }}">Voltar para a emenda</a>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
        <div>
            <p class="page-kicker mb-2">Planejamento municipal</p>
            <h1 class="h3 mb-1">Plano de trabalho</h1>
            <p class="text-secondary mb-0">Emenda {{ $amendment->reference }} · {{ $amendment->municipality->name }}/SP</p>
        </div>
        @if ($plan)
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="work-plan-status status-{{ $plan->status }}">{{ $plan->statusLabel() }}</span>
                <a class="btn btn-outline-primary" href="{{ route('emendas.work-plan.pdf', $amendment) }}">
                    <i data-lucide="file-down" aria-hidden="true"></i>Gerar PDF
                </a>
            </div>
        @endif
    </div>

    <nav class="amendment-tabs mb-4" aria-label="Seções da emenda">
        <a href="{{ route('emendas.show', $amendment) }}">Visão geral</a>
        <a class="active" href="{{ route('emendas.work-plan', $amendment) }}" aria-current="page">Plano de trabalho</a>
        <a href="{{ route('emendas.impediments', $amendment) }}">Impedimentos</a>
        <a href="{{ route('emendas.execution', $amendment) }}">Execução</a>
        <a href="{{ route('emendas.compliance', $amendment) }}">Conformidade TCESP</a>
        <a href="{{ route('emendas.accountability', $amendment) }}">Prestação de contas</a>
    </nav>

    <x-validation-summary />
    @error('work_plan')<div class="alert alert-danger" role="alert">{{ $message }}</div>@enderror

    @if (! $plan)
        <section class="content-panel work-plan-empty">
            <span><i data-lucide="clipboard-list" aria-hidden="true"></i></span>
            <div>
                <h2 class="h5">Inicie o planejamento da emenda</h2>
                <p>Organize beneficiário, necessidade pública, metas, custos e cronograma físico-financeiro antes da análise técnica.</p>
            </div>
            @if ($canEdit)
                <form method="POST" action="{{ route('emendas.work-plan.store', $amendment) }}">
                    @csrf
                    <input name="_submission_token" type="hidden" value="{{ $createToken }}">
                    <button class="btn btn-primary" type="submit"><i data-lucide="plus" aria-hidden="true"></i>Iniciar plano</button>
                </form>
            @endif
        </section>
    @else
        <section class="work-plan-overview mb-4" aria-label="Prontidão do plano de trabalho">
            <div class="work-plan-readiness">
                <strong>{{ $readiness['score'] }}%</strong>
                <div>
                    <span>Prontidão para análise</span>
                    <div class="work-plan-meter" role="progressbar" aria-valuenow="{{ $readiness['score'] }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $readiness['score'] }}%"></span></div>
                    <small>{{ $readiness['completed'] }} de {{ $readiness['total'] }} verificações concluídas</small>
                </div>
            </div>
            <div class="work-plan-overview-metrics">
                <div><small>Valor da emenda</small><strong>R$ {{ number_format($amendment->expected_amount, 2, ',', '.') }}</strong></div>
                <div><small>Total planejado</small><strong>R$ {{ number_format($readiness['planned_amount'], 2, ',', '.') }}</strong></div>
                <div class="{{ abs($readiness['difference']) >= 0.01 ? 'has-difference' : '' }}"><small>Diferença</small><strong>R$ {{ number_format($readiness['difference'], 2, ',', '.') }}</strong></div>
                <div><small>Revisão</small><strong>{{ $plan->revision_number > 0 ? 'R'.$plan->revision_number : 'Rascunho' }}</strong></div>
            </div>
        </section>

        @if ($readiness['blockers'] || $readiness['warnings'])
            <section class="work-plan-diagnostics mb-4">
                @if ($readiness['blockers'])
                    <div class="work-plan-blockers">
                        <div><i data-lucide="triangle-alert" aria-hidden="true"></i><strong>Pendências antes do envio</strong></div>
                        <ul>@foreach ($readiness['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
                    </div>
                @endif
                @if ($readiness['warnings'])
                    <div class="work-plan-warnings">
                        <div><i data-lucide="info" aria-hidden="true"></i><strong>Pontos para análise técnica</strong></div>
                        <ul>@foreach ($readiness['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul>
                    </div>
                @endif
            </section>
        @endif

        @if ($plan->status === App\Models\MunicipalWorkPlan::STATUS_ADJUSTMENTS_REQUESTED && $plan->reviews->first()?->corrections_requested)
            <div class="alert alert-warning" role="alert">
                <strong>Ajustes solicitados no parecer R{{ $plan->reviews->first()->plan_revision }}:</strong>
                <span style="white-space: pre-line">{{ $plan->reviews->first()->corrections_requested }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('emendas.work-plan.update', $amendment) }}" novalidate>
            @csrf
            @method('PATCH')
            <input name="_submission_token" type="hidden" value="{{ $updateToken }}">

            <fieldset @disabled(! $canEdit || ! $plan->isEditable())>
                <section class="content-panel mb-4">
                    <div class="content-panel-header work-plan-section-title"><span>1</span><div><h2 class="h5 mb-0">Beneficiário e objeto</h2><small>Quem executará e qual entrega será realizada</small></div></div>
                    <div class="content-panel-body work-plan-form-grid">
                        <div>
                            <label class="form-label" for="beneficiary_type">Tipo de beneficiário <span class="required-mark">*</span></label>
                            <select class="form-select" id="beneficiary_type" name="beneficiary_type" required>
                                @foreach ($beneficiaryTypes as $value => $label)<option value="{{ $value }}" @selected(old('beneficiary_type', $plan->beneficiary_type) === $value)>{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="beneficiary_name">Nome ou razão social <span class="required-mark">*</span></label>
                            <input class="form-control" id="beneficiary_name" name="beneficiary_name" value="{{ old('beneficiary_name', $plan->beneficiary_name) }}" maxlength="255" required>
                        </div>
                        <div>
                            <label class="form-label" for="beneficiary_cnpj">CNPJ</label>
                            <input class="form-control" id="beneficiary_cnpj" name="beneficiary_cnpj" value="{{ old('beneficiary_cnpj', $plan->beneficiary_cnpj) }}" inputmode="numeric" maxlength="18">
                            <div class="form-text">Obrigatório para beneficiário externo.</div>
                        </div>
                        <div>
                            <label class="form-label" for="beneficiary_contact">Contato responsável <span class="required-mark">*</span></label>
                            <input class="form-control" id="beneficiary_contact" name="beneficiary_contact" value="{{ old('beneficiary_contact', $plan->beneficiary_contact) }}" maxlength="255" required>
                        </div>
                        <div class="span-2">
                            <label class="form-label" for="object_description">Objeto detalhado <span class="required-mark">*</span></label>
                            <textarea class="form-control" id="object_description" name="object_description" rows="4" maxlength="5000" required>{{ old('object_description', $plan->object_description) }}</textarea>
                        </div>
                        <div class="span-2">
                            <label class="form-label" for="public_need">Justificativa e necessidade pública <span class="required-mark">*</span></label>
                            <textarea class="form-control" id="public_need" name="public_need" rows="4" maxlength="5000" required>{{ old('public_need', $plan->public_need) }}</textarea>
                        </div>
                    </div>
                </section>

                <section class="content-panel mb-4">
                    <div class="content-panel-header work-plan-section-title"><span>2</span><div><h2 class="h5 mb-0">Metas e orçamento</h2><small>O que será entregue e onde a despesa se enquadra</small></div></div>
                    <div class="content-panel-body work-plan-form-grid">
                        <div>
                            <label class="form-label" for="physical_target">Meta física <span class="required-mark">*</span></label>
                            <textarea class="form-control" id="physical_target" name="physical_target" rows="3" maxlength="3000" required>{{ old('physical_target', $plan->physical_target) }}</textarea>
                        </div>
                        <div>
                            <label class="form-label" for="finalistic_target">Meta finalística <span class="required-mark">*</span></label>
                            <textarea class="form-control" id="finalistic_target" name="finalistic_target" rows="3" maxlength="3000" required>{{ old('finalistic_target', $plan->finalistic_target) }}</textarea>
                        </div>
                        <div>
                            <label class="form-label" for="budget_program">Programa orçamentário <span class="required-mark">*</span></label>
                            <input class="form-control" id="budget_program" name="budget_program" value="{{ old('budget_program', $plan->budget_program) }}" maxlength="255" required>
                        </div>
                        <div>
                            <label class="form-label" for="budget_action">Ação orçamentária <span class="required-mark">*</span></label>
                            <input class="form-control" id="budget_action" name="budget_action" value="{{ old('budget_action', $plan->budget_action) }}" maxlength="255" required>
                        </div>
                        <div class="span-2">
                            <label class="form-label" for="application_plan">Plano de aplicação <span class="required-mark">*</span></label>
                            <textarea class="form-control" id="application_plan" name="application_plan" rows="3" maxlength="5000" required>{{ old('application_plan', $plan->application_plan) }}</textarea>
                        </div>
                        <div>
                            <label class="form-label" for="cost_memory">Memória de cálculo <span class="required-mark">*</span></label>
                            <textarea class="form-control" id="cost_memory" name="cost_memory" rows="4" maxlength="5000" required>{{ old('cost_memory', $plan->cost_memory) }}</textarea>
                        </div>
                        <div>
                            <label class="form-label" for="maintenance_plan">Operação e manutenção futura <span class="required-mark">*</span></label>
                            <textarea class="form-control" id="maintenance_plan" name="maintenance_plan" rows="4" maxlength="5000" required>{{ old('maintenance_plan', $plan->maintenance_plan) }}</textarea>
                        </div>
                    </div>
                </section>

                <section class="content-panel mb-4">
                    <div class="content-panel-header work-plan-section-title"><span>3</span><div><h2 class="h5 mb-0">Condições técnicas</h2><small>Saúde, engenharia, licença e planejamento de contratação</small></div></div>
                    <div class="content-panel-body work-plan-form-grid">
                        <label class="work-plan-toggle"><input type="checkbox" name="health_related" value="1" @checked(old('health_related', $plan->health_related))><span><strong>Objeto relacionado à saúde</strong><small>Marque somente para ação ou serviço público de saúde.</small></span></label>
                        <label class="work-plan-toggle"><input type="checkbox" name="health_reserve_verified" value="1" @checked(old('health_reserve_verified', $plan->health_reserve_verified))><span><strong>Reserva da saúde verificada</strong><small>Confirmação conforme a regulamentação municipal.</small></span></label>
                        <label class="work-plan-toggle span-2"><input type="checkbox" name="includes_engineering" value="1" @checked(old('includes_engineering', $plan->includes_engineering))><span><strong>Inclui obra ou serviço de engenharia</strong><small>Ativa o acompanhamento de projeto e licença.</small></span></label>
                        <div>
                            <label class="form-label" for="engineering_project_status">Projeto de engenharia</label>
                            <select class="form-select" id="engineering_project_status" name="engineering_project_status">@foreach($engineeringStatuses as $value => $label)<option value="{{ $value }}" @selected(old('engineering_project_status', $plan->engineering_project_status) === $value)>{{ $label }}</option>@endforeach</select>
                        </div>
                        <div>
                            <label class="form-label" for="environmental_license_status">Licença ambiental</label>
                            <select class="form-select" id="environmental_license_status" name="environmental_license_status">@foreach($engineeringStatuses as $value => $label)<option value="{{ $value }}" @selected(old('environmental_license_status', $plan->environmental_license_status) === $value)>{{ $label }}</option>@endforeach</select>
                        </div>
                        <div>
                            <label class="form-label" for="pca_status">Situação no PCA <span class="required-mark">*</span></label>
                            <select class="form-select" id="pca_status" name="pca_status" required>@foreach($pcaStatuses as $value => $label)<option value="{{ $value }}" @selected(old('pca_status', $plan->pca_status) === $value)>{{ $label }}</option>@endforeach</select>
                        </div>
                        <div class="work-plan-date-pair">
                            <div><label class="form-label" for="planned_start_at">Início <span class="required-mark">*</span></label><input class="form-control" id="planned_start_at" name="planned_start_at" type="date" value="{{ old('planned_start_at', $plan->planned_start_at?->toDateString()) }}" required></div>
                            <div><label class="form-label" for="planned_end_at">Fim <span class="required-mark">*</span></label><input class="form-control" id="planned_end_at" name="planned_end_at" type="date" value="{{ old('planned_end_at', $plan->planned_end_at?->toDateString()) }}" required></div>
                        </div>
                    </div>
                </section>
            </fieldset>

            @if ($canEdit && $plan->isEditable())
                <div class="d-flex justify-content-end mb-4"><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar plano</button></div>
            @endif
        </form>

        <section class="content-panel mb-4" id="cronograma">
            <div class="content-panel-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div><h2 class="h5 mb-0">Cronograma físico-financeiro</h2><small class="text-secondary">Etapas, entregas, períodos e desembolsos</small></div>
                @if ($canEdit && $plan->isEditable())<button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#newWorkPlanStage"><i data-lucide="plus" aria-hidden="true"></i>Nova etapa</button>@endif
            </div>

            @if ($canEdit && $plan->isEditable())
                <div class="collapse" id="newWorkPlanStage">
                    <form class="work-plan-stage-form" method="POST" action="{{ route('emendas.work-plan.stages.store', $amendment) }}">
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $stageCreateToken }}">
                        <input name="_form_context" type="hidden" value="stage-create">
                        <div><label class="form-label">Etapa <span class="required-mark">*</span></label><input class="form-control" name="title" maxlength="255" required></div>
                        <div><label class="form-label">Entrega física <span class="required-mark">*</span></label><input class="form-control" name="physical_delivery" maxlength="3000" required></div>
                        <div><label class="form-label">Valor planejado <span class="required-mark">*</span></label><input class="form-control" name="planned_amount" type="number" min="0.01" step="0.01" required></div>
                        <div><label class="form-label">Início <span class="required-mark">*</span></label><input class="form-control" name="planned_start_at" type="date" required></div>
                        <div><label class="form-label">Fim <span class="required-mark">*</span></label><input class="form-control" name="planned_end_at" type="date" required></div>
                        <div><label class="form-label">Ordem <span class="required-mark">*</span></label><input class="form-control" name="sort_order" type="number" value="{{ ($plan->stages->max('sort_order') ?? 0) + 10 }}" min="0" max="999" required></div>
                        <button class="btn btn-primary" type="submit"><i data-lucide="plus" aria-hidden="true"></i>Adicionar etapa</button>
                    </form>
                </div>
            @endif

            @if ($plan->stages->isEmpty())
                <div class="empty-state">Nenhuma etapa cadastrada no cronograma.</div>
            @else
                <div class="work-plan-stage-list">
                    @foreach ($plan->stages as $stage)
                        <details class="work-plan-stage-row" id="etapa-plano-{{ $stage->id }}">
                            <summary>
                                <span class="work-plan-stage-order">{{ $loop->iteration }}</span>
                                <span><strong>{{ $stage->title }}</strong><small>{{ $stage->physical_delivery }}</small></span>
                                <span>{{ $stage->planned_start_at->format('d/m/Y') }} a {{ $stage->planned_end_at->format('d/m/Y') }}</span>
                                <strong>R$ {{ number_format($stage->planned_amount, 2, ',', '.') }}</strong>
                                @if ($canEdit && $plan->isEditable())<i data-lucide="chevron-down" aria-hidden="true"></i>@endif
                            </summary>
                            @if ($canEdit && $plan->isEditable())
                                <div class="work-plan-stage-editor">
                                    <form class="work-plan-stage-form" method="POST" action="{{ route('emendas.work-plan.stages.update', [$amendment, $stage]) }}">
                                        @csrf @method('PATCH')
                                        <input name="_submission_token" type="hidden" value="{{ $stageUpdateTokens->get($stage->id) }}">
                                        <div><label class="form-label">Etapa</label><input class="form-control" name="title" value="{{ $stage->title }}" maxlength="255" required></div>
                                        <div><label class="form-label">Entrega física</label><input class="form-control" name="physical_delivery" value="{{ $stage->physical_delivery }}" maxlength="3000" required></div>
                                        <div><label class="form-label">Valor</label><input class="form-control" name="planned_amount" type="number" value="{{ $stage->planned_amount }}" min="0.01" step="0.01" required></div>
                                        <div><label class="form-label">Início</label><input class="form-control" name="planned_start_at" type="date" value="{{ $stage->planned_start_at->toDateString() }}" required></div>
                                        <div><label class="form-label">Fim</label><input class="form-control" name="planned_end_at" type="date" value="{{ $stage->planned_end_at->toDateString() }}" required></div>
                                        <div><label class="form-label">Ordem</label><input class="form-control" name="sort_order" type="number" value="{{ $stage->sort_order }}" min="0" max="999" required></div>
                                        <button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar etapa</button>
                                    </form>
                                    <form method="POST" action="{{ route('emendas.work-plan.stages.destroy', [$amendment, $stage]) }}">
                                        @csrf @method('DELETE')
                                        <input name="_submission_token" type="hidden" value="{{ $stageDeleteTokens->get($stage->id) }}">
                                        <button class="btn btn-sm btn-outline-danger" type="submit"><i data-lucide="trash-2" aria-hidden="true"></i>Remover etapa</button>
                                    </form>
                                </div>
                            @endif
                        </details>
                    @endforeach
                    <div class="work-plan-stage-total"><span>Total do cronograma</span><strong>R$ {{ number_format($readiness['planned_amount'], 2, ',', '.') }}</strong></div>
                </div>
            @endif
        </section>

        @if ($canEdit && $plan->isEditable())
            <section class="content-panel work-plan-submit mb-4 {{ $readiness['ready'] ? 'is-ready' : '' }}">
                <div><i data-lucide="send" aria-hidden="true"></i><div><h2 class="h5 mb-1">Enviar para análise técnica</h2><p>{{ $readiness['ready'] ? 'O plano passou pelas verificações de preenchimento e será bloqueado durante o parecer.' : 'Resolva as pendências indicadas antes de enviar o plano.' }}</p></div></div>
                <form method="POST" action="{{ route('emendas.work-plan.submit', $amendment) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $submitToken }}"><button class="btn btn-primary" type="submit" @disabled(! $readiness['ready'])><i data-lucide="send" aria-hidden="true"></i>Enviar revisão {{ $plan->revision_number + 1 }}</button></form>
            </section>
        @endif

        @if ($plan->status === App\Models\MunicipalWorkPlan::STATUS_UNDER_REVIEW)
            <section class="content-panel mb-4" id="parecer">
                <div class="content-panel-header"><h2 class="h5 mb-0">Parecer de admissibilidade · R{{ $plan->revision_number }}</h2><small class="text-secondary">Análise preliminar antes da aprovação legislativa</small></div>
                @if ($canReview)
                    <form class="admissibility-form" method="POST" action="{{ route('emendas.work-plan.review', $amendment) }}" novalidate>
                        @csrf
                        <input name="_submission_token" type="hidden" value="{{ $reviewToken }}">
                        <div class="admissibility-criteria">
                            @foreach ($criteria as $code => $criterion)
                                <div><span><strong>{{ $criterion['label'] }}</strong><small>{{ $criterion['guidance'] }}</small></span><select class="form-select" name="criteria[{{ $code }}]" required><option value="">Avaliar</option>@foreach($criterionStatuses as $value => $label)<option value="{{ $value }}" @selected(old("criteria.$code") === $value)>{{ $label }}</option>@endforeach</select></div>
                            @endforeach
                        </div>
                        <div class="admissibility-decision">
                            <div><label class="form-label" for="conclusion">Conclusão <span class="required-mark">*</span></label><select class="form-select" id="conclusion" name="conclusion" required><option value="">Selecione</option>@foreach($conclusions as $value => $label)<option value="{{ $value }}" @selected(old('conclusion') === $value)>{{ $label }}</option>@endforeach</select></div>
                            <div><label class="form-label" for="rationale">Fundamentação <span class="required-mark">*</span></label><textarea class="form-control" id="rationale" name="rationale" rows="4" maxlength="5000" required>{{ old('rationale') }}</textarea></div>
                            <div><label class="form-label" for="corrections_requested">Ajustes solicitados</label><textarea class="form-control" id="corrections_requested" name="corrections_requested" rows="4" maxlength="5000">{{ old('corrections_requested') }}</textarea></div>
                            <button class="btn btn-primary" type="submit"><i data-lucide="badge-check" aria-hidden="true"></i>Emitir parecer imutável</button>
                        </div>
                    </form>
                @else
                    <div class="work-plan-awaiting"><i data-lucide="clock-3" aria-hidden="true"></i><p>O plano está bloqueado aguardando parecer de um gestor municipal.</p></div>
                @endif
            </section>
        @endif

        @if ($plan->reviews->isNotEmpty())
            <section class="content-panel" id="historico-pareceres">
                <div class="content-panel-header"><h2 class="h5 mb-0">Histórico de pareceres</h2><small class="text-secondary">Registros preservados por revisão</small></div>
                <div class="admissibility-history">
                    @foreach ($plan->reviews as $review)
                        <details>
                            <summary><span><strong>Revisão {{ $review->plan_revision }}</strong><small>{{ $review->reviewer->name }} · {{ $review->created_at->format('d/m/Y H:i') }}</small></span><span class="work-plan-status status-{{ $review->conclusion }}">{{ $review->conclusionLabel() }}</span><i data-lucide="chevron-down" aria-hidden="true"></i></summary>
                            <div><h3 class="h6">Fundamentação</h3><p>{{ $review->rationale }}</p>@if($review->corrections_requested)<h3 class="h6">Ajustes solicitados</h3><p>{{ $review->corrections_requested }}</p>@endif<div class="admissibility-history-criteria">@foreach($criteria as $code => $criterion)<span><small>{{ $criterion['label'] }}</small><strong>{{ $criterionStatuses[$review->criteria[$code]] ?? $review->criteria[$code] }}</strong></span>@endforeach</div></div>
                        </details>
                    @endforeach
                </div>
            </section>
        @endif
    @endif
@endsection
