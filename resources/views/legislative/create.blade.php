@extends('layouts.app')

@section('title', 'Cadastrar emenda | TrilhaGov')

@section('content')
    <div class="page-heading legislative-heading">
        <div>
            <span class="eyebrow">Câmara Municipal · exercício {{ $year }}</span>
            <h1>Cadastrar indicação de emenda</h1>
            <p>{{ $membership->legislative_name ?: auth()->user()->name }} · {{ $membership->legislative_party ?: 'identificação partidária pendente' }}</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('legislative.index', ['year' => $year]) }}"><i data-lucide="arrow-left" aria-hidden="true"></i>Voltar</a>
    </div>

    <x-validation-summary />

    @if (! $profile)
        <div class="legislative-notice is-danger"><i data-lucide="circle-alert" aria-hidden="true"></i><div><strong>Exercício sem regra vigente</strong><p>A configuração municipal precisa ser ativada antes do cadastro.</p></div></div>
    @elseif ($quota['legacy_ceiling'])
        <div class="legislative-notice is-warning"><i data-lucide="triangle-alert" aria-hidden="true"></i><div><strong>Número de vereadores não informado</strong><p>A divisão igualitária da cota depende desse parâmetro na configuração municipal.</p></div></div>
    @endif

    <form method="POST" action="{{ route('legislative.store') }}" data-prevent-double-submit>
        @csrf
        <input name="_submission_token" type="hidden" value="{{ $submissionToken }}">
        @include('legislative._form')
        <div class="legislative-form-actions">
            <a class="btn btn-outline-secondary" href="{{ route('legislative.index', ['year' => $year]) }}">Cancelar</a>
            <button class="btn btn-primary" type="submit" @disabled(! $profile)><i data-lucide="save" aria-hidden="true"></i>Salvar rascunho</button>
        </div>
    </form>
@endsection
