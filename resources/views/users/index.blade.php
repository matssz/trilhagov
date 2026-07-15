@extends('layouts.app')

@section('title', 'Usuários | TrilhaGov')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <p class="page-kicker mb-2">{{ $municipality->name }} / {{ $municipality->state }}</p>
            <h1 class="h3 mb-1">Usuários e acessos</h1>
            <p class="text-secondary mb-0">Controle quem pode operar, consultar e auditar os dados municipais.</p>
        </div>
    </div>

    <x-validation-summary />

    @if (session('invitation_link'))
        <div class="alert alert-success app-alert align-items-start mb-4" role="status">
            <i data-lucide="circle-check" aria-hidden="true"></i>
            <div class="flex-grow-1 min-width-0">
                <strong>Convite pronto para envio.</strong>
                <div class="small mb-2">O link também foi enviado por e-mail quando o serviço de mensagens está configurado.</div>
                <div class="invitation-link-box">
                    <input class="form-control" id="invitationLink" type="text" value="{{ session('invitation_link') }}" readonly aria-label="Link do convite">
                    <button class="icon-button copy-link-button" type="button" data-copy-target="#invitationLink" title="Copiar link" aria-label="Copiar link">
                        <i data-lucide="copy" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <div class="content-panel mb-4">
        <div class="content-panel-header">
            <h2 class="h5 mb-1">Convidar usuário</h2>
            <p class="small text-secondary mb-0">O convite expira em 7 dias e pode ser usado uma única vez.</p>
        </div>
        <div class="content-panel-body">
            <form class="invitation-form" method="POST" action="{{ route('users.invitations.store') }}" novalidate>
                @csrf
                <input name="_submission_token" type="hidden" value="{{ $submissionToken }}">
                <div>
                    <label class="form-label" for="email">E-mail <span class="required-mark">*</span></label>
                    <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" required>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="form-label" for="role">Perfil de acesso <span class="required-mark">*</span></label>
                    <select class="form-select @error('role') is-invalid @enderror" id="role" name="role" required>
                        <option value="">Selecione</option>
                        @foreach ($invitableRoles as $value => $label)
                            <option value="{{ $value }}" @selected(old('role') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button class="btn btn-primary invitation-submit" type="submit">
                    <i data-lucide="user-plus" aria-hidden="true"></i>Criar convite
                </button>
            </form>
        </div>
    </div>

    <div class="content-panel mb-4">
        <div class="content-panel-header">
            <h2 class="h5 mb-1">Equipe municipal</h2>
            <p class="small text-secondary mb-0">{{ $members->count() }} {{ $members->count() === 1 ? 'usuário com acesso' : 'usuários com acesso' }}</p>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Usuário</th>
                        <th>E-mail</th>
                        <th>Perfil</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($members as $member)
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $member->name }}</span>
                                @if ($member->is(auth()->user()))
                                    <span class="badge text-bg-light ms-1">Você</span>
                                @endif
                            </td>
                            <td>{{ $member->email }}</td>
                            <td class="role-cell">
                                @if ($member->is(auth()->user()))
                                    <span class="badge text-bg-primary">{{ $roles[$member->pivot->role] ?? $member->pivot->role }}</span>
                                @else
                                    <form class="role-form" method="POST" action="{{ route('users.role.update', $member) }}">
                                        @csrf
                                        @method('PATCH')
                                        <label class="visually-hidden" for="role-{{ $member->id }}">Perfil de {{ $member->name }}</label>
                                        <select class="form-select form-select-sm" id="role-{{ $member->id }}" name="role" required>
                                            @foreach ($roles as $value => $label)
                                                <option value="{{ $value }}" @selected($member->pivot->role === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Salvar</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="content-panel">
        <div class="content-panel-header">
            <h2 class="h5 mb-1">Convites pendentes</h2>
            <p class="small text-secondary mb-0">Revogue um convite caso ele tenha sido enviado à pessoa errada.</p>
        </div>
        @if ($invitations->isEmpty())
            <div class="empty-state">Nenhum convite pendente.</div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>E-mail</th>
                            <th>Perfil</th>
                            <th>Validade</th>
                            <th class="text-end">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invitations as $invitation)
                            <tr>
                                <td>{{ $invitation->email }}</td>
                                <td>{{ $invitation->roleLabel() }}</td>
                                <td>
                                    @if ($invitation->expires_at->isPast())
                                        <span class="badge text-bg-secondary">Expirado</span>
                                    @else
                                        {{ $invitation->expires_at->format('d/m/Y \à\s H:i') }}
                                    @endif
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('users.invitations.destroy', $invitation) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit">
                                            <i data-lucide="trash-2" aria-hidden="true"></i>Revogar
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
