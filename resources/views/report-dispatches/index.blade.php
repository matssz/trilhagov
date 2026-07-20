@extends('layouts.app')

@section('title', 'Remessas '.$report->code().' | TrilhaGov')

@section('content')
    @php
        $counts = $dispatches->countBy('status');
        $openCount = $dispatches->whereIn('status', [\App\Models\MunicipalReportDispatch::STATUS_PREPARED, \App\Models\MunicipalReportDispatch::STATUS_SENT])->count();
    @endphp
    <div class="dispatch-heading mb-4">
        <div>
            <a class="back-link" href="{{ route('governance-reports.show', $report) }}"><i data-lucide="arrow-left" aria-hidden="true"></i>{{ $report->code() }}</a>
            <p class="page-kicker mt-3 mb-2">Relação institucional entre órgãos municipais</p>
            <h1 class="h3 mb-1">Protocolo de remessas</h1>
            <p class="text-secondary mb-0">Câmara Municipal, Controle Interno, Contabilidade e demais destinatários da competência {{ $report->periodLabel() }}.</p>
        </div>
        <a class="btn btn-outline-primary" href="https://www.tce.sp.gov.br/legislacao/comunicado/comunicado-gp-462025" target="_blank" rel="noopener noreferrer"><i data-lucide="external-link" aria-hidden="true"></i>Comunicado GP 46/2025</a>
    </div>

    <x-validation-summary />

    <div class="dispatch-report-band mb-4">
        <span><i data-lucide="badge-check" aria-hidden="true"></i></span>
        <div><small>Relatório emitido</small><strong>{{ $report->code() }}</strong><p>Versão fechada em {{ $report->issued_at->format('d/m/Y H:i') }} · SHA-256 {{ substr($report->snapshot_sha256, 0, 16) }}…</p></div>
        <a href="{{ route('governance-reports.pdf', $report) }}"><i data-lucide="file-down" aria-hidden="true"></i>Documento original</a>
    </div>

    <div class="dispatch-metrics mb-4">
        <article><small>Remessas</small><strong>{{ $dispatches->count() }}</strong><span>Todos os destinatários</span></article>
        <article class="{{ $openCount > 0 ? 'has-warning' : '' }}"><small>Em andamento</small><strong>{{ $openCount }}</strong><span>Preparadas ou enviadas</span></article>
        <article><small>Recebidas</small><strong>{{ (int) ($counts[\App\Models\MunicipalReportDispatch::STATUS_ACKNOWLEDGED] ?? 0) }}</strong><span>Confirmação preservada</span></article>
        <article class="{{ (int) ($counts[\App\Models\MunicipalReportDispatch::STATUS_REJECTED] ?? 0) > 0 ? 'has-risk' : '' }}"><small>Devolvidas</small><strong>{{ (int) ($counts[\App\Models\MunicipalReportDispatch::STATUS_REJECTED] ?? 0) }}</strong><span>Exigem nova tentativa</span></article>
    </div>

    @if ($canEdit)
        <section class="content-panel mb-4" id="nova-remessa">
            <div class="content-panel-header dispatch-panel-header"><div><p class="page-kicker mb-1">Novo destinatário</p><h2 class="h5 mb-0">Preparar remessa municipal</h2></div><span class="small text-secondary">Prazo definido pela regra ou rotina local</span></div>
            <form class="dispatch-create-form" method="POST" action="{{ route('report-dispatches.store', $report) }}">
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $createToken }}">
                <div><label class="form-label" for="recipient_type">Tipo de destinatário <span class="required-mark">*</span></label><select class="form-select @error('recipient_type') is-invalid @enderror" id="recipient_type" name="recipient_type" required><option value="">Selecione</option>@foreach(\App\Models\MunicipalReportDispatch::recipientTypes() as $value => $label)<option value="{{ $value }}" @selected(old('recipient_type', $retryTemplate?->recipient_type) === $value)>{{ $label }}</option>@endforeach</select>@error('recipient_type')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-2"><label class="form-label" for="recipient_name">Órgão destinatário <span class="required-mark">*</span></label><input class="form-control @error('recipient_name') is-invalid @enderror" id="recipient_name" name="recipient_name" value="{{ old('recipient_name', $retryTemplate?->recipient_name) }}" maxlength="180" placeholder="Ex.: Câmara Municipal de ..." required>@error('recipient_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="recipient_unit">Unidade / setor</label><input class="form-control @error('recipient_unit') is-invalid @enderror" id="recipient_unit" name="recipient_unit" value="{{ old('recipient_unit', $retryTemplate?->recipient_unit) }}" maxlength="180" placeholder="Ex.: Secretaria Legislativa">@error('recipient_unit')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="delivery_method">Meio formal <span class="required-mark">*</span></label><select class="form-select @error('delivery_method') is-invalid @enderror" id="delivery_method" name="delivery_method" required><option value="">Selecione</option>@foreach(\App\Models\MunicipalReportDispatch::deliveryMethods() as $value => $label)<option value="{{ $value }}" @selected(old('delivery_method', $retryTemplate?->delivery_method) === $value)>{{ $label }}</option>@endforeach</select>@error('delivery_method')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="recipient_email">E-mail institucional</label><input class="form-control @error('recipient_email') is-invalid @enderror" id="recipient_email" name="recipient_email" type="email" value="{{ old('recipient_email', $retryTemplate?->recipient_email) }}" maxlength="180">@error('recipient_email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="due_at">Prazo municipal <span class="required-mark">*</span></label><input class="form-control @error('due_at') is-invalid @enderror" id="due_at" name="due_at" type="date" value="{{ old('due_at') }}" required>@error('due_at')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="responsible_user_id">Responsável interno</label><select class="form-select @error('responsible_user_id') is-invalid @enderror" id="responsible_user_id" name="responsible_user_id"><option value="">Gestores do Município</option>@foreach($operationalUsers as $user)<option value="{{ $user->id }}" @selected((string) old('responsible_user_id', $retryTemplate?->responsible_user_id) === (string) $user->id)>{{ $user->name }}</option>@endforeach</select>@error('responsible_user_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="span-2"><label class="form-label" for="legal_basis">Referência local do prazo</label><input class="form-control @error('legal_basis') is-invalid @enderror" id="legal_basis" name="legal_basis" value="{{ old('legal_basis', $retryTemplate?->legal_basis) }}" maxlength="500" placeholder="Lei Orgânica, LDO, Regimento, decreto ou fluxo interno">@error('legal_basis')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                @if ($rejectedDispatches->isNotEmpty())<div class="span-2"><label class="form-label" for="retry_of_id">Tentativa devolvida</label><select class="form-select @error('retry_of_id') is-invalid @enderror" id="retry_of_id" name="retry_of_id"><option value="">Primeira tentativa</option>@foreach($rejectedDispatches as $rejected)<option value="{{ $rejected->id }}" @selected((string) old('retry_of_id', request('retry_of')) === (string) $rejected->id)>{{ $rejected->recipient_name }} · {{ $rejected->code() }}</option>@endforeach</select>@error('retry_of_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>@endif
                <div class="span-3"><label class="form-label" for="notes">Observações</label><textarea class="form-control @error('notes') is-invalid @enderror" id="notes" name="notes" rows="2" maxlength="3000">{{ old('notes') }}</textarea>@error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <button class="btn btn-primary" type="submit"><i data-lucide="send" aria-hidden="true"></i>Preparar remessa</button>
            </form>
        </section>
    @endif

    <section class="content-panel">
        <div class="content-panel-header dispatch-panel-header"><div class="d-flex align-items-center gap-2"><i data-lucide="history" aria-hidden="true"></i><h2 class="h5 mb-0">Histórico por destinatário</h2></div><span class="small text-secondary">{{ $dispatches->count() }} registro(s)</span></div>
        @forelse($dispatches as $dispatch)
            <article class="dispatch-list-row {{ $dispatch->isOverdue() ? 'is-overdue' : '' }}">
                <span class="dispatch-recipient-icon"><i data-lucide="{{ $dispatch->recipient_type === 'chamber' ? 'landmark' : ($dispatch->recipient_type === 'internal_control' ? 'shield-check' : 'building-2') }}" aria-hidden="true"></i></span>
                <div><strong>{{ $dispatch->recipient_name }}</strong><small>{{ $dispatch->recipientTypeLabel() }}@if($dispatch->recipient_unit) · {{ $dispatch->recipient_unit }}@endif</small><span>{{ $dispatch->deliveryMethodLabel() }} · prazo {{ $dispatch->due_at->format('d/m/Y') }}</span></div>
                <div class="dispatch-list-owner"><strong>{{ $dispatch->responsibleUser?->name ?? 'Gestores do Município' }}</strong><small>Responsável interno</small></div>
                <div class="dispatch-list-status"><span class="dispatch-status status-{{ $dispatch->status }}">{{ $dispatch->statusLabel() }}</span>@if($dispatch->isOverdue())<small>Prazo vencido</small>@elseif($dispatch->protocol_number)<small>Protocolo {{ $dispatch->protocol_number }}</small>@endif</div>
                <a class="icon-button" href="{{ route('report-dispatches.show', $dispatch) }}" title="Abrir remessa" aria-label="Abrir remessa"><i data-lucide="arrow-right" aria-hidden="true"></i></a>
            </article>
        @empty
            <div class="empty-state">Nenhuma remessa institucional foi preparada para esta versão.</div>
        @endforelse
    </section>
@endsection
