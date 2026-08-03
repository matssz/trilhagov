@extends('layouts.app')

@section('title', 'LGPD e defesa')

@section('content')
    <section class="security-privacy-page">
        <div class="page-heading mb-4">
            <div>
                <span class="section-kicker">Defesa municipal</span>
                <h1>LGPD e defesa contra acesso indevido</h1>
                <p class="text-muted mb-0">Diagnostico pratico para proteger dados, reduzir exposicao e evitar que IDs visiveis virem acesso real.</p>
            </div>
        </div>

        <div class="privacy-hero">
            <div>
                <span class="privacy-pill">
                    <i data-lucide="database-lock" aria-hidden="true"></i>
                    Supabase/Postgres via backend
                </span>
                <h2>IDs no inspecionar nao concedem permissao</h2>
                <p>
                    O usuario pode ver referencias, links e IDs renderizados na pagina, mas cada acao sensivel continua passando por login,
                    municipio ativo, papel permitido e validacao no servidor Laravel.
                </p>
            </div>
            <div class="privacy-score">
                <strong>{{ $okChecks }}/{{ count($checks) }}</strong>
                <span>controles prontos</span>
            </div>
        </div>

        <article class="privacy-mfa-card mt-3">
            <div>
                <span class="privacy-pill"><i data-lucide="shield-check" aria-hidden="true"></i>Segundo fator</span>
                <h2>Protecao extra para a sua conta de gestor</h2>
                <p>{{ $currentUserMfaEnabled ? 'Seu login ja exige uma verificacao adicional depois da senha.' : 'Ative a verificacao em duas etapas para reduzir risco caso a senha seja exposta.' }}</p>
            </div>
            <form method="POST" action="{{ route('security-privacy.mfa.update') }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="enabled" value="{{ $currentUserMfaEnabled ? 0 : 1 }}">
                <button class="btn {{ $currentUserMfaEnabled ? 'btn-outline-primary' : 'btn-primary' }}" type="submit">
                    <i data-lucide="{{ $currentUserMfaEnabled ? 'shield-off' : 'shield-check' }}" aria-hidden="true"></i>
                    {{ $currentUserMfaEnabled ? 'Desativar MFA' : 'Ativar MFA' }}
                </button>
            </form>
        </article>

        <div class="row g-3 mt-1">
            @foreach ($checks as $check)
                <div class="col-12 col-xl-6">
                    <article class="privacy-check privacy-check-{{ $check['status'] }}">
                        <span class="privacy-check-icon">
                            <i data-lucide="{{ $check['status'] === 'critical' ? 'triangle-alert' : ($check['status'] === 'planned' ? 'clock-3' : ($check['status'] === 'attention' ? 'shield-alert' : 'circle-check')) }}" aria-hidden="true"></i>
                        </span>
                        <div>
                            <h2>{{ $check['title'] }}</h2>
                            <p>{{ $check['description'] }}</p>
                        </div>
                    </article>
                </div>
            @endforeach
        </div>

        <div class="row g-3 mt-1">
            <div class="col-12 col-xl-8">
                <article class="privacy-panel">
                    <div class="privacy-panel-heading">
                        <span>Inventario LGPD</span>
                        <strong>Dados tratados pelo municipio</strong>
                    </div>
                    <div class="privacy-inventory">
                        @foreach ($dataInventory as $item)
                            <div>
                                <span>{{ $item['area'] }}</span>
                                <strong>{{ $item['data'] }}</strong>
                                <small>{{ $item['count'] }}</small>
                                <p>{{ $item['purpose'] }}</p>
                                <em>{{ $item['exposure'] }}</em>
                            </div>
                        @endforeach
                    </div>
                </article>
            </div>
            <div class="col-12 col-xl-4">
                <article class="privacy-panel">
                    <div class="privacy-panel-heading">
                        <span>Bases de tratamento</span>
                        <strong>Por que o dado existe</strong>
                    </div>
                    <div class="privacy-legal-bases">
                        @foreach ($legalBases as $basis)
                            <div>
                                <i data-lucide="scale" aria-hidden="true"></i>
                                <strong>{{ $basis['title'] }}</strong>
                                <p>{{ $basis['description'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </article>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-12 col-lg-7">
                <article class="privacy-panel">
                    <div class="privacy-panel-heading">
                        <span>Mapa de exposicao</span>
                        <strong>{{ $municipality->name }} / {{ $municipality->state }}</strong>
                    </div>
                    <div class="privacy-map">
                        <div>
                            <span>Publico</span>
                            <strong>{{ $municipality->transparency_enabled ? 'Transparencia ativa' : 'Transparencia desligada' }}</strong>
                            <small>Slug publico controlado pelo gestor.</small>
                        </div>
                        <div>
                            <span>Autenticado</span>
                            <strong>Sessao municipal</strong>
                            <small>Sem cache de telas internas no navegador.</small>
                        </div>
                        <div>
                            <span>Operacional</span>
                            <strong>Papel e permissao</strong>
                            <small>Gestor, editor, vereador, revisao ou consulta.</small>
                        </div>
                    </div>
                </article>
            </div>
            <div class="col-12 col-lg-5">
                <article class="privacy-panel">
                    <div class="privacy-panel-heading">
                        <span>Acessos</span>
                        <strong>Usuarios e convites</strong>
                    </div>
                    <div class="privacy-stats">
                        @foreach (App\Models\User::municipalityRoles() as $role => $label)
                            <div>
                                <span>{{ $label }}</span>
                                <strong>{{ $roleCounts[$role] ?? 0 }}</strong>
                            </div>
                        @endforeach
                    </div>
                    <div class="privacy-invitations">
                        <span>{{ $invitations['pending'] }} convite(s) pendente(s)</span>
                        <span>{{ $invitations['expired'] }} expirado(s)</span>
                        <span>{{ $invitations['accepted'] }} aceito(s)</span>
                    </div>
                </article>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-12 col-xl-7">
                <article class="privacy-panel">
                    <div class="privacy-panel-heading">
                        <span>Matriz de risco</span>
                        <strong>Defesa operacional</strong>
                    </div>
                    <div class="privacy-risk-list">
                        @foreach ($riskMatrix as $risk)
                            <div class="privacy-risk privacy-risk-{{ $risk['status'] }}">
                                <i data-lucide="{{ $risk['status'] === 'ok' ? 'circle-check' : ($risk['status'] === 'critical' ? 'triangle-alert' : 'shield-alert') }}" aria-hidden="true"></i>
                                <div>
                                    <strong>{{ $risk['title'] }}</strong>
                                    <span>{{ $risk['signal'] }}</span>
                                    <p>{{ $risk['action'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>
            </div>
            <div class="col-12 col-xl-5">
                <article class="privacy-panel">
                    <div class="privacy-panel-heading">
                        <span>Retencao e descarte</span>
                        <strong>Regras praticas</strong>
                    </div>
                    <div class="privacy-retention">
                        @foreach ($retentionPlan as $rule)
                            <div>
                                <span>{{ $rule['item'] }}</span>
                                <p>{{ $rule['rule'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </article>
            </div>
        </div>

        <article class="privacy-panel mt-3">
            <div class="privacy-panel-heading">
                <span>Resposta a incidente</span>
                <strong>Roteiro para vazamento, acesso indevido ou erro de permissao</strong>
            </div>
            <div class="privacy-playbook">
                @foreach ($incidentPlaybook as $step)
                    <div>
                        <span>{{ $step['step'] }}</span>
                        <strong>{{ $step['title'] }}</strong>
                        <p>{{ $step['description'] }}</p>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="privacy-guidance mt-3">
            <i data-lucide="lock-keyhole" aria-hidden="true"></i>
            <div>
                <h2>Regra de defesa do TrilhaGov</h2>
                <p>
                    ID exibido na interface e segredo sao coisas diferentes. O sistema pode mostrar um identificador operacional, mas nao pode confiar nele.
                    A defesa correta e sempre validar no backend se o registro pertence ao municipio ativo e se o usuario tem papel para aquela acao.
                </p>
            </div>
        </article>
    </section>
@endsection
