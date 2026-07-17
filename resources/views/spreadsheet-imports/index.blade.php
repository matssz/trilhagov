@extends('layouts.app')

@section('title', 'Importar planilha | TrilhaGov')

@section('content')
    <header class="import-heading">
        <div>
            <p class="page-kicker mb-2">Migração assistida</p>
            <h1>Importar planilha</h1>
            <p>{{ $municipality->name }} · conferência antes de gravar</p>
        </div>
        <a class="btn btn-outline-primary" href="{{ route('spreadsheet-imports.template') }}"><i data-lucide="download" aria-hidden="true"></i>Baixar modelo CSV</a>
    </header>

    <section class="import-status-band">
        <span><i data-lucide="file-spreadsheet" aria-hidden="true"></i></span>
        <div><strong>Até 500 linhas por lote</strong><small>CSV com vírgula ou ponto e vírgula · máximo de 2 MB</small></div>
        <p>Registros existentes são sinalizados e nunca sobrescritos.</p>
    </section>

    <section class="import-upload-band">
        <div>
            <p class="panel-kicker">Novo lote</p>
            <h2>Selecionar arquivo</h2>
            <p>Use o modelo para manter nomes e datas reconhecíveis durante a conferência.</p>
        </div>
        <form method="POST" action="{{ route('spreadsheet-imports.preview') }}" enctype="multipart/form-data">
            @csrf
            <input name="_submission_token" type="hidden" value="{{ $submissionToken }}">
            <label class="import-file-field">
                <span>Planilha CSV</span>
                <input class="form-control @error('spreadsheet') is-invalid @enderror" name="spreadsheet" type="file" accept=".csv,text/csv" required>
                @error('spreadsheet')<small class="invalid-feedback">{{ $message }}</small>@enderror
            </label>
            <button class="btn btn-primary" type="submit"><i data-lucide="scan-search" aria-hidden="true"></i>Conferir planilha</button>
        </form>
    </section>

    <section class="import-history">
        <header><div><p class="panel-kicker">Rastreabilidade</p><h2>Lotes recentes</h2></div><span>{{ $batches->count() }} registro(s)</span></header>
        @if ($batches->isEmpty())
            <div class="import-empty"><span><i data-lucide="file-clock" aria-hidden="true"></i></span><h2>Nenhum lote enviado</h2><p>Os arquivos conferidos por esta equipe aparecerão aqui.</p></div>
        @else
            <div class="table-responsive">
                <table class="table import-table mb-0">
                    <thead><tr><th>Arquivo</th><th>Responsável</th><th>Data</th><th>Resultado</th><th><span class="visually-hidden">Abrir</span></th></tr></thead>
                    <tbody>
                        @foreach ($batches as $batch)
                            <tr>
                                <td><strong>{{ $batch->original_name }}</strong><small>Lote #{{ $batch->id }}</small></td>
                                <td>{{ $batch->user->name }}</td>
                                <td>{{ $batch->created_at->format('d/m/Y H:i') }}</td>
                                <td><span class="import-state state-{{ $batch->status }}">{{ $batch->status === 'completed' ? 'Importado' : 'Aguardando confirmação' }}</span></td>
                                <td><a class="icon-button" href="{{ route('spreadsheet-imports.show', $batch) }}" title="Abrir lote" aria-label="Abrir lote {{ $batch->id }}"><i data-lucide="arrow-right" aria-hidden="true"></i></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
