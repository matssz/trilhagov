@extends('layouts.app')

@section('title', 'Relatórios mensais | TrilhaGov')

@section('content')
    <div class="governance-heading mb-4">
        <div>
            <p class="page-kicker mb-2">Governança municipal · controle interno · Câmara</p>
            <h1 class="h3 mb-1">Relatórios mensais</h1>
            <p class="text-secondary mb-0">Fotografias versionadas da execução das emendas, dos controles e das providências pendentes.</p>
        </div>
        <a class="btn btn-outline-primary" href="{{ \App\Services\MunicipalGovernanceReportService::SOURCE_URL }}" target="_blank" rel="noopener noreferrer">
            <i data-lucide="external-link" aria-hidden="true"></i>Manual TCESP
        </a>
    </div>

    <x-validation-summary />

    <div class="governance-basis mb-4" role="note">
        <i data-lucide="landmark" aria-hidden="true"></i>
        <div><strong>Prestação periódica de informações</strong><p>Consolidação gerencial dos valores recebidos, empenhados, liquidados, pagos e do saldo, acompanhada dos controles internos e da rastreabilidade municipal.</p></div>
        <span>Metodologia 2026.01</span>
    </div>

    @if ($canEdit)
        <section class="content-panel mb-4">
            <div class="content-panel-header governance-panel-header">
                <div><p class="page-kicker mb-1">Nova competência</p><h2 class="h5 mb-0">Preparar fotografia mensal</h2></div>
                <span class="small text-secondary">Uma versão em preparação por competência</span>
            </div>
            <form class="governance-create-form" method="POST" action="{{ route('governance-reports.store') }}">
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $createToken }}">
                <div><label class="form-label" for="fiscal_year">Exercício <span class="required-mark">*</span></label><input class="form-control @error('fiscal_year') is-invalid @enderror" id="fiscal_year" name="fiscal_year" type="number" min="2026" max="2026" value="{{ old('fiscal_year', 2026) }}" required>@error('fiscal_year')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div><label class="form-label" for="reference_month">Competência <span class="required-mark">*</span></label><select class="form-select @error('reference_month') is-invalid @enderror" id="reference_month" name="reference_month" required>@foreach (range(1, 12) as $month)<option value="{{ $month }}" @selected((int) old('reference_month', now()->month) === $month)>{{ str_pad((string) $month, 2, '0', STR_PAD_LEFT) }}/2026</option>@endforeach</select>@error('reference_month')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <button class="btn btn-primary" type="submit"><i data-lucide="file-plus-2" aria-hidden="true"></i>Preparar relatório</button>
            </form>
        </section>
    @endif

    <section class="content-panel">
        <div class="content-panel-header governance-panel-header">
            <div class="d-flex align-items-center gap-2"><i data-lucide="history" aria-hidden="true"></i><h2 class="h5 mb-0">Histórico preservado</h2></div>
            <span class="small text-secondary">{{ $reports->total() }} versão(ões)</span>
        </div>
        @if ($reports->isEmpty())
            <div class="empty-state">Nenhuma competência mensal foi preparada para este Município.</div>
        @else
            <div class="governance-report-list">
                @foreach ($reports as $report)
                    @php($totals = $report->snapshot['totals'])
                    <article class="governance-report-row">
                        <div class="governance-period"><small>Competência</small><strong>{{ $report->periodLabel() }}</strong><span>Versão {{ $report->version }}</span></div>
                        <div class="governance-report-identity"><strong>{{ $report->code() }}</strong><span>{{ $totals['amendments'] }} emenda(s) · R$ {{ number_format($totals['received'], 2, ',', '.') }} recebidos</span><small>Dados de {{ $report->data_generated_at->format('d/m/Y H:i') }}</small></div>
                        <div class="governance-report-state"><span class="governance-status status-{{ $report->status }}">{{ $report->statusLabel() }}</span><small>{{ $report->issued_at ? 'Emitido por '.$report->issuer?->name : 'Preparado por '.$report->creator->name }}</small></div>
                        <a class="icon-button" href="{{ route('governance-reports.show', $report) }}" title="Abrir relatório" aria-label="Abrir relatório"><i data-lucide="arrow-right" aria-hidden="true"></i></a>
                    </article>
                @endforeach
            </div>
            <div class="p-3">{{ $reports->links() }}</div>
        @endif
    </section>
@endsection
