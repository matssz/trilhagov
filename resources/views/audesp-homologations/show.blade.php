@extends('layouts.app')

@section('title', 'Lote Audesp ' . $batch->reference . ' | TrilhaGov')

@section('content')
    @php
        $stageIndex = match ($batch->status) {
            \App\Models\AudespHomologationBatch::STATUS_UNDER_REVIEW => 1,
            \App\Models\AudespHomologationBatch::STATUS_READY => 2,
            \App\Models\AudespHomologationBatch::STATUS_SUBMITTED, \App\Models\AudespHomologationBatch::STATUS_RECEIVED, \App\Models\AudespHomologationBatch::STATUS_VALIDATED => 3,
            \App\Models\AudespHomologationBatch::STATUS_REJECTED, \App\Models\AudespHomologationBatch::STATUS_STORED => 4,
            default => 1,
        };
        $rejected = $batch->status === \App\Models\AudespHomologationBatch::STATUS_REJECTED;
        $isFinancial = $batch->source_document_type === \App\Models\AudespHomologationBatch::TYPE_MONTHLY_FINANCIAL;
        $financialLabels = [
            'pre_commitment_amount' => 'Pré-empenho / reserva',
            'committed_amount' => 'Empenhado líquido',
            'liquidated_amount' => 'Liquidado líquido',
            'paid_amount' => 'Pago líquido',
        ];
    @endphp

    <a class="back-link mb-3" href="{{ route('audesp-homologations.index') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Voltar para homologações</a>

    <div class="homologation-heading mb-4">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <p class="page-kicker mb-0">Lote {{ Str::limit($batch->reference, 18, '') }}</p>
                <span class="homologation-status status-{{ $batch->status }}">{{ $batch->statusLabel() }}</span>
                <span class="small text-secondary">{{ $batch->documentTypeLabel() }}</span>
            </div>
            <h1 class="h3 mb-1">{{ str_pad((string) $batch->reference_month, 2, '0', STR_PAD_LEFT) }}/{{ $batch->fiscal_year }} · {{ $batch->source_system }}</h1>
            <p class="text-secondary mb-0">{{ $batch->source_original_name }} · SHA-256 {{ Str::limit($batch->source_sha256, 16, '') }}</p>
        </div>
        <div class="homologation-heading-actions">
            <a class="btn btn-outline-primary" href="{{ route('audesp-homologations.report', $batch) }}"><i data-lucide="file-spreadsheet" aria-hidden="true"></i>Conferência CSV</a>
            <a class="btn btn-outline-primary" href="{{ route('audesp-homologations.source', $batch) }}"><i data-lucide="download" aria-hidden="true"></i>XML original</a>
        </div>
    </div>

    <x-validation-summary />

    <ol class="homologation-flow mb-4" aria-label="Etapas da homologação">
        @foreach ([['file-input', 'Arquivo do Siafic'], ['git-compare-arrows', 'Conferência local'], ['send', 'Coletor Audesp'], [$rejected ? 'circle-alert' : 'badge-check', 'Retorno TCESP']] as $index => [$icon, $label])
            <li class="{{ $stageIndex > $index ? 'is-complete' : '' }} {{ $stageIndex === $index + 1 ? 'is-current' : '' }} {{ $rejected && $index === 3 ? 'has-risk' : '' }}"><span><i data-lucide="{{ $icon }}" aria-hidden="true"></i></span><strong>{{ $label }}</strong></li>
        @endforeach
    </ol>

    @if ($rejected)
        <div class="homologation-alert is-danger mb-4"><i data-lucide="shield-alert" aria-hidden="true"></i><div><strong>Retorno rejeitado preservado</strong><p>Corrija o cadastro ou o XML com a contabilidade e crie um novo lote vinculando esta tentativa. O arquivo, o protocolo e a evidência originais não serão substituídos.</p></div><a class="btn btn-outline-danger" href="{{ route('audesp-homologations.index') }}#novo-lote">Criar reenvio</a></div>
    @elseif ($batch->status === \App\Models\AudespHomologationBatch::STATUS_STORED)
        <div class="homologation-alert is-success mb-4"><i data-lucide="badge-check" aria-hidden="true"></i><div><strong>Documento informado como armazenado</strong><p>A evidência anexada encerra esta tentativa no TrilhaGov. Conserve também a consulta oficial no ambiente Audesp.</p></div></div>
    @endif

    <div class="homologation-metrics mb-4">
        <article><span><i data-lucide="file-text" aria-hidden="true"></i></span><div><small>{{ $isFinancial ? 'Emendas conciliadas' : 'Registros no XML' }}</small><strong>{{ $batch->item_count }}</strong></div></article>
        <article class="is-success"><span><i data-lucide="circle-check" aria-hidden="true"></i></span><div><small>Coincidentes</small><strong>{{ $batch->matched_count }}</strong></div></article>
        <article class="{{ $batch->divergent_count ? 'has-risk' : '' }}"><span><i data-lucide="git-compare-arrows" aria-hidden="true"></i></span><div><small>Divergentes</small><strong>{{ $batch->divergent_count }}</strong></div></article>
        <article class="{{ $batch->unmatched_count ? 'has-risk' : '' }}"><span><i data-lucide="link-2" aria-hidden="true"></i></span><div><small>Sem vínculo</small><strong>{{ $batch->unmatched_count }}</strong></div></article>
    </div>

    <section class="content-panel mb-4">
        <div class="content-panel-header homologation-panel-header">
            <div class="d-flex align-items-center gap-2"><i data-lucide="scan-search" aria-hidden="true"></i><h2 class="h5 mb-0">{{ $isFinancial ? 'Execução financeira da emenda' : 'Conferência Siafic × TrilhaGov' }}</h2></div>
            @if ($canEdit && $batch->isEditable())
                <form method="POST" action="{{ route('audesp-homologations.recheck', $batch) }}">@csrf<input name="_submission_token" type="hidden" value="{{ $recheckToken }}"><button class="btn btn-sm btn-outline-primary" type="submit"><i data-lucide="refresh-cw" aria-hidden="true"></i>Reconferir</button></form>
            @else
                <span class="small text-secondary">Snapshot preservado no lote</span>
            @endif
        </div>
        <div class="homologation-items">
            @foreach ($batch->items as $item)
                <details class="homologation-item status-{{ $item->status }}" @if ($item->status !== 'matched') open @endif>
                    <summary>
                        <span class="item-result"><i data-lucide="{{ $item->status === 'matched' ? 'circle-check' : ($item->status === 'unmatched' ? 'link-2' : 'git-compare-arrows') }}" aria-hidden="true"></i></span>
                        <span><strong>@if ($isFinancial)Código {{ $item->source_snapshot['application_code'] ?? 'não informado' }}@else{{ $item->source_amendment_number ?: 'Sem número no XML' }}/{{ $item->source_amendment_year ?: '—' }}@endif</strong><small>{{ $item->amendment ? 'Vinculada a '.$item->amendment->reference : 'Cadastro não localizado no município' }}</small></span>
                        <span class="item-result-label">{{ $item->status === 'matched' ? 'Coincidente' : ($item->status === 'unmatched' ? 'Sem vínculo' : count($item->differences ?? []).' divergência(s)') }}</span>
                        <i data-lucide="chevron-down" aria-hidden="true"></i>
                    </summary>
                    @if ($item->status === 'unmatched')
                        <p class="homologation-item-note">{{ $isFinancial ? 'Cadastre ou revise o Código de Aplicação da emenda. O TrilhaGov não cria vínculos financeiros por semelhança de nome ou valor.' : 'Crie ou revise o cadastro Audesp no TrilhaGov usando o mesmo âmbito, número e exercício informados pelo Siafic.' }}</p>
                    @elseif ($item->differences)
                        <div class="homologation-differences">
                            <div class="difference-head"><span>Campo</span><span>Siafic</span><span>TrilhaGov</span></div>
                            @foreach ($item->differences as $difference)
                                <div><strong>{{ $difference['label'] }}</strong><span data-label="Siafic">{{ is_array($difference['source']) ? implode(', ', $difference['source']) : ($difference['source'] ?: 'Não informado') }}</span><span data-label="TrilhaGov">{{ is_array($difference['local']) ? implode(', ', $difference['local']) : ($difference['local'] ?: 'Não informado') }}</span></div>
                            @endforeach
                        </div>
                        @if ($item->amendment)<a class="difference-action" href="{{ $isFinancial ? route('emendas.execution', $item->amendment) : route('emendas.audesp', $item->amendment) }}"><i data-lucide="pencil" aria-hidden="true"></i>{{ $isFinancial ? 'Revisar execução da emenda' : 'Revisar cadastro da emenda' }}</a>@endif
                    @elseif ($isFinancial)
                        <div class="homologation-differences">
                            <div class="difference-head"><span>Etapa</span><span>Siafic</span><span>TrilhaGov</span></div>
                            @foreach ($financialLabels as $field => $label)
                                <div><strong>{{ $label }}</strong><span data-label="Siafic">R$ {{ number_format((float) ($item->source_snapshot[$field] ?? 0), 2, ',', '.') }}</span><span data-label="TrilhaGov">R$ {{ number_format((float) ($item->local_snapshot[$field] ?? 0), 2, ',', '.') }}</span></div>
                            @endforeach
                        </div>
                        <p class="homologation-item-note is-success">Os movimentos líquidos da competência coincidem pelo Código de Aplicação.</p>
                    @else
                        <p class="homologation-item-note is-success">Todos os campos controlados coincidem com o cadastro municipal preparado no TrilhaGov.</p>
                    @endif
                </details>
            @endforeach
        </div>
    </section>

    @if ($canEdit && $batch->status === \App\Models\AudespHomologationBatch::STATUS_READY)
        <section class="content-panel mb-4">
            <div class="content-panel-header homologation-panel-header"><div class="d-flex align-items-center gap-2"><i data-lucide="send" aria-hidden="true"></i><h2 class="h5 mb-0">Registrar transmissão externa</h2></div><span class="small text-secondary">Operação realizada no Coletor Audesp</span></div>
            <form class="homologation-event-form" method="POST" action="{{ route('audesp-homologations.submission', $batch) }}" enctype="multipart/form-data" novalidate>
                @csrf<input name="_submission_token" type="hidden" value="{{ $submissionToken }}">
                <div><label class="form-label" for="external_protocol">Identificador ou protocolo <span class="required-mark">*</span></label><input class="form-control @error('external_protocol') is-invalid @enderror" id="external_protocol" name="external_protocol" value="{{ old('external_protocol') }}" maxlength="160" required>@error('external_protocol')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="submitted_at">Data e hora <span class="required-mark">*</span></label><input class="form-control @error('submitted_at') is-invalid @enderror" id="submitted_at" name="submitted_at" type="datetime-local" value="{{ old('submitted_at', now()->format('Y-m-d\TH:i')) }}" required>@error('submitted_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="submission_evidence">Evidência inicial</label><input class="form-control @error('evidence') is-invalid @enderror" id="submission_evidence" name="evidence" type="file" accept=".pdf,.xml,.txt,.csv,.jpg,.jpeg,.png">@error('evidence')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-3"><label class="form-label" for="submission_message">Observações</label><textarea class="form-control" id="submission_message" name="message" rows="2" maxlength="2000">{{ old('message') }}</textarea></div>
                <button class="btn btn-primary" type="submit"><i data-lucide="send" aria-hidden="true"></i>Confirmar transmissão</button>
            </form>
        </section>
    @endif

    @if ($canEdit && $returnToken)
        <section class="content-panel mb-4">
            <div class="content-panel-header homologation-panel-header"><div class="d-flex align-items-center gap-2"><i data-lucide="receipt-text" aria-hidden="true"></i><h2 class="h5 mb-0">Registrar retorno do Audesp</h2></div><span class="small text-secondary">Sempre com evidência anexada</span></div>
            <form class="homologation-event-form" method="POST" action="{{ route('audesp-homologations.return', $batch) }}" enctype="multipart/form-data" novalidate>
                @csrf<input name="_submission_token" type="hidden" value="{{ $returnToken }}">
                <div><label class="form-label" for="external_status">Situação consultada <span class="required-mark">*</span></label><select class="form-select @error('external_status') is-invalid @enderror" id="external_status" name="external_status" required><option value="">Selecione</option>@foreach ($availableExternalStatuses as $value => $label)<option value="{{ $value }}" @selected(old('external_status') === $value)>{{ $label }}</option>@endforeach</select>@error('external_status')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="occurred_at">Data e hora <span class="required-mark">*</span></label><input class="form-control @error('occurred_at') is-invalid @enderror" id="occurred_at" name="occurred_at" type="datetime-local" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" required>@error('occurred_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="return_protocol">Protocolo atualizado</label><input class="form-control @error('protocol') is-invalid @enderror" id="return_protocol" name="protocol" value="{{ old('protocol', $batch->external_protocol) }}" maxlength="160">@error('protocol')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="issue_code">Código da rejeição</label><input class="form-control @error('issue_code') is-invalid @enderror" id="issue_code" name="issue_code" value="{{ old('issue_code') }}" maxlength="100">@error('issue_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="issue_field">Campo ou caminho</label><input class="form-control @error('issue_field') is-invalid @enderror" id="issue_field" name="issue_field" value="{{ old('issue_field') }}" maxlength="160">@error('issue_field')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="return_evidence">Recibo ou retorno <span class="required-mark">*</span></label><input class="form-control @error('evidence') is-invalid @enderror" id="return_evidence" name="evidence" type="file" accept=".pdf,.xml,.txt,.csv,.jpg,.jpeg,.png" required>@error('evidence')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-3"><label class="form-label" for="return_message">Descrição do retorno</label><textarea class="form-control @error('message') is-invalid @enderror" id="return_message" name="message" rows="3" maxlength="5000">{{ old('message') }}</textarea>@error('message')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <button class="btn btn-primary" type="submit"><i data-lucide="save" aria-hidden="true"></i>Registrar retorno</button>
            </form>
        </section>
    @endif

    <section class="content-panel">
        <div class="content-panel-header homologation-panel-header"><div class="d-flex align-items-center gap-2"><i data-lucide="history" aria-hidden="true"></i><h2 class="h5 mb-0">Trilha de evidências</h2></div><span class="small text-secondary">Eventos preservados em ordem cronológica</span></div>
        <div class="homologation-timeline">
            @foreach ($batch->events as $event)
                <article>
                    <span class="timeline-icon"><i data-lucide="{{ $event->type === 'rejection_recorded' ? 'circle-alert' : ($event->external_status === 'stored' ? 'badge-check' : 'circle-dot') }}" aria-hidden="true"></i></span>
                    <div><div class="timeline-heading"><strong>{{ match ($event->type) { 'source_imported' => 'Arquivo do Siafic importado', 'source_rechecked' => 'Conferência refeita', 'submission_recorded' => 'Transmissão registrada', 'rejection_recorded' => 'Rejeição registrada', default => $externalStatuses[$event->external_status] ?? 'Retorno externo registrado' } }}</strong><time>{{ $event->occurred_at->format('d/m/Y H:i') }}</time></div><p>{{ $event->message }}</p><small>Registrado por {{ $event->creator->name }}@if ($event->protocol) · Protocolo {{ $event->protocol }}@endif @if ($event->issue_code) · Código {{ $event->issue_code }}@endif</small></div>
                    @if ($event->evidence_storage_path)<a class="btn btn-sm btn-outline-primary" href="{{ route('audesp-homologations.evidence', [$batch, $event]) }}"><i data-lucide="paperclip" aria-hidden="true"></i>Evidência</a>@endif
                </article>
            @endforeach
        </div>
    </section>
@endsection
