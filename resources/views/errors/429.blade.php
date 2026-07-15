@extends('layouts.app')

@section('title', 'Muitas solicitações | TrilhaGov')

@section('content')
    <x-error-state code="429" title="Muitas solicitações" message="Aguarde alguns instantes antes de tentar novamente." />
@endsection
