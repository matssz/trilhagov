@extends('layouts.app')

@section('title', 'Conferir importação | TrilhaGov')

@section('content')
    <header class="import-heading">
        <div>
            <p class="page-kicker mb-2">Lote #{{ $batch->id }}</p>
            <h1>Conferência da planilha</h1>
            <p>{{ $batch->original_name }} · enviado por {{ $batch->user->name }}</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('spreadsheet-imports.index') }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Voltar aos lotes</a>
    </header>

    <section class="import-status-band {{ $batch->status === 'completed' ? 'is-completed' : '' }}">
        <span><i data-lucide="{{ $batch->status === 'completed' ? 'circle-check' : 'scan-search' }}" aria-hidden="true"></i></span>
        <div><strong>{{ $batch->status === 'completed' ? 'Importação concluída' : 'Aguardando sua confirmação' }}</strong><small>{{ $batch->created_at->format('d/m/Y H:i') }} · {{ $batch->total_rows }} linha(s) analisada(s)</small></div>
        <p>{{ $batch->status === 'completed' ? 'O resultado permanece disponível para auditoria.' : 'Somente as linhas aptas serão cadastradas.' }}</p>
    </section>

    <section class="import-metrics" aria-label="Resultado da conferência">
        <article><span class="metric-total"><i data-lucide="rows-3" aria-hidden="true"></i></span><div><small>Total</small><strong>{{ $batch->total_rows }}</strong></div></article>
        <article><span class="metric-valid"><i data-lucide="circle-check" aria-hidden="true"></i></span><div><small>{{ $batch->status === 'completed' ? 'Importadas' : 'Aptas' }}</small><strong>{{ $batch->status === 'completed' ? $batch->imported_rows : $batch->valid_rows }}</strong></div></article>
        <article><span class="metric-duplicate"><i data-lucide="copy-x" aria-hidden="true"></i></span><div><small>Duplicadas</small><strong>{{ $batch->duplicate_rows }}</strong></div></article>
        <article><span class="metric-invalid"><i data-lucide="triangle-alert" aria-hidden="true"></i></span><div><small>Inválidas</small><strong>{{ $batch->invalid_rows }}</strong></div></article>
    </section>

    @if ($batch->status === 'previewed')
        <section class="import-confirm-band">
            <div><strong>{{ $batch->valid_rows }} linha(s) pronta(s) para importação</strong><small>Duplicadas e inválidas continuarão fora do cadastro.</small></div>
            @if ($batch->valid_rows > 0)
                <form method="POST" action="{{ route('spreadsheet-imports.confirm', $batch) }}">
                    @csrf
                    <input name="_submission_token" type="hidden" value="{{ $confirmationToken }}">
                    <button class="btn btn-primary" type="submit"><i data-lucide="file-input" aria-hidden="true"></i>Importar linhas aptas</button>
                </form>
            @else
                <a class="btn btn-outline-primary" href="{{ route('spreadsheet-imports.index') }}"><i data-lucide="upload" aria-hidden="true"></i>Enviar arquivo corrigido</a>
            @endif
        </section>
    @endif

    <section class="import-preview">
        <header><div><p class="panel-kicker">Resultado por linha</p><h2>Dados reconhecidos</h2></div><span>{{ $rows->total() }} resultado(s)</span></header>
        <div class="table-responsive">
            <table class="table import-table import-preview-table mb-0">
                <thead><tr><th>Linha</th><th>Identificação</th><th>Exercício</th><th>Esfera</th><th>Autor</th><th>Valor previsto</th><th>Resultado</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php($data = $row->normalized_data ?? [])
                        <tr>
                            <td>{{ $row->row_number }}</td>
                            <td><strong>{{ $data['reference'] ?? 'Não reconhecida' }}</strong>@if ($row->amendment)<small><a href="{{ route('emendas.show', $row->amendment) }}">Abrir emenda</a></small>@endif</td>
                            <td>{{ $data['fiscal_year'] ?? '—' }}</td>
                            <td>{{ App\Models\ParliamentaryAmendment::governmentSpheres()[$data['government_sphere'] ?? ''] ?? ($data['government_sphere'] ?? '—') }}</td>
                            <td>{{ $data['author_name'] ?? '—' }}</td>
                            <td>{{ is_numeric($data['expected_amount'] ?? null) ? 'R$ '.number_format((float) $data['expected_amount'], 2, ',', '.') : ($data['expected_amount'] ?? '—') }}</td>
                            <td>
                                <span class="import-state state-{{ $row->status }}">{{ match ($row->status) { 'valid' => 'Apta', 'duplicate' => 'Duplicada', 'imported' => 'Importada', default => 'Corrigir' } }}</span>
                                @if ($row->errors)<details class="import-errors"><summary>Ver motivo</summary><ul>@foreach ($row->errors as $error)<li>{{ $error }}</li>@endforeach</ul></details>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    {{ $rows->links() }}
@endsection
