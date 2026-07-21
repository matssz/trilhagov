@extends('layouts.app')

@section('title', 'Relatórios especializados | TrilhaGov')

@section('content')
    <div class="page-heading report-heading">
        <div>
            <span class="page-kicker">Gestão municipal</span>
            <h1>Relatórios especializados</h1>
            <p>Produtos consolidados para decisão, controle interno e prestação institucional.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('governance-reports.index') }}"><i data-lucide="scroll-text" aria-hidden="true"></i>Relatórios mensais</a>
    </div>

    @if ($canEdit)
        <section class="report-builder" aria-labelledby="prepare-report-title">
            <div class="report-builder-copy">
                <span class="section-icon"><i data-lucide="file-chart-column" aria-hidden="true"></i></span>
                <div><h2 id="prepare-report-title">Preparar relatório</h2><p>Competência de referência dos dados consolidados</p></div>
            </div>
            <form method="POST" action="{{ route('specialized-reports.store') }}" class="report-builder-form" data-prevent-double-submit>
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $createToken }}">
                <label class="form-label">Modelo
                    <select class="form-select" name="report_type" required>
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}" @selected(old('report_type') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="form-label">Exercício
                    <select class="form-select" name="fiscal_year" required><option value="2026">2026</option></select>
                </label>
                <label class="form-label">Competência
                    <select class="form-select" name="reference_month" required>
                        @foreach (range(1, 12) as $month)
                            <option value="{{ $month }}" @selected((int) old('reference_month', now()->month) === $month)>{{ str_pad((string) $month, 2, '0', STR_PAD_LEFT) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="form-label">Tolerância física/financeira
                    <div class="input-group"><input class="form-control" name="difference_threshold" type="number" min="5" max="100" value="{{ old('difference_threshold', 20) }}"><span class="input-group-text">p.p.</span></div>
                </label>
                <button class="btn btn-primary" type="submit"><i data-lucide="sparkles" aria-hidden="true"></i>Preparar</button>
            </form>
            @if ($errors->any())
                <div class="form-errors" role="alert">{{ $errors->first() }}</div>
            @endif
        </section>
    @endif

    <div class="report-product-grid">
        @foreach ($types as $value => $label)
            @php($icon = match ($value) { 'health' => 'heart-pulse', 'divergences' => 'git-compare-arrows', default => 'archive' })
            <article class="report-product">
                <span class="report-product-icon"><i data-lucide="{{ $icon }}" aria-hidden="true"></i></span>
                <div><h2>{{ $label }}</h2><p>{{ $descriptions[$value] }}</p></div>
            </article>
        @endforeach
    </div>

    <section class="content-panel report-history">
        <div class="content-panel-header"><div><span class="page-kicker">Histórico</span><h2 class="h5 mb-0">Versões preparadas e emitidas</h2></div><span class="record-count">{{ $reports->total() }}</span></div>
        @if ($reports->isEmpty())
            <div class="empty-state"><i data-lucide="file-chart-column" aria-hidden="true"></i><h3>Nenhum relatório preparado</h3><p>O histórico aparecerá aqui após a primeira consolidação.</p></div>
        @else
            <div class="table-responsive">
                <table class="table app-table align-middle mb-0">
                    <thead><tr><th>Documento</th><th>Competência</th><th>Situação</th><th>Responsável</th><th>Dados gerados</th><th class="text-end">Acesso</th></tr></thead>
                    <tbody>
                        @foreach ($reports as $report)
                            <tr>
                                <td><strong>{{ $report->typeLabel() }}</strong><small class="table-subtitle">{{ $report->code() }}</small></td>
                                <td>{{ $report->periodLabel() }}</td>
                                <td><span class="status-pill {{ $report->status === 'issued' ? 'is-success' : 'is-warning' }}">{{ $report->statusLabel() }}</span></td>
                                <td>{{ $report->issuer?->name ?? $report->creator->name }}</td>
                                <td>{{ $report->data_generated_at->format('d/m/Y H:i') }}</td>
                                <td class="text-end"><a class="icon-button" href="{{ route('specialized-reports.show', $report) }}" title="Abrir relatório" aria-label="Abrir {{ $report->code() }}"><i data-lucide="arrow-right" aria-hidden="true"></i></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="panel-pagination">{{ $reports->links() }}</div>
        @endif
    </section>
@endsection
