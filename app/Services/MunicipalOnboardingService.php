<?php

namespace App\Services;

use App\Models\Municipality;
use App\Models\MunicipalityInvitation;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\AuditLog;
use App\Models\IntegrityAlert;
use App\Models\LegislativeProposal;
use App\Models\ParliamentaryAmendment;
use App\Models\SupportOccurrence;
use App\Models\User;

class MunicipalOnboardingService
{
    public function __construct(
        private readonly MunicipalRegulatoryReadiness $readiness,
    ) {}

    /** @return array{activeProfile: ?MunicipalRegulatoryProfile, draftProfile: ?MunicipalRegulatoryProfile, year: int, steps: array<int, array{key: string, title: string, description: string, complete: bool, route: string, action: string, icon: string}>, score: int, guide: array<string, mixed>, health: array<string, mixed>, council: array<string, mixed>, commercial: array<string, mixed>} */
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
        $termStartYear = $year - (($year - 2025) % 4 + 4) % 4;
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
        $pilot = $this->pilotReadiness($municipality, $activeProfile, $hasCouncil, $hasWorkCenter);
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
                        'title' => 'Câmara habilitada',
                        'complete' => $hasCouncil,
                        'message' => $hasCouncil
                            ? 'Há vereador ou análise legislativa com acesso aceito.'
                            : ($pendingCouncilInvitations->isNotEmpty()
                                ? 'Convite enviado. Aguardando aceite para habilitar a Câmara.'
                                : 'Convide pelo menos um vereador após ativar o exercício.'),
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
                'legislative_term_start' => $termStartYear.'-01-01',
                'legislative_term_end' => ($termStartYear + 3).'-12-31',
            ],
            'health' => [
                'ready' => $activeProfile !== null && $hasCouncil,
                'rules_score' => $readiness['score'] ?? 0,
                'blockers' => $readiness['blockers'] ?? ['Nenhuma norma ativa ou em preparação foi encontrada.'],
                'warnings' => $readiness['warnings'] ?? [],
            ],
            'pilot' => $pilot,
            'operationalHealth' => $this->operationalHealth($municipality, $activeProfile, $hasCouncil, $hasWorkCenter),
            'initialImport' => $this->initialImport($municipality),
            'profileTrails' => $this->profileTrails($municipality, $activeProfile, $hasCouncil),
            'defense' => $this->defensePackage($municipality),
            'parameters' => $this->municipalParameters($municipality, $activeProfile),
            'council' => [
                'released' => $activeProfile !== null,
                'ready' => $activeProfile !== null && $councilors->isNotEmpty(),
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
            'commercial' => $this->commercialOnboarding($municipality, $activeProfile, $hasCouncil, $hasLegislativeFlow || $hasAmendment, $hasWorkCenter),
        ];
    }

    /** @return array<string, mixed> */
    private function operationalHealth(
        Municipality $municipality,
        ?MunicipalRegulatoryProfile $activeProfile,
        bool $hasCouncil,
        bool $hasWorkCenter,
    ): array {
        $openAlerts = $municipality->integrityAlerts()
            ->where('status', IntegrityAlert::STATUS_OPEN)
            ->get();
        $activeWork = $municipality->workItems()
            ->whereIn('status', ['pending', 'in_progress'])
            ->get();
        $withoutResponsible = $municipality->amendments()
            ->whereNull('responsible_user_id')
            ->count();
        $lateWork = $activeWork
            ->filter(fn ($item): bool => $item->due_at !== null && $item->due_at->isPast())
            ->count();
        $score = 100;
        $score -= $activeProfile ? 0 : 25;
        $score -= $hasCouncil ? 0 : 18;
        $score -= $hasWorkCenter ? 0 : 12;
        $score -= min(20, $openAlerts->count() * 4);
        $score -= min(15, $lateWork * 5);
        $score -= min(10, $withoutResponsible * 2);
        $score = max(0, $score);

        return [
            'score' => $score,
            'tone' => $score >= 80 ? 'ready' : ($score >= 55 ? 'attention' : 'blocked'),
            'summary' => $score >= 80
                ? 'Município pronto para operar com segurança.'
                : ($score >= 55 ? 'Município operável, mas com pontos de atenção.' : 'Ainda há bloqueios antes do uso real.'),
            'cards' => [
                [
                    'label' => 'Exercício ativo',
                    'value' => $activeProfile ? (string) $activeProfile->fiscal_year : 'Não',
                    'hint' => $activeProfile ? 'Cotas e saúde liberadas.' : 'Ative as normas municipais.',
                    'icon' => 'landmark',
                    'tone' => $activeProfile ? 'ready' : 'blocked',
                    'route' => route('municipal-rules.index'),
                    'action' => $activeProfile ? 'Ver normas' : 'Ativar',
                ],
                [
                    'label' => 'Câmara liberada',
                    'value' => $hasCouncil ? 'Sim' : 'Não',
                    'hint' => $hasCouncil ? 'Há vínculo legislativo.' : 'Convide vereador ou revisor.',
                    'icon' => 'users',
                    'tone' => $hasCouncil ? 'ready' : 'attention',
                    'route' => route('users.index'),
                    'action' => 'Gerenciar',
                ],
                [
                    'label' => 'Prazos críticos',
                    'value' => (string) $lateWork,
                    'hint' => $lateWork > 0 ? 'Há ações atrasadas.' : 'Sem atrasos na Central.',
                    'icon' => 'timer-reset',
                    'tone' => $lateWork > 0 ? 'blocked' : 'ready',
                    'route' => route('work-center.index', ['status' => 'active']),
                    'action' => 'Ver prazos',
                ],
                [
                    'label' => 'Alertas abertos',
                    'value' => (string) $openAlerts->count(),
                    'hint' => $openAlerts->isNotEmpty() ? 'Integridade exige revisão.' : 'Nenhum alerta aberto.',
                    'icon' => 'shield-alert',
                    'tone' => $openAlerts->isNotEmpty() ? 'attention' : 'ready',
                    'route' => route('alerts.index'),
                    'action' => 'Ver alertas',
                ],
                [
                    'label' => 'Sem responsável',
                    'value' => (string) $withoutResponsible,
                    'hint' => $withoutResponsible > 0 ? 'Defina responsáveis operacionais.' : 'Responsabilidade organizada.',
                    'icon' => 'user-check',
                    'tone' => $withoutResponsible > 0 ? 'attention' : 'ready',
                    'route' => route('emendas.index'),
                    'action' => 'Ver emendas',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function initialImport(Municipality $municipality): array
    {
        $lastBatch = $municipality->amendmentImportBatches()->latest()->first();
        $enabled = $municipality->moduleEnabled('spreadsheet_import');

        return [
            'enabled' => $enabled,
            'route' => $enabled ? route('spreadsheet-imports.index') : route('municipal-rules.index'),
            'action' => $enabled ? 'Importar planilha' : 'Habilitar importação',
            'last_batch' => $lastBatch,
            'steps' => [
                ['title' => 'Baixar modelo municipal', 'description' => 'Use o CSV simplificado para vereadores, propostas antigas e valores.'],
                ['title' => 'Preencher dados reais', 'description' => 'Traga referência, autor, objeto, secretaria, valor e situação atual.'],
                ['title' => 'Pré-visualizar', 'description' => 'O sistema separa linhas válidas, duplicadas e inválidas antes de gravar.'],
                ['title' => 'Confirmar importação', 'description' => 'Somente linhas aptas entram no banco, com auditoria do lote.'],
            ],
            'coverage' => [
                ['label' => 'Vereadores', 'status' => 'modelo'],
                ['label' => 'Secretarias', 'status' => 'modelo'],
                ['label' => 'Propostas antigas', 'status' => 'modelo'],
                ['label' => 'Beneficiários', 'status' => 'modelo'],
                ['label' => 'Valores e status', 'status' => 'modelo'],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function profileTrails(Municipality $municipality, ?MunicipalRegulatoryProfile $activeProfile, bool $hasCouncil): array
    {
        $hasProposal = $municipality->legislativeProposals()->exists();
        $hasReserved = $municipality->legislativeProposals()
            ->where('status', LegislativeProposal::STATUS_RESERVED)
            ->exists();
        $hasExecution = $municipality->executionStages()->exists();
        $hasAccountability = $municipality->accountabilityProcesses()->exists();

        return [
            [
                'role' => 'Gestor municipal',
                'icon' => 'briefcase-business',
                'description' => 'Libera normas, equipe, Câmara e acompanha o risco geral.',
                'route' => route('municipal-onboarding.index'),
                'action' => 'Abrir implantação',
                'items' => [
                    ['label' => 'Ativar exercício', 'done' => $activeProfile !== null],
                    ['label' => 'Convidar equipe e Câmara', 'done' => $hasCouncil],
                    ['label' => 'Rodar Central de Trabalho', 'done' => $municipality->workItems()->exists()],
                ],
            ],
            [
                'role' => 'Vereador',
                'icon' => 'landmark',
                'description' => 'Cria proposta simples e acompanha saldo, saúde e andamento.',
                'route' => route('legislative.index'),
                'action' => 'Abrir Portal',
                'items' => [
                    ['label' => 'Ver cota disponível', 'done' => $activeProfile !== null],
                    ['label' => 'Criar proposta', 'done' => $hasProposal],
                    ['label' => 'Acompanhar Executivo', 'done' => $hasReserved],
                ],
            ],
            [
                'role' => 'Executivo',
                'icon' => 'building-2',
                'description' => 'Recebe protocolo, registra reserva e abre plano/execução.',
                'route' => route('legislative.index'),
                'action' => 'Abrir Mesa',
                'items' => [
                    ['label' => 'Receber proposta', 'done' => $hasReserved],
                    ['label' => 'Registrar reserva', 'done' => $hasReserved],
                    ['label' => 'Acompanhar execução', 'done' => $hasExecution],
                ],
            ],
            [
                'role' => 'Controle interno',
                'icon' => 'shield-check',
                'description' => 'Confere evidências, alertas, trilha de auditoria e prestação.',
                'route' => route('work-center.index'),
                'action' => 'Abrir pendências',
                'items' => [
                    ['label' => 'Tratar alertas', 'done' => $municipality->integrityAlerts()->where('status', IntegrityAlert::STATUS_OPEN)->doesntExist()],
                    ['label' => 'Revisar execução', 'done' => $hasExecution],
                    ['label' => 'Fechar prestação', 'done' => $hasAccountability],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function defensePackage(Municipality $municipality): array
    {
        $auditCount = AuditLog::query()
            ->where('municipality_id', $municipality->id)
            ->count();
        $latestAudit = AuditLog::query()
            ->where('municipality_id', $municipality->id)
            ->latest()
            ->first();
        $documents = $municipality->documents()->count();
        $officialDocuments = $municipality->officialDocuments()->count();
        $reports = $municipality->governanceReports()->count() + $municipality->specializedReports()->count();

        return [
            'score' => min(100, ($auditCount > 0 ? 25 : 0) + ($documents > 0 ? 25 : 0) + ($officialDocuments > 0 ? 20 : 0) + ($reports > 0 ? 15 : 0) + ($municipality->document_checklist_enabled ? 15 : 0)),
            'latest_audit' => $latestAudit,
            'items' => [
                ['label' => 'Trilhas de auditoria', 'value' => (string) $auditCount, 'hint' => 'Quem fez, quando, IP e antes/depois.'],
                ['label' => 'Documentos anexados', 'value' => (string) $documents, 'hint' => 'Arquivos privados vinculados às emendas.'],
                ['label' => 'Comunicações oficiais', 'value' => (string) $officialDocuments, 'hint' => 'Ofícios, pareceres e protocolos municipais.'],
                ['label' => 'Relatórios emitidos', 'value' => (string) $reports, 'hint' => 'Governança, divergências e saúde.'],
            ],
            'route' => route('security-privacy.index'),
            'action' => 'Ver LGPD e defesa',
        ];
    }

    /** @return array<string, mixed> */
    private function municipalParameters(Municipality $municipality, ?MunicipalRegulatoryProfile $activeProfile): array
    {
        $modules = collect(Municipality::moduleParameters())
            ->map(function (array $module, string $key) use ($municipality): array {
                return [
                    'key' => $key,
                    'label' => $module['label'],
                    'description' => $module['description'],
                    'enabled' => $municipality->moduleEnabled($key),
                    'automatic' => (bool) ($module['automatic'] ?? false),
                    'paulista' => (bool) ($module['paulista'] ?? false),
                ];
            })
            ->values()
            ->all();

        return [
            'route' => route('municipal-rules.index'),
            'active_profile' => $activeProfile,
            'modules' => $modules,
            'enabled_count' => count(array_filter($modules, fn (array $module): bool => $module['enabled'])),
            'total_count' => count($modules),
            'rules' => [
                ['label' => 'Lei Orgânica', 'value' => $activeProfile ? 'Ativa' : 'Pendente'],
                ['label' => 'RCL anterior', 'value' => $activeProfile?->previous_year_rcl ? 'R$ '.number_format((float) $activeProfile->previous_year_rcl, 2, ',', '.') : 'A configurar'],
                ['label' => 'Cadeiras da Câmara', 'value' => $activeProfile?->councilor_seats ?: 'A configurar'],
                ['label' => 'Reserva saúde', 'value' => $activeProfile?->health_reserve_percentage ? number_format((float) $activeProfile->health_reserve_percentage, 2, ',', '.').'%' : 'Automática ao ativar'],
                ['label' => 'TCESP/Audesp', 'value' => $municipality->moduleEnabled('tcesp_audesp') ? 'Ativo para SP' : 'Fora do escopo automático'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function pilotReadiness(
        Municipality $municipality,
        ?MunicipalRegulatoryProfile $activeProfile,
        bool $hasCouncil,
        bool $hasWorkCenter,
    ): array {
        $year = $activeProfile?->fiscal_year ?? now()->year + 1;
        $proposalQuery = $municipality->legislativeProposals()->where('fiscal_year', $year);
        $amendmentQuery = $municipality->amendments()->where('fiscal_year', $year);
        $proposal = (clone $proposalQuery)->latest()->first();
        $amendment = (clone $amendmentQuery)->with(['executionStages', 'accountabilityProcess', 'documents'])->latest()->first();
        $hasProposal = $proposal !== null;
        $hasLegislativeReview = (clone $proposalQuery)
            ->whereIn('status', [
                LegislativeProposal::STATUS_APPROVED,
                LegislativeProposal::STATUS_REJECTED,
                LegislativeProposal::STATUS_SENT,
                LegislativeProposal::STATUS_RECEIVED,
                LegislativeProposal::STATUS_RESERVED,
            ])
            ->exists();
        $hasProtocol = (clone $proposalQuery)
            ->whereIn('status', [
                LegislativeProposal::STATUS_SENT,
                LegislativeProposal::STATUS_RECEIVED,
                LegislativeProposal::STATUS_RESERVED,
            ])
            ->exists();
        $hasExecutiveReceive = (clone $proposalQuery)
            ->whereIn('status', [
                LegislativeProposal::STATUS_RECEIVED,
                LegislativeProposal::STATUS_RESERVED,
            ])
            ->exists();
        $hasBudgetReserve = (clone $proposalQuery)
            ->where('status', LegislativeProposal::STATUS_RESERVED)
            ->exists();
        $hasExecution = (clone $amendmentQuery)
            ->where(function ($query) {
                $query->whereIn('status', [
                    ParliamentaryAmendment::STATUS_EXECUTING,
                    ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING,
                    ParliamentaryAmendment::STATUS_COMPLETED,
                ])->orWhereHas('executionStages');
            })
            ->exists();
        $hasAccountability = (clone $amendmentQuery)->whereHas('accountabilityProcess')->exists();
        $hasDossierEvidence = (clone $amendmentQuery)->whereHas('documents')->exists()
            || (clone $amendmentQuery)->whereHas('accountabilityProcess', fn ($query) => $query->whereNotNull('protocol_number'))->exists();
        $hasOccurrencesChecked = SupportOccurrence::query()
            ->where(function ($query) use ($municipality) {
                $query->where('municipality_id', $municipality->id)->orWhereNull('municipality_id');
            })
            ->exists();

        $checks = [
            [
                'key' => 'municipality',
                'title' => 'Município configurado',
                'description' => $municipality->hasCompleteProfile()
                    ? 'Dados institucionais mínimos prontos para demonstração.'
                    : 'Complete CNPJ, UF e código IBGE antes da apresentação.',
                'complete' => $municipality->hasCompleteProfile(),
                'status' => $municipality->hasCompleteProfile() ? 'ready' : 'blocked',
                'route' => route('municipalities.select'),
                'action' => 'Ver município',
                'icon' => 'building-2',
            ],
            [
                'key' => 'rules',
                'title' => 'Norma ativa',
                'description' => $activeProfile
                    ? "Exercício {$activeProfile->fiscal_year} ativo com cota e reserva de saúde."
                    : 'Ative o exercício para liberar Câmara, cotas e bloqueios automáticos.',
                'complete' => $activeProfile !== null,
                'status' => $activeProfile ? 'ready' : 'blocked',
                'route' => route('municipal-onboarding.index'),
                'action' => 'Ativar exercício',
                'icon' => 'landmark',
            ],
            [
                'key' => 'council',
                'title' => 'Câmara liberada',
                'description' => $hasCouncil
                    ? 'Há vereador ou revisor legislativo vinculado ao município.'
                    : 'Convide ao menos um vereador para validar o fluxo real da Câmara.',
                'complete' => $hasCouncil,
                'status' => $hasCouncil ? 'ready' : ($activeProfile ? 'attention' : 'blocked'),
                'route' => route('users.index'),
                'action' => 'Convidar Câmara',
                'icon' => 'users',
            ],
            [
                'key' => 'proposal',
                'title' => 'Proposta legislativa criada',
                'description' => $hasProposal
                    ? 'Existe proposta para demonstrar a visão do vereador.'
                    : 'Crie uma proposta teste pelo Portal Legislativo.',
                'complete' => $hasProposal,
                'status' => $hasProposal ? 'ready' : ($hasCouncil ? 'attention' : 'blocked'),
                'route' => route('legislative.index'),
                'action' => 'Abrir Portal',
                'icon' => 'send',
            ],
            [
                'key' => 'review',
                'title' => 'Conferência da Câmara',
                'description' => $hasLegislativeReview
                    ? 'A proposta já passou por decisão legislativa.'
                    : 'Aprove, devolva ou rejeite uma proposta para validar a etapa da Câmara.',
                'complete' => $hasLegislativeReview,
                'status' => $hasLegislativeReview ? 'ready' : ($hasProposal ? 'attention' : 'blocked'),
                'route' => route('legislative.index'),
                'action' => 'Conferir proposta',
                'icon' => 'badge-check',
            ],
            [
                'key' => 'protocol',
                'title' => 'Protocolo ao Executivo',
                'description' => $hasProtocol
                    ? 'A Câmara já encaminhou proposta ao Executivo.'
                    : 'Protocole uma proposta aprovada para mostrar a transição Câmara-Executivo.',
                'complete' => $hasProtocol,
                'status' => $hasProtocol ? 'ready' : ($hasLegislativeReview ? 'attention' : 'blocked'),
                'route' => route('legislative.index'),
                'action' => 'Protocolar',
                'icon' => 'file-check-2',
            ],
            [
                'key' => 'receive',
                'title' => 'Executivo recebeu',
                'description' => $hasExecutiveReceive
                    ? 'O Executivo recebeu formalmente a proposta.'
                    : 'Receba a proposta no painel executivo para validar a fila municipal.',
                'complete' => $hasExecutiveReceive,
                'status' => $hasExecutiveReceive ? 'ready' : ($hasProtocol ? 'attention' : 'blocked'),
                'route' => route('legislative.index'),
                'action' => 'Receber proposta',
                'icon' => 'inbox',
            ],
            [
                'key' => 'reserve',
                'title' => 'Reserva orçamentária',
                'description' => $hasBudgetReserve
                    ? 'Reserva registrada para demonstrar disponibilidade orçamentária.'
                    : 'Registre reserva para provar o controle de saldo e saúde.',
                'complete' => $hasBudgetReserve,
                'status' => $hasBudgetReserve ? 'ready' : ($hasExecutiveReceive ? 'attention' : 'blocked'),
                'route' => route('legislative.index'),
                'action' => 'Reservar',
                'icon' => 'wallet-cards',
            ],
            [
                'key' => 'execution',
                'title' => 'Execução iniciada',
                'description' => $hasExecution
                    ? 'Há emenda em execução ou etapa cadastrada.'
                    : 'Inicie a execução simplificada de uma emenda reservada.',
                'complete' => $hasExecution,
                'status' => $hasExecution ? 'ready' : ($hasBudgetReserve || $amendment ? 'attention' : 'blocked'),
                'route' => $amendment ? route('emendas.execution', $amendment) : route('emendas.index'),
                'action' => 'Abrir execução',
                'icon' => 'gauge',
            ],
            [
                'key' => 'accountability',
                'title' => 'Prestação preparada',
                'description' => $hasAccountability
                    ? 'Existe prestação de contas vinculada a uma emenda.'
                    : 'Prepare a prestação final para validar documentos, prazos e recibo.',
                'complete' => $hasAccountability,
                'status' => $hasAccountability ? 'ready' : ($hasExecution ? 'attention' : 'blocked'),
                'route' => $amendment ? route('emendas.accountability', $amendment) : route('emendas.index'),
                'action' => 'Abrir prestação',
                'icon' => 'archive',
            ],
            [
                'key' => 'dossier',
                'title' => 'Dossiê ou evidência gerada',
                'description' => $hasDossierEvidence
                    ? 'Há documento, protocolo ou evidência para demonstrar rastreabilidade.'
                    : 'Anexe evidência ou gere protocolo para fechar a apresentação.',
                'complete' => $hasDossierEvidence,
                'status' => $hasDossierEvidence ? 'ready' : ($hasAccountability ? 'attention' : 'blocked'),
                'route' => $amendment ? route('emendas.accountability', $amendment) : route('emendas.index'),
                'action' => 'Ver dossiê',
                'icon' => 'folder-check',
            ],
            [
                'key' => 'operations',
                'title' => 'Monitoramento e ocorrências',
                'description' => $hasOccurrencesChecked
                    ? 'A Central de Ocorrências já possui leitura para suporte.'
                    : 'Abra a Central de Ocorrências para validar suporte e pós-deploy.',
                'complete' => $hasOccurrencesChecked,
                'status' => $hasOccurrencesChecked ? 'ready' : 'attention',
                'route' => route('occurrences.index'),
                'action' => 'Ver ocorrências',
                'icon' => 'bug',
            ],
        ];

        $complete = count(array_filter($checks, fn (array $check): bool => $check['complete']));
        $next = collect($checks)->first(fn (array $check): bool => ! $check['complete']) ?? end($checks);

        return [
            'year' => $year,
            'ready' => $complete === count($checks),
            'score' => (int) round($complete / count($checks) * 100),
            'complete' => $complete,
            'total' => count($checks),
            'next' => $next,
            'checks' => $checks,
            'has_work_center' => $hasWorkCenter,
            'demo_amendment' => $amendment,
        ];
    }

    /** @return array<string, mixed> */
    private function commercialOnboarding(
        Municipality $municipality,
        ?MunicipalRegulatoryProfile $activeProfile,
        bool $hasCouncil,
        bool $hasFirstFlow,
        bool $hasWorkCenter,
    ): array {
        $hasDemoBase = $municipality->hasCompleteProfile() && $activeProfile !== null && $hasCouncil && $hasFirstFlow;
        $readyToPitch = $hasDemoBase && $hasWorkCenter;
        $steps = [
            [
                'title' => 'Preparar ambiente de demonstração',
                'description' => 'Município, exercício, cotas, Câmara e exemplos precisam estar prontos antes da reunião.',
                'complete' => $hasDemoBase,
                'route' => route('municipal-onboarding.index'),
                'action' => 'Ver implantação',
                'icon' => 'monitor-check',
            ],
            [
                'title' => 'Validar a dor com o cliente',
                'description' => 'Confirmar como Câmara, Executivo, contabilidade e controle interno tratam emendas hoje.',
                'complete' => false,
                'route' => route('work-center.index'),
                'action' => 'Abrir roteiro',
                'icon' => 'messages-square',
            ],
            [
                'title' => 'Coletar materiais reais',
                'description' => 'Lei Orgânica, RCL, número de cadeiras, modelos de ofício, planilhas e exemplo de execução.',
                'complete' => false,
                'route' => route('municipal-rules.index'),
                'action' => 'Conferir normas',
                'icon' => 'folder-check',
            ],
            [
                'title' => 'Executar piloto controlado',
                'description' => 'Rodar uma proposta da Câmara até reserva, execução e prestação final com acompanhamento semanal.',
                'complete' => false,
                'route' => route('legislative.index'),
                'action' => 'Abrir fluxo',
                'icon' => 'route',
            ],
            [
                'title' => 'Medir resultado para venda',
                'description' => 'Registrar tempo economizado, pendências evitadas, documentos completos e riscos encontrados.',
                'complete' => $readyToPitch,
                'route' => route('governance-reports.index'),
                'action' => 'Ver relatórios',
                'icon' => 'chart-no-axes-combined',
            ],
        ];

        $complete = count(array_filter($steps, fn (array $step): bool => $step['complete']));

        return [
            'ready' => $readyToPitch,
            'score' => (int) round($complete / count($steps) * 100),
            'headline' => $readyToPitch
                ? 'Ambiente pronto para apresentação comercial'
                : 'Transforme a implantação em demonstração comercial',
            'description' => $readyToPitch
                ? 'O município já possui fluxo, Câmara e Central de Trabalho suficientes para uma reunião de validação.'
                : 'Use esta trilha para sair de uma tela técnica e chegar em uma apresentação que o prefeito, vereador e controle interno entendem.',
            'steps' => $steps,
            'meeting_script' => [
                'Comece pela dor: planilhas, mensagens soltas, prazos e falta de visão do saldo.',
                'Mostre o vereador criando proposta com cota e saúde calculadas automaticamente.',
                'Mostre o Executivo recebendo, reservando orçamento e acompanhando execução.',
                'Feche na prestação de contas com dossiê, evidências, protocolo e trilha de auditoria.',
            ],
            'materials' => [
                'Lei Orgânica ou ato local que institui as emendas.',
                'RCL do exercício anterior e número oficial de cadeiras da Câmara.',
                'Planilha ou lista real de indicações dos vereadores.',
                'Modelo de ofício, protocolo, parecer ou relatório usado pelo município.',
                'Arquivo de teste do Siafic/Audesp quando o município for paulista.',
            ],
        ];
    }
}
