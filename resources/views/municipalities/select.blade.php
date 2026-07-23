@extends('layouts.app')

@section('title', 'Selecionar município | TrilhaGov')

@section('content')
    <div class="auth-shell">
        <div class="mb-4">
            <p class="page-kicker mb-2">Contexto de trabalho</p>
            <h1 class="h3 mb-1">Selecionar município</h1>
            <p class="text-secondary mb-0">Escolha o vínculo correto. O card mostra qual área será aberta e qual papel ficará ativo.</p>
        </div>

        <form method="POST" action="{{ route('municipalities.activate') }}">
            @csrf
            <x-validation-summary />
            <div class="d-grid gap-3 mb-3">
                @foreach ($municipalities as $municipality)
                    @php
                        $workspaceContext = auth()->user()->workspaceContextForMunicipality($municipality->id);
                        $roleLabel = App\Models\User::municipalityRoles()[$municipality->pivot->role] ?? 'Usuário municipal';
                    @endphp
                    <label class="municipality-option">
                        <span class="d-flex align-items-start gap-3">
                            <input class="form-check-input mt-1" name="municipality_id" type="radio" value="{{ $municipality->id }}" required>
                            <span class="municipality-option-main">
                                <strong class="d-block">{{ $municipality->name }} / {{ $municipality->state }}</strong>
                                <small class="text-secondary">IBGE {{ $municipality->ibge_code }} · {{ $roleLabel }}</small>
                                <span class="municipality-workspace">
                                    <span><i data-lucide="{{ $workspaceContext['icon'] }}" aria-hidden="true"></i>{{ $workspaceContext['label'] }}</span>
                                    <small>{{ $workspaceContext['description'] }}</small>
                                </span>
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('municipality_id')<div class="text-danger small mb-3">{{ $message }}</div>@enderror
            <button class="btn btn-primary w-100" type="submit">Continuar</button>
        </form>
    </div>
@endsection
