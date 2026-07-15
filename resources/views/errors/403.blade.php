@extends('layouts.app')

@section('title', 'Acesso não permitido | TrilhaGov')

@section('content')
    <x-error-state code="403" title="Acesso não permitido" message="Seu usuário não possui permissão para acessar este conteúdo." />
@endsection
