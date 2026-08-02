@extends('layouts.app')

@section('title', 'Verificar documento - TrilhaGov')

@section('content')
    <div class="official-verification-page">
        <header class="verification-hero">
            <span><i data-lucide="badge-check" aria-hidden="true"></i></span>
            <div>
                <p class="page-kicker mb-1">Autenticidade institucional</p>
                <h1>Documento localizado</h1>
                <p>Este registro confirma que o documento foi emitido no TrilhaGov e preservado com hash SHA-256.</p>
            </div>
        </header>

        <section class="content-panel verification-card">
            <div class="verification-status-line">
                <span class="official-status status-{{ $document->status }}">{{ $document->statusLabel() }}</span>
                <strong>{{ $document->official_number }}</strong>
            </div>

            <dl class="verification-data">
                <div><dt>Município</dt><dd>{{ $document->municipality->name }} / {{ $document->municipality->state }}</dd></div>
                <div><dt>Tipo</dt><dd>{{ $document->typeLabel() }}</dd></div>
                <div><dt>Emitido em</dt><dd>{{ $document->issued_at?->format('d/m/Y H:i') ?? 'Não informado' }}</dd></div>
                <div><dt>Emitido por</dt><dd>{{ $document->issuer?->name ?? 'Responsável não informado' }}</dd></div>
                <div><dt>Destinatário</dt><dd>{{ $document->recipient_entity }}</dd></div>
                <div><dt>Protocolo</dt><dd>{{ $document->protocol_number ?: 'Ainda não protocolado' }}</dd></div>
            </dl>

            <div class="verification-hash">
                <small>SHA-256 do conteúdo emitido</small>
                <code>{{ $document->snapshot_sha256 }}</code>
            </div>

            @if($lastEvent)
                <div class="verification-last-event">
                    <i data-lucide="history" aria-hidden="true"></i>
                    <div>
                        <strong>Última movimentação: {{ $lastEvent->label() }}</strong>
                        <p>{{ $lastEvent->occurred_at->format('d/m/Y H:i') }} · {{ $lastEvent->message }}</p>
                    </div>
                </div>
            @endif
        </section>
    </div>
@endsection
