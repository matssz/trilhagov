@extends('layouts.app')

@section('title', 'Sessão expirada | TrilhaGov')

@section('content')
    <x-error-state code="419" title="Sua sessão expirou" message="Atualize a página e envie o formulário novamente. Nenhuma informação foi salva por esta tentativa." />
@endsection
