@extends('layouts.app')

@section('title', 'Erro interno | TrilhaGov')

@section('content')
    <x-error-state code="500" title="Não foi possível concluir" message="Ocorreu um erro inesperado. Tente novamente e, se o problema continuar, informe o suporte responsável." />
@endsection
