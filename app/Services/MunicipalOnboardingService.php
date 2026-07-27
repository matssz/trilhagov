<?php

namespace App\Services;

use App\Models\Municipality;
use App\Models\MunicipalityInvitation;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\User;

class MunicipalOnboardingService
{
    public function __construct(
        private readonly MunicipalRegulatoryReadiness $readiness,
    ) {}

    /** @return array{activeProfile: ?MunicipalRegulatoryProfile, draftProfile: ?MunicipalRegulatoryProfile, year: int, steps: array<int, array{key: string, title: string, description: string, complete: bool, route: string, action: string, icon: string}>, score: int, guide: array<string, mixed>, health: array<string, mixed>, council: array<string, mixed>} */
    public function summary(Municipality $municipality): array
    {
        $activeProfile = $municipality->regulatoryProfiles()
            ->with('instruments')
            ->where('status', MunicipalRegulatoryProfile::STATUS_ACTIVE)
            ->orderByDesc('fiscal_year')
            ->first();
        $draftProfile = $municipality->regulatoryProfiles()
            ->with('instruments')
            ->where('status', MunicipalRegulatoryProfile::STATUS_DRAFT)
            ->orderByDesc('fiscal_year')
            ->first();
        $year = $activeProfile?->fiscal_year ?? $draftProfile?->fiscal_year ?? now()->year + 1;
        $members = $municipality->users()->get();
        $hasTeam = $members
            ->whereIn('pivot.role', [User::ROLE_MANAGER, User::ROLE_EDITOR, User::ROLE_AUDITOR])
            ->count() > 1;
        $hasCouncil = $members
            ->whereIn('pivot.role', [User::ROLE_COUNCILOR, User::ROLE_LEGISLATIVE_REVIEWER])
            ->isNotEmpty();
        $councilors = $members->where('pivot.role', User::ROLE_COUNCILOR)->values();
        $legislativeReviewers = $members->where('pivot.role', User::ROLE_LEGISLATIVE_REVIEWER)->values();
        $pendingCouncilInvitations = MunicipalityInvitation::query()
            ->where('municipality_id', $municipality->id)
            ->whereIn('role', [User::ROLE_COUNCILOR, User::ROLE_LEGISLATIVE_REVIEWER])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->latest()
            ->get();
        $hasLegislativeFlow = $municipality->legislativeProposals()
            ->when($activeProfile, fn ($query) => $query->where('fiscal_year', $activeProfile->fiscal_year))
            ->exists();
        $hasAmendment = $municipality->amendments()
            ->when($activeProfile, fn ($query) => $query->where('fiscal_year', $activeProfile->fiscal_year))
            ->exists();
        $hasWorkCenter = $municipality->workItems()->exists();
        $readiness = $activeProfile
            ? $this->readiness->evaluate($activeProfile)
            : ($draftProfile ? $this->readiness->evaluate($draftProfile) : null);

        $steps = [
            [
                'key' => 'municipality',
                'title' => 'Completar município',
                'description' => $municipality->hasCompleteProfile()
                    ? 'CNPJ, UF e código IBGE estão prontos para auditoria e integrações.'
                    : 'Complete CNPJ, UF e código IBGE antes de operar oficialmente.',
                'complete' => $municipality->hasCompleteProfile(),
                'route' => route('municipalities.select'),
                'action' => 'Ver vínculo',
                'icon' => 'building-2',
            ],
            [
                'key' => 'rules',
                'title' => 'Ativar normas do exercício',
                'description' => $activeProfile
                    ? "Exercício {$activeProfile->fiscal_year} ativo com cota, saúde e instrumentos mínimos."
                    : 'Ative a Lei Orgânica para liberar cotas, reserva de saúde e Portal Legislativo.',
                'complete' => $activeProfile !== null,
                'route' => route('municipal-rules.index'),
                'action' => $activeProfile ? 'Consultar normas' : 'Ativar exercício',
                'icon' => 'landmark',
            ],
            [
                'key' => 'team',
                'title' => 'Convidar equipe executiva',
                'description' => $hasTeam
                    ? 'Há equipe além do gestor para apoiar execução, controle ou auditoria.'
                    : 'Inclua ao menos um editor, auditor ou consulta para reduzir dependência do gestor.',
                'complete' => $hasTeam,
                'route' => route('users.index'),
                'action' => 'Gerenciar usuários',
                'icon' => 'users',
            ],
            [
                'key' => 'council',
                'title' => 'Convidar Câmara',
                'description' => $hasCouncil
                    ? 'A Câmara já tem usuário legislativo vinculado ao município.'
                    : 'Convide vereadores ou análise legislativa para iniciar as indicações.',
                'complete' => $hasCouncil,
                'route' => route('users.index'),
                'action' => 'Convidar Câmara',
                'icon' => 'landmark',
            ],
            [
                'key' => 'first_flow',
                'title' => 'Criar primeira proposta ou emenda',
                'description' => $hasLegislativeFlow || $hasAmendment
                    ? 'O município já possui fluxo operacional iniciado no exercício.'
                    : 'Use uma proposta legislativa ou uma emenda municipal para validar o caminho completo.',
                'complete' => $hasLegislativeFlow || $hasAmendment,
                'route' => route('legislative.index'),
                'action' => 'Abrir Portal Legislativo',
                'icon' => 'send',
            ],
            [
                'key' => 'work_center',
                'title' => 'Rodar Central de Trabalho',
                'description' => $hasWorkCenter
                    ? 'A Central já consolidou pendências, prazos e ações por perfil.'
                    : 'Sincronize a Central para transformar riscos e pendências em tarefas.',
                'complete' => $hasWorkCenter,
                'route' => route('work-center.index'),
                'action' => 'Abrir Central',
                'icon' => 'clipboard-check',
            ],
        ];

        $complete = count(array_filter($steps, fn (array $step) => $step['complete']));
        $nextStep = collect($steps)->first(fn (array $step) => ! $step['complete']) ?? end($steps);

        return [
            'activeProfile' => $activeProfile,
            'draftProfile' => $draftProfile,
            'year' => $year,
            'steps' => $steps,
            'score' => (int) round($complete / count($steps) * 100),
            'guide' => [
                'next_step' => $nextStep,
                'release_items' => [
                    [
                        'title' => 'Município identificado',
                        'complete' => $municipality->hasCompleteProfile(),
                        'message' => $municipality->hasCompleteProfile()
                            ? 'Dados básicos prontos para auditoria e vínculos.'
                            : 'Complete CNPJ, UF e código IBGE para operar oficialmente.',
                    ],
                    [
                        'title' => 'Exercício ativo',
                        'complete' => $activeProfile !== null,
                        'message' => $activeProfile
                            ? "Norma de {$activeProfile->fiscal_year} ativa para calcular cotas."
                            : 'Informe RCL, cadeiras e revisão jurídica para liberar a Câmara.',
                    ],
                    [
                        'title' => 'Câmara convidada',
                        'complete' => $hasCouncil || $pendingCouncilInvitations->isNotEmpty(),
                        'message' => $hasCouncil || $pendingCouncilInvitations->isNotEmpty()
                            ? 'Há vereador ou convite legislativo vinculado ao município.'
                            : 'Convide pelo menos um vereador após ativar o exercício.',
                    ],
                    [
                        'title' => 'Primeiro fluxo iniciado',
                        'complete' => $hasLegislativeFlow || $hasAmendment,
                        'message' => $hasLegislativeFlow || $hasAmendment
                            ? 'Já existe proposta ou emenda para validar a rotina.'
                            : 'Depois da Câmara liberada, registre a primeira proposta.',
                    ],
                ],
                'activation_fields' => [
                    'Exercício que será usado pela Câmara',
                    'RCL do exercício anterior',
                    'Quantidade de cadeiras da Câmara',
                    'Responsável pela revisão jurídica',
                    'Referência do parecer ou despacho',
                    'Data da revisão',
                ],
            ],
            'health' => [
                'ready' => $activeProfile !== null && $hasCouncil,
                'rules_score' => $readiness['score'] ?? 0,
                'blockers' => $readiness['blockers'] ?? ['Nenhuma norma ativa ou em preparação foi encontrada.'],
                'warnings' => $readiness['warnings'] ?? [],
            ],
            'council' => [
                'released' => $activeProfile !== null,
                'ready' => $activeProfile !== null && ($councilors->isNotEmpty() || $pendingCouncilInvitations->isNotEmpty()),
                'councilors' => $councilors,
                'reviewers' => $legislativeReviewers,
                'pending_invitations' => $pendingCouncilInvitations,
                'quota_label' => $activeProfile && $activeProfile->previous_year_rcl && $activeProfile->individual_limit_percentage && $activeProfile->councilor_seats
                    ? 'R$ '.number_format(((float) $activeProfile->previous_year_rcl * (float) $activeProfile->individual_limit_percentage / 100) / max(1, (int) $activeProfile->councilor_seats), 2, ',', '.')
                    : 'A configurar',
                'health_label' => $activeProfile?->health_reserve_percentage
                    ? number_format((float) $activeProfile->health_reserve_percentage, 2, ',', '.').'%'
                    : 'A configurar',
            ],
        ];
    }
}
