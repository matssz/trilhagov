@extends('layouts.app')

@section('title', 'Página não encontrada | TrilhaGov')

@section('content')
    <x-error-state code="404" title="Página não encontrada" message="O endereço informado não existe ou o conteúdo não está disponível neste município." />
@endsection
