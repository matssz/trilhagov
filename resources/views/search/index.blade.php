@extends('layouts.app')

@section('title', 'Busca Global')

@section('content')
    <section class="global-search-page">
        <div class="page-heading mb-4">
            <div>
                <span class="section-kicker">{{ $municipality->name }} / {{ $municipality->state }}</span>
                <h1>Busca Global</h1>
                <p class="text-muted mb-0">Encontre emendas, propostas, documentos, protocolos, beneficiários e ocorrências em uma única tela.</p>
            </div>
        </div>

        <form class="global-search-box" method="GET" action="{{ route('search.index') }}">
            <i data-lucide="search" aria-hidden="true"></i>
            <input name="search" type="search" value="{{ $search['query'] }}" placeholder="Digite referência, autor, protocolo, objeto ou beneficiário..." autofocus>
            <button class="btn btn-primary" type="submit">Pesquisar</button>
        </form>

        <div class="global-search-summary">
            @if (mb_strlen($search['query']) < 2)
                <span>Digite pelo menos 2 caracteres para iniciar.</span>
            @else
                <span>{{ $search['total'] }} resultado(s) para “{{ $search['query'] }}”.</span>
            @endif
        </div>

        <div class="global-search-groups">
            @foreach ($search['groups'] as $group)
                <section class="content-panel global-search-group">
                    <div class="content-panel-header">
                        <div>
                            <p class="panel-kicker mb-1"><i data-lucide="{{ $group['icon'] }}" aria-hidden="true"></i>{{ $group['label'] }}</p>
                            <h2 class="h5 mb-0">{{ count($group['results']) }} resultado(s)</h2>
                        </div>
                    </div>
                    <div class="global-search-results">
                        @forelse ($group['results'] as $result)
                            <a class="global-search-result" href="{{ $result['url'] }}">
                                <span><i data-lucide="arrow-up-right" aria-hidden="true"></i></span>
                                <div>
                                    <strong>{{ $result['title'] }}</strong>
                                    <p>{{ $result['subtitle'] }}</p>
                                    <small>{{ $result['meta'] }}</small>
                                </div>
                            </a>
                        @empty
                            <div class="empty-state compact">
                                <i data-lucide="search-x" aria-hidden="true"></i>
                                <strong>Nada encontrado neste grupo</strong>
                                <p>Tente buscar por referência, autor, protocolo, secretaria ou parte do objeto.</p>
                            </div>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </section>
@endsection
