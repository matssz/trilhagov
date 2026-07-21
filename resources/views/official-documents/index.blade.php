@extends('layouts.app')

@section('title', 'Comunicações Oficiais - TrilhaGov')

@section('content')
<div class="official-documents-page">
    <header class="page-header official-page-header">
        <div>
            <p class="page-kicker mb-1">Expediente municipal</p>
            <h1 class="h3 mb-1">Comunicações oficiais</h1>
            <p class="text-secondary mb-0">{{ $municipality->name }}/{{ $municipality->state }}</p>
        </div>
        @if($canDraft && $activeTemplates->isNotEmpty())
            <a class="btn btn-primary" href="#nova-comunicacao"><i data-lucide="file-plus-2" aria-hidden="true"></i>Nova minuta</a>
        @endif
    </header>

    <section class="official-metrics" aria-label="Resumo das comunicações">
        <article><i data-lucide="file-text" aria-hidden="true"></i><span><small>Total</small><strong>{{ (int) $stats->total }}</strong></span></article>
        <article><i data-lucide="pencil" aria-hidden="true"></i><span><small>Minutas</small><strong>{{ (int) $stats->drafts }}</strong></span></article>
        <article><i data-lucide="send" aria-hidden="true"></i><span><small>Protocolados</small><strong>{{ (int) $stats->dispatched }}</strong></span></article>
        <article><i data-lucide="badge-check" aria-hidden="true"></i><span><small>Recebidos</small><strong>{{ (int) $stats->acknowledged }}</strong></span></article>
    </section>

    @if($activeTemplates->isEmpty())
        <section class="content-panel official-empty-setup">
            <div><i data-lucide="scroll-text" aria-hidden="true"></i><span><strong>Modelos municipais pendentes</strong><small>Ofício, notificação, diligência, despacho, parecer e termo de encaminhamento.</small></span></div>
            @if($canManage)
                <form method="POST" action="{{ route('official-document-templates.install') }}">@csrf<input name="_submission_token" type="hidden" value="{{ $installToken }}"><button class="btn btn-primary" type="submit"><i data-lucide="plus" aria-hidden="true"></i>Instalar modelos</button></form>
            @endif
        </section>
    @endif

    @if($canDraft && $activeTemplates->isNotEmpty())
        <section class="content-panel mb-4" id="nova-comunicacao">
            <div class="content-panel-header official-section-header"><div><p class="page-kicker mb-1">Geração assistida</p><h2 class="h5 mb-0">Nova minuta</h2></div><span class="official-step">01 · Preparar</span></div>
            <form class="official-create-form" method="POST" action="{{ route('official-documents.store') }}">
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $createToken }}">
                <div><label class="form-label" for="municipal_document_template_id">Modelo <span class="required-mark">*</span></label><select class="form-select @error('municipal_document_template_id') is-invalid @enderror" id="municipal_document_template_id" name="municipal_document_template_id" required><option value="">Selecione</option>@foreach($activeTemplates as $template)<option value="{{ $template->id }}" @selected((string) old('municipal_document_template_id') === (string) $template->id)>{{ $template->typeLabel() }} · v{{ $template->version }}</option>@endforeach</select>@error('municipal_document_template_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="fiscal_year">Exercício <span class="required-mark">*</span></label><input class="form-control @error('fiscal_year') is-invalid @enderror" id="fiscal_year" name="fiscal_year" type="number" min="2000" max="2100" value="{{ old('fiscal_year', now()->year) }}" required>@error('fiscal_year')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-2"><label class="form-label" for="parliamentary_amendment_id">Emenda vinculada</label><select class="form-select @error('parliamentary_amendment_id') is-invalid @enderror" id="parliamentary_amendment_id" name="parliamentary_amendment_id" data-official-amendment><option value="">Comunicação geral do Município</option>@foreach($amendments as $amendment)<option value="{{ $amendment->id }}" @selected((string) old('parliamentary_amendment_id', request('amendment')) === (string) $amendment->id)>{{ $amendment->reference }} · {{ $amendment->fiscal_year }} · {{ Str::limit($amendment->object, 70) }}</option>@endforeach</select>@error('parliamentary_amendment_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>

                <div><label class="form-label" for="recipient_name">Destinatário <span class="required-mark">*</span></label><input class="form-control @error('recipient_name') is-invalid @enderror" id="recipient_name" name="recipient_name" value="{{ old('recipient_name') }}" maxlength="180" required>@error('recipient_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="recipient_role">Cargo ou função</label><input class="form-control @error('recipient_role') is-invalid @enderror" id="recipient_role" name="recipient_role" value="{{ old('recipient_role') }}" maxlength="180">@error('recipient_role')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="recipient_entity">Órgão destinatário <span class="required-mark">*</span></label><input class="form-control @error('recipient_entity') is-invalid @enderror" id="recipient_entity" name="recipient_entity" value="{{ old('recipient_entity') }}" maxlength="180" required>@error('recipient_entity')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="recipient_email">E-mail institucional</label><input class="form-control @error('recipient_email') is-invalid @enderror" id="recipient_email" name="recipient_email" type="email" value="{{ old('recipient_email') }}" maxlength="180">@error('recipient_email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="response_due_at">Prazo de resposta</label><input class="form-control @error('response_due_at') is-invalid @enderror" id="response_due_at" name="response_due_at" type="date" value="{{ old('response_due_at') }}">@error('response_due_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>

                <fieldset class="span-2 official-source-fields"><legend>Origem estruturada</legend>
                    <div><label class="form-label" for="technical_impediment_id">Impedimento</label><select class="form-select @error('technical_impediment_id') is-invalid @enderror" id="technical_impediment_id" name="technical_impediment_id" data-official-source><option value="">Nenhum</option>@foreach($impediments as $item)<option value="{{ $item->id }}" data-amendment-id="{{ $item->parliamentary_amendment_id }}" @selected((string) old('technical_impediment_id', request('impediment')) === (string) $item->id)>{{ $item->title }}</option>@endforeach</select>@error('technical_impediment_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div><label class="form-label" for="technical_diligence_id">Diligência</label><select class="form-select @error('technical_diligence_id') is-invalid @enderror" id="technical_diligence_id" name="technical_diligence_id" data-official-source><option value="">Nenhuma</option>@foreach($diligences as $item)<option value="{{ $item->id }}" data-amendment-id="{{ $item->parliamentary_amendment_id }}" @selected((string) old('technical_diligence_id', request('diligence')) === (string) $item->id)>{{ $item->title }}</option>@endforeach</select>@error('technical_diligence_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="span-2"><label class="form-label" for="municipal_internal_control_review_id">Parecer do Controle Interno</label><select class="form-select @error('municipal_internal_control_review_id') is-invalid @enderror" id="municipal_internal_control_review_id" name="municipal_internal_control_review_id" data-official-source><option value="">Nenhum</option>@foreach($reviews as $item)<option value="{{ $item->id }}" data-amendment-id="{{ $item->parliamentary_amendment_id }}" @selected((string) old('municipal_internal_control_review_id') === (string) $item->id)>{{ $item->reference }} · {{ Str::limit($item->summary, 80) }}</option>@endforeach</select>@error('municipal_internal_control_review_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                </fieldset>
                <div class="span-2"><label class="form-label" for="context">Contexto complementar</label><textarea class="form-control @error('context') is-invalid @enderror" id="context" name="context" rows="3" maxlength="8000">{{ old('context') }}</textarea>@error('context')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-2"><label class="form-label" for="legal_basis">Fundamento informado</label><textarea class="form-control @error('legal_basis') is-invalid @enderror" id="legal_basis" name="legal_basis" rows="2" maxlength="3000">{{ old('legal_basis') }}</textarea>@error('legal_basis')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-2 official-form-actions"><span><i data-lucide="fingerprint" aria-hidden="true"></i>Versão do modelo e dados usados serão registrados.</span><button class="btn btn-primary" type="submit"><i data-lucide="sparkles" aria-hidden="true"></i>Gerar minuta</button></div>
            </form>
        </section>
    @endif

    <section class="content-panel mb-4">
        <div class="content-panel-header official-section-header"><div><p class="page-kicker mb-1">Expediente</p><h2 class="h5 mb-0">Documentos do Município</h2></div></div>
        <form class="official-filters" method="GET">
            <label><span class="visually-hidden">Pesquisar</span><div class="input-icon"><i data-lucide="search" aria-hidden="true"></i><input class="form-control" name="q" value="{{ request('q') }}" placeholder="Número, assunto ou destinatário"></div></label>
            <label><span class="visually-hidden">Tipo</span><select class="form-select" name="type"><option value="">Todos os tipos</option>@foreach(\App\Models\MunicipalOfficialDocument::types() as $value => $label)<option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label><span class="visually-hidden">Situação</span><select class="form-select" name="status"><option value="">Todas as situações</option>@foreach(\App\Models\MunicipalOfficialDocument::statuses() as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
            <button class="btn btn-outline-primary" type="submit"><i data-lucide="list-filter" aria-hidden="true"></i>Filtrar</button>
            @if(request()->hasAny(['q', 'type', 'status']))<a class="icon-button" href="{{ route('official-documents.index') }}" title="Limpar filtros" aria-label="Limpar filtros"><i data-lucide="filter-x" aria-hidden="true"></i></a>@endif
        </form>
        <div class="official-document-list">
            @forelse($documents as $document)
                <a href="{{ route('official-documents.show', $document) }}">
                    <span class="official-document-icon"><i data-lucide="file-text" aria-hidden="true"></i></span>
                    <span class="official-document-main"><small>{{ $document->typeLabel() }} · v{{ $document->version }}</small><strong>{{ $document->official_number ?: 'Minuta '.Str::upper(Str::substr($document->reference, 0, 8)) }}</strong><span>{{ $document->subject }}</span></span>
                    <span class="official-document-recipient"><small>Destinatário</small><strong>{{ $document->recipient_entity }}</strong><span>{{ $document->recipient_name }}</span></span>
                    <span class="official-status status-{{ $document->status }}">{{ $document->statusLabel() }}</span>
                    <i data-lucide="chevron-right" aria-hidden="true"></i>
                </a>
            @empty
                <div class="empty-state py-5">Nenhuma comunicação encontrada.</div>
            @endforelse
        </div>
        @if($documents->hasPages())<div class="content-panel-body border-top">{{ $documents->links() }}</div>@endif
    </section>

    @if($canManage && $activeTemplates->isNotEmpty())
        <section class="content-panel official-template-panel">
            <div class="content-panel-header official-section-header"><div><p class="page-kicker mb-1">Configuração municipal</p><h2 class="h5 mb-0">Modelos ativos</h2></div><span>{{ $activeTemplates->count() }} tipos</span></div>
            <div class="official-template-list">
                @foreach($activeTemplates as $template)
                    <details>
                        <summary><span><strong>{{ $template->typeLabel() }}</strong><small>{{ $template->name }} · {{ $template->prefix }} · versão {{ $template->version }}</small></span><i data-lucide="chevron-down" aria-hidden="true"></i></summary>
                        <form method="POST" action="{{ route('official-document-templates.revise', $template) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $templateTokens[$template->id] }}"><div><label class="form-label" for="template_name_{{ $template->id }}">Nome <span class="required-mark">*</span></label><input class="form-control" id="template_name_{{ $template->id }}" name="name" value="{{ $template->name }}" required maxlength="160"></div><div><label class="form-label" for="template_prefix_{{ $template->id }}">Prefixo <span class="required-mark">*</span></label><input class="form-control" id="template_prefix_{{ $template->id }}" name="prefix" value="{{ $template->prefix }}" required maxlength="12"></div><div class="span-2"><label class="form-label" for="template_subject_{{ $template->id }}">Assunto <span class="required-mark">*</span></label><input class="form-control" id="template_subject_{{ $template->id }}" name="subject_template" value="{{ $template->subject_template }}" required maxlength="2000"></div><div class="span-2"><label class="form-label" for="template_body_{{ $template->id }}">Corpo <span class="required-mark">*</span></label><textarea class="form-control" id="template_body_{{ $template->id }}" name="body_template" rows="9" required maxlength="30000">{{ $template->body_template }}</textarea></div><div class="span-2 official-template-actions"><small>Campos disponíveis: @foreach($placeholders as $key => $label)<code title="{{ $label }}">{{ '{'.'{'.$key.'}'.'}' }}</code>@endforeach</small><button class="btn btn-primary" type="submit"><i data-lucide="copy-plus" aria-hidden="true"></i>Criar nova versão</button></div></form>
                    </details>
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
