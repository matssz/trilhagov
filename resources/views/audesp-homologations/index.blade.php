@extends('layouts.app')

@section('title', 'Homologação Audesp | TrilhaGov')

@section('content')
    @php
        $totalBatches = (int) $counts->sum();
        $pendingBatches = (int) $counts->only([
            \App\Models\AudespHomologationBatch::STATUS_UNDER_REVIEW,
            \App\Models\AudespHomologationBatch::STATUS_READY,
            \App\Models\AudespHomologationBatch::STATUS_SUBMITTED,
            \App\Models\AudespHomologationBatch::STATUS_RECEIVED,
            \App\Models\AudespHomologationBatch::STATUS_VALIDATED,
        ])->sum();
    @endphp

    <div class="homologation-heading mb-4">
        <div>
            <p class="page-kicker mb-2">Contabilidade municipal · evidências de remessa</p>
            <h1 class="h3 mb-1">Homologação Audesp</h1>
            <p class="text-secondary mb-0">Confronte o XML do Siafic, registre a transmissão oficial e preserve cada retorno do TCESP.</p>
        </div>
        <a class="btn btn-outline-primary" href="https://www.tce.sp.gov.br/audesp/coletor" target="_blank" rel="noopener noreferrer">
            <i data-lucide="external-link" aria-hidden="true"></i>Coletor Audesp
        </a>
    </div>

    <x-validation-summary />

    <div class="homologation-scope mb-4" role="note">
        <i data-lucide="shield-check" aria-hidden="true"></i>
        <div>
            <strong>Controle de homologação, não canal de transmissão</strong>
            <p>O envio permanece no Coletor Audesp e exige permissão do órgão. O TrilhaGov guarda o arquivo, compara os cadastros e organiza protocolos, rejeições e reenvios.</p>
        </div>
        <span>XSD {{ \App\Models\AudespAmendmentRegistration::SCHEMA_VERSION }}</span>
    </div>

    <div class="homologation-metrics mb-4">
        <article><span><i data-lucide="package" aria-hidden="true"></i></span><div><small>Lotes registrados</small><strong>{{ $totalBatches }}</strong></div></article>
        <article><span><i data-lucide="clock-3" aria-hidden="true"></i></span><div><small>Em andamento</small><strong>{{ $pendingBatches }}</strong></div></article>
        <article class="{{ (int) ($counts[\App\Models\AudespHomologationBatch::STATUS_REJECTED] ?? 0) > 0 ? 'has-risk' : '' }}"><span><i data-lucide="circle-alert" aria-hidden="true"></i></span><div><small>Rejeitados</small><strong>{{ (int) ($counts[\App\Models\AudespHomologationBatch::STATUS_REJECTED] ?? 0) }}</strong></div></article>
        <article class="is-success"><span><i data-lucide="badge-check" aria-hidden="true"></i></span><div><small>Armazenados</small><strong>{{ (int) ($counts[\App\Models\AudespHomologationBatch::STATUS_STORED] ?? 0) }}</strong></div></article>
    </div>

    @if ($canEdit)
        <section class="content-panel mb-4" id="novo-lote">
            <div class="content-panel-header homologation-panel-header">
                <div class="d-flex align-items-center gap-2"><i data-lucide="file-input" aria-hidden="true"></i><h2 class="h5 mb-0">Novo lote de conferência</h2></div>
                <span class="small text-secondary">XML gerado pelo Siafic · limite de 5 MB</span>
            </div>
            <form class="homologation-upload-form" method="POST" action="{{ route('audesp-homologations.store') }}" enctype="multipart/form-data" novalidate>
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $uploadToken }}">
                <div>
                    <label class="form-label" for="fiscal_year">Exercício <span class="required-mark">*</span></label>
                    <input class="form-control @error('fiscal_year') is-invalid @enderror" id="fiscal_year" name="fiscal_year" type="number" min="2026" max="2026" value="{{ old('fiscal_year', 2026) }}" required>
                    @error('fiscal_year')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="form-label" for="reference_month">Mês de referência <span class="required-mark">*</span></label>
                    <select class="form-select @error('reference_month') is-invalid @enderror" id="reference_month" name="reference_month" required>
                        @foreach (range(1, 12) as $month)<option value="{{ $month }}" @selected((int) old('reference_month', now()->month) === $month)>{{ str_pad((string) $month, 2, '0', STR_PAD_LEFT) }}</option>@endforeach
                        <option value="13" @selected((int) old('reference_month') === 13)>13 · Encerramento</option>
                        <option value="14" @selected((int) old('reference_month') === 14)>14 · Encerramento final</option>
                    </select>
                    @error('reference_month')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="form-label" for="source_system">Siafic / fornecedor <span class="required-mark">*</span></label>
                    <input class="form-control @error('source_system') is-invalid @enderror" id="source_system" name="source_system" value="{{ old('source_system') }}" maxlength="120" placeholder="Nome do sistema contábil" required>
                    @error('source_system')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="form-label" for="source_version">Versão do sistema</label>
                    <input class="form-control @error('source_version') is-invalid @enderror" id="source_version" name="source_version" value="{{ old('source_version') }}" maxlength="80">
                    @error('source_version')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="span-2">
                    <label class="form-label" for="source_file">Arquivo XML <span class="required-mark">*</span></label>
                    <input class="form-control @error('source_file') is-invalid @enderror" id="source_file" name="source_file" type="file" accept=".xml,application/xml,text/xml" required>
                    @error('source_file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="span-2">
                    <label class="form-label" for="retry_of_id">Tentativa anterior rejeitada</label>
                    <select class="form-select @error('retry_of_id') is-invalid @enderror" id="retry_of_id" name="retry_of_id">
                        <option value="">Não é um reenvio</option>
                        @foreach ($rejectedBatches as $rejected)
                            <option value="{{ $rejected->id }}" @selected((string) old('retry_of_id') === (string) $rejected->id)>{{ $rejected->reference }} · {{ str_pad((string) $rejected->reference_month, 2, '0', STR_PAD_LEFT) }}/{{ $rejected->fiscal_year }}</option>
                        @endforeach
                    </select>
                    @error('retry_of_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="span-3">
                    <label class="form-label" for="notes">Observações</label>
                    <textarea class="form-control @error('notes') is-invalid @enderror" id="notes" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-primary" type="submit"><i data-lucide="scan-search" aria-hidden="true"></i>Importar e conferir</button>
            </form>
        </section>
    @endif

    <section class="content-panel">
        <div class="content-panel-header homologation-panel-header">
            <div class="d-flex align-items-center gap-2"><i data-lucide="history" aria-hidden="true"></i><h2 class="h5 mb-0">Histórico de lotes</h2></div>
            <span class="small text-secondary">{{ $batches->total() }} registro(s)</span>
        </div>
        @if ($batches->isEmpty())
            <div class="empty-state">Nenhum lote de homologação foi criado para este município.</div>
        @else
            <div class="homologation-table-wrap">
                <table class="homologation-table">
                    <thead><tr><th>Referência</th><th>Competência</th><th>Siafic</th><th>Conferência</th><th>Situação</th><th>Registrado em</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($batches as $batch)
                            <tr>
                                <td><strong>{{ Str::limit($batch->reference, 13, '') }}</strong>@if ($batch->retry_of_id)<small>Reenvio vinculado</small>@endif</td>
                                <td>{{ str_pad((string) $batch->reference_month, 2, '0', STR_PAD_LEFT) }}/{{ $batch->fiscal_year }}</td>
                                <td><strong>{{ $batch->source_system }}</strong><small>{{ $batch->source_version ?: 'Versão não informada' }}</small></td>
                                <td><strong>{{ $batch->matched_count }}/{{ $batch->item_count }}</strong><small>{{ $batch->divergent_count + $batch->unmatched_count }} pendência(s)</small></td>
                                <td><span class="homologation-status status-{{ $batch->status }}">{{ $batch->statusLabel() }}</span></td>
                                <td>{{ $batch->created_at->format('d/m/Y H:i') }}<small>{{ $batch->creator->name }}</small></td>
                                <td><a class="icon-button" href="{{ route('audesp-homologations.show', $batch) }}" title="Abrir lote" aria-label="Abrir lote"><i data-lucide="arrow-right" aria-hidden="true"></i></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $batches->links() }}</div>
        @endif
    </section>
@endsection
