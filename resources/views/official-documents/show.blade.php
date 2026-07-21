@extends('layouts.app')

@section('title', ($document->official_number ?: 'Minuta').' - TrilhaGov')

@section('content')
<div class="official-document-detail">
    <header class="page-header official-page-header">
        <div><a class="back-link" href="{{ route('official-documents.index') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Comunicações</a><p class="page-kicker mb-1">{{ $document->typeLabel() }} · versão {{ $document->version }}</p><h1 class="h3 mb-1">{{ $document->official_number ?: 'Minuta '.Str::upper(Str::substr($document->reference, 0, 8)) }}</h1><p class="text-secondary mb-0">{{ $document->subject }}</p></div>
        <div class="official-header-actions"><span class="official-status status-{{ $document->status }}">{{ $document->statusLabel() }}</span><a class="btn btn-outline-primary" href="{{ route('official-documents.pdf', $document) }}"><i data-lucide="file-down" aria-hidden="true"></i>PDF</a></div>
    </header>

    <section class="official-flow" aria-label="Andamento do documento">
        @foreach([['draft', 'Minuta', 'pencil'], ['issued', 'Emissão', 'stamp'], ['sent', 'Protocolo', 'send'], ['acknowledged', 'Recebimento', 'badge-check']] as [$status, $label, $icon])
            @php $order = ['draft' => 0, 'issued' => 1, 'sent' => 2, 'acknowledged' => 3, 'rejected' => 3, 'cancelled' => 0]; $done = $order[$document->status] >= $order[$status] && $document->status !== 'cancelled'; @endphp
            <div class="{{ $done ? 'is-done' : '' }}"><span><i data-lucide="{{ $icon }}" aria-hidden="true"></i></span><small>{{ $label }}</small></div>
        @endforeach
    </section>

    <div class="official-detail-grid">
        <main>
            <section class="content-panel official-paper-panel">
                <div class="official-paper-meta"><span><small>Destinatário</small><strong>{{ $document->recipient_name }}</strong><em>{{ $document->recipient_role ?: 'Função não informada' }} · {{ $document->recipient_entity }}</em></span><span><small>Referência</small><strong>{{ $document->amendment?->reference ?: 'Comunicação geral' }}</strong><em>{{ $document->response_due_at ? 'Resposta até '.$document->response_due_at->format('d/m/Y') : 'Sem prazo de resposta' }}</em></span></div>
                <article class="official-paper"><h2>{{ $document->subject }}</h2><div>{!! nl2br(e($document->body)) !!}</div></article>
                @if($document->snapshot_sha256)<div class="official-hash"><i data-lucide="fingerprint" aria-hidden="true"></i><span><small>SHA-256 do conteúdo emitido</small><code>{{ $document->snapshot_sha256 }}</code></span></div>@endif
            </section>

            @if($canDraft && $document->isDraft())
                <section class="content-panel mt-4">
                    <div class="content-panel-header official-section-header"><div><p class="page-kicker mb-1">Revisão</p><h2 class="h5 mb-0">Editar minuta</h2></div></div>
                    <form class="official-edit-form" method="POST" action="{{ route('official-documents.update', $document) }}">@csrf @method('PATCH')<input name="_submission_token" type="hidden" value="{{ $updateToken }}">
                        <div><label class="form-label" for="recipient_name">Destinatário <span class="required-mark">*</span></label><input class="form-control" id="recipient_name" name="recipient_name" value="{{ old('recipient_name', $document->recipient_name) }}" required maxlength="180"></div><div><label class="form-label" for="recipient_role">Cargo ou função</label><input class="form-control" id="recipient_role" name="recipient_role" value="{{ old('recipient_role', $document->recipient_role) }}" maxlength="180"></div><div><label class="form-label" for="recipient_entity">Órgão <span class="required-mark">*</span></label><input class="form-control" id="recipient_entity" name="recipient_entity" value="{{ old('recipient_entity', $document->recipient_entity) }}" required maxlength="180"></div><div><label class="form-label" for="recipient_email">E-mail</label><input class="form-control" id="recipient_email" name="recipient_email" type="email" value="{{ old('recipient_email', $document->recipient_email) }}" maxlength="180"></div><div><label class="form-label" for="response_due_at">Prazo</label><input class="form-control" id="response_due_at" name="response_due_at" type="date" value="{{ old('response_due_at', $document->response_due_at?->toDateString()) }}"></div><div class="span-2"><label class="form-label" for="subject">Assunto <span class="required-mark">*</span></label><input class="form-control" id="subject" name="subject" value="{{ old('subject', $document->subject) }}" required maxlength="500"></div><div class="span-2"><label class="form-label" for="body">Corpo <span class="required-mark">*</span></label><textarea class="form-control" id="body" name="body" rows="15" required maxlength="30000">{{ old('body', $document->body) }}</textarea></div><div class="span-2 official-form-actions"><span><i data-lucide="history" aria-hidden="true"></i>A revisão ficará na linha do tempo.</span><button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Salvar minuta</button></div>
                    </form>
                </section>
            @endif

            <section class="content-panel mt-4">
                <div class="content-panel-header official-section-header"><div><p class="page-kicker mb-1">Rastreabilidade</p><h2 class="h5 mb-0">Linha do tempo</h2></div><span>Imutável</span></div>
                <div class="official-timeline">@foreach($document->events->sortByDesc('occurred_at') as $event)<article><span class="official-timeline-dot"><i data-lucide="{{ match($event->type) {'issued' => 'stamp', 'sent' => 'send', 'acknowledged' => 'badge-check', 'rejected' => 'circle-x', 'cancelled' => 'ban', default => 'history'} }}" aria-hidden="true"></i></span><div><header><strong>{{ $event->label() }}</strong><time>{{ $event->occurred_at->format('d/m/Y H:i') }}</time></header><p>{{ $event->message }}</p><small>{{ $event->creator->name }}@if($event->protocol_number) · Protocolo {{ $event->protocol_number }}@endif</small>@if($event->evidence_storage_path)<a href="{{ route('official-documents.evidence', [$document, $event]) }}"><i data-lucide="paperclip" aria-hidden="true"></i>{{ $event->evidence_original_name }}</a>@endif @if($event->evidence_sha256)<code>{{ $event->evidence_sha256 }}</code>@endif</div></article>@endforeach</div>
            </section>
        </main>

        <aside class="official-actions-column">
            <section class="content-panel official-context-panel"><div class="content-panel-header"><h2 class="h6 mb-0">Vínculos</h2></div><dl><div><dt>Modelo</dt><dd>{{ $document->template->name }} · v{{ $document->template->version }}</dd></div><div><dt>Criado por</dt><dd>{{ $document->creator->name }}</dd></div>@if($document->amendment)<div><dt>Emenda</dt><dd><a href="{{ route('emendas.show', $document->amendment) }}">{{ $document->amendment->reference }}</a></dd></div>@endif @if($document->impediment)<div><dt>Impedimento</dt><dd>{{ $document->impediment->title }}</dd></div>@endif @if($document->diligence)<div><dt>Diligência</dt><dd>{{ $document->diligence->title }}</dd></div>@endif @if($document->internalControlReview)<div><dt>Parecer</dt><dd>{{ $document->internalControlReview->reference }}</dd></div>@endif @if($document->protocol_number)<div><dt>Protocolo</dt><dd>{{ $document->protocol_number }}</dd></div>@endif</dl></section>

            @if($canManage && $document->isDraft())
                <section class="content-panel official-action-panel"><h2>Emitir documento</h2><form method="POST" action="{{ route('official-documents.issue', $document) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $issueToken }}"><label class="official-confirm"><input name="confirm_content" type="checkbox" value="1" required><span>Conteúdo e destinatário conferidos.</span></label><button class="btn btn-primary w-100" type="submit"><i data-lucide="stamp" aria-hidden="true"></i>Numerar e emitir</button></form></section>
                <section class="content-panel official-action-panel is-danger"><details><summary><i data-lucide="ban" aria-hidden="true"></i>Cancelar minuta</summary><form method="POST" action="{{ route('official-documents.cancel', $document) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $cancelToken }}"><label class="form-label" for="reason">Justificativa</label><textarea class="form-control" id="reason" name="reason" rows="3" required minlength="5"></textarea><button class="btn btn-outline-danger w-100" type="submit">Confirmar cancelamento</button></form></details></section>
            @endif

            @if($canDraft && $document->status === 'issued')
                <section class="content-panel official-action-panel"><h2>Protocolar envio</h2><form method="POST" action="{{ route('official-documents.send', $document) }}" enctype="multipart/form-data">@csrf<input name="_submission_token" type="hidden" value="{{ $sendToken }}"><label><span class="form-label">Meio <span class="required-mark">*</span></span><select class="form-select" name="delivery_method" required><option value="">Selecione</option>@foreach(\App\Models\MunicipalOfficialDocument::deliveryMethods() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label><label><span class="form-label">Protocolo <span class="required-mark">*</span></span><input class="form-control" name="protocol_number" required maxlength="160"></label><label><span class="form-label">Data e hora <span class="required-mark">*</span></span><input class="form-control" name="sent_at" type="datetime-local" value="{{ now()->format('Y-m-d\TH:i') }}" required></label><label><span class="form-label">Comprovante <span class="required-mark">*</span></span><input class="form-control" name="evidence" type="file" accept=".pdf,.txt,.csv,.jpg,.jpeg,.png,.eml,.msg" required></label><label><span class="form-label">Observação</span><textarea class="form-control" name="message" rows="2" maxlength="3000"></textarea></label><button class="btn btn-primary w-100" type="submit"><i data-lucide="send" aria-hidden="true"></i>Registrar protocolo</button></form></section>
            @endif

            @if($canDraft && $document->status === 'sent')
                <section class="content-panel official-action-panel"><h2>Registrar retorno</h2><form method="POST" action="{{ route('official-documents.return', $document) }}" enctype="multipart/form-data">@csrf<input name="_submission_token" type="hidden" value="{{ $returnToken }}"><label><span class="form-label">Resultado <span class="required-mark">*</span></span><select class="form-select" name="result" required><option value="acknowledged">Recebimento confirmado</option><option value="rejected">Documento devolvido</option></select></label><label><span class="form-label">Data e hora <span class="required-mark">*</span></span><input class="form-control" name="occurred_at" type="datetime-local" value="{{ now()->format('Y-m-d\TH:i') }}" required></label><label><span class="form-label">Protocolo complementar</span><input class="form-control" name="protocol_number" maxlength="160"></label><label><span class="form-label">Comprovante <span class="required-mark">*</span></span><input class="form-control" name="evidence" type="file" accept=".pdf,.txt,.jpg,.jpeg,.png,.eml,.msg" required></label><label><span class="form-label">Observação</span><textarea class="form-control" name="message" rows="3" maxlength="5000"></textarea></label><button class="btn btn-primary w-100" type="submit"><i data-lucide="badge-check" aria-hidden="true"></i>Registrar retorno</button></form></section>
            @endif

            @if($canDraft && ! $document->isDraft())
                <section class="content-panel official-action-panel"><h2>Versionamento</h2><form method="POST" action="{{ route('official-documents.revise', $document) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $revisionToken }}"><button class="btn btn-outline-primary w-100" type="submit"><i data-lucide="copy-plus" aria-hidden="true"></i>Preparar nova versão</button></form>@if($document->supersedes)<a class="official-version-link" href="{{ route('official-documents.show', $document->supersedes) }}"><i data-lucide="history" aria-hidden="true"></i>Versão anterior: {{ $document->supersedes->official_number }}</a>@endif @foreach($document->revisions as $revision)<a class="official-version-link" href="{{ route('official-documents.show', $revision) }}"><i data-lucide="arrow-right" aria-hidden="true"></i>Versão {{ $revision->version }} · {{ $revision->statusLabel() }}</a>@endforeach</section>
            @endif
        </aside>
    </div>
</div>
@endsection
