<?php

namespace App\Http\Controllers;

use App\Models\LegislativeProposal;
use App\Models\MunicipalInstitution;
use App\Models\Municipality;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\CouncilorPortalSummaryService;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\LegislativeNotificationService;
use App\Services\LegislativeProposalService;
use App\Services\MunicipalTransparencyTrail;
use App\Services\MunicipalWorkItemService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LegislativeProposalController extends Controller
{
    public function index(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        LegislativeProposalService $service,
        CouncilorPortalSummaryService $councilorPortal,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $role = $request->user()->roleForMunicipality($municipality->id);
        $year = ctype_digit((string) $request->query('year')) ? (int) $request->query('year') : $service->defaultYear($municipality);
        $status = (string) $request->query('status');
        $search = trim((string) $request->query('search'));
        $author = trim((string) $request->query('author'));
        $department = trim((string) $request->query('department'));
        $health = (string) $request->query('health');
        $membership = $request->user()->municipalities()->whereKey($municipality->id)->firstOrFail()->pivot;
        $filterBase = $municipality->legislativeProposals()
            ->where('fiscal_year', $year)
            ->when($role === User::ROLE_COUNCILOR, fn ($query) => $query->where('submitted_by', $request->user()->id));
        $query = $municipality->legislativeProposals()
            ->with(['submitter:id,name', 'amendment:id,reference,status'])
            ->where('fiscal_year', $year)
            ->when($role === User::ROLE_COUNCILOR, fn ($query) => $query->where('submitted_by', $request->user()->id))
            ->when(array_key_exists($status, LegislativeProposal::statuses()), fn ($query) => $query->where('status', $status))
            ->when($author !== '', fn ($query) => $query->where('author_name', $author))
            ->when($department !== '', fn ($query) => $query->where('responsible_department', $department))
            ->when(in_array($health, ['yes', 'no'], true), fn ($query) => $query->where('health_related', $health === 'yes'))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('reference', 'like', "%{$search}%")
                    ->orWhere('author_name', 'like', "%{$search}%")
                    ->orWhere('object', 'like', "%{$search}%")
                    ->orWhere('beneficiary_name', 'like', "%{$search}%");
            }));
        $summaryQuery = clone $query;
        $boardQuery = clone $query;
        $profile = $service->profile($municipality, $year);
        $quota = $role === User::ROLE_COUNCILOR && $profile
            ? $service->quota($municipality, $profile, (string) ($membership->legislative_name ?: $request->user()->name))
            : null;
        $councilorGuide = $role === User::ROLE_COUNCILOR
            ? $councilorPortal->build($municipality, $request->user()->id, $year, $profile, $quota)
            : null;
        $councilorGroups = $role === User::ROLE_COUNCILOR
            ? $this->councilorProposalGroups($municipality, $request->user()->id, $year, $councilorGuide)
            : null;
        $executiveBoard = $role === User::ROLE_COUNCILOR
            ? null
            : $this->executiveBoard($boardQuery->latest('id')->get(), $year);
        $executiveDesk = $executiveBoard
            ? $this->executiveDesk($executiveBoard, $request, $formSubmission, $profile, $role)
            : null;

        return view('legislative.index', [
            'municipality' => $municipality,
            'role' => $role,
            'membership' => $membership,
            'year' => $year,
            'selectedStatus' => $status,
            'selectedAuthor' => $author,
            'selectedDepartment' => $department,
            'selectedHealth' => $health,
            'search' => $search,
            'statuses' => LegislativeProposal::statuses(),
            'filterOptions' => [
                'authors' => (clone $filterBase)->select('author_name')->distinct()->orderBy('author_name')->pluck('author_name')->filter()->values(),
                'departments' => (clone $filterBase)->select('responsible_department')->distinct()->orderBy('responsible_department')->pluck('responsible_department')->filter()->values(),
            ],
            'activeYears' => $service->activeYears($municipality),
            'profile' => $profile,
            'quota' => $quota,
            'councilorGuide' => $councilorGuide,
            'councilorGroups' => $councilorGroups,
            'executiveBoard' => $executiveBoard,
            'executiveDesk' => $executiveDesk,
            'proposals' => $query->latest('id')->paginate(12)->withQueryString(),
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'amount' => (float) (clone $summaryQuery)->sum('estimated_amount'),
                'pending' => (clone $summaryQuery)->whereIn('status', [LegislativeProposal::STATUS_SUBMITTED, LegislativeProposal::STATUS_APPROVED])->count(),
                'sent' => (clone $summaryQuery)->whereIn('status', [LegislativeProposal::STATUS_SENT, LegislativeProposal::STATUS_RECEIVED, LegislativeProposal::STATUS_RESERVED])->count(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $quota
     * @return array<string, mixed>
     */
    private function councilorGuide(
        Municipality $municipality,
        int $userId,
        int $year,
        ?MunicipalRegulatoryProfile $profile,
        ?array $quota,
    ): array {
        $statusBase = $municipality->legislativeProposals()
            ->where('fiscal_year', $year)
            ->where('submitted_by', $userId);
        $statusCounts = [];
        foreach (array_keys(LegislativeProposal::statuses()) as $status) {
            $statusCounts[$status] = (clone $statusBase)->where('status', $status)->count();
        }

        $remaining = $quota['remaining'] ?? null;
        $countLimit = $quota['count_limit'] ?? null;
        $count = (int) ($quota['count'] ?? 0);
        $quotaUsed = (float) ($quota['used'] ?? 0);
        $quotaCeiling = $quota['author_ceiling'] ?? null;
        $healthAllocated = (float) ($quota['health_allocated'] ?? 0);
        $healthRequired = $quota['health_required'] ?? null;
        $healthGap = $quota['health_gap'] ?? null;
        $minimumAmount = $profile?->minimum_amendment_amount === null ? null : (float) $profile->minimum_amendment_amount;

        $canCreate = $profile !== null
            && ($remaining === null || (float) $remaining > 0.005)
            && ($minimumAmount === null || $remaining === null || (float) $remaining + 0.005 >= $minimumAmount)
            && ($countLimit === null || $count < (int) $countLimit);
        $quotaProgress = $quotaCeiling ? min(100, round($quotaUsed / (float) $quotaCeiling * 100)) : null;
        $healthProgress = $healthRequired ? min(100, round($healthAllocated / (float) $healthRequired * 100)) : null;

        if ($profile === null) {
            $nextTitle = 'Aguardando ativação do exercício';
            $nextText = 'O gestor municipal precisa ativar a norma para liberar cota, reserva de saúde e cadastro de propostas.';
            $badge = 'Bloqueado';
            $badgeTone = 'danger';
        } elseif ($countLimit !== null && $count >= (int) $countLimit) {
            $nextTitle = 'Limite de propostas atingido';
            $nextText = 'Revise as propostas existentes ou aguarde orientação da equipe municipal antes de cadastrar outra.';
            $badge = 'Cota encerrada';
            $badgeTone = 'warning';
        } elseif ($remaining !== null && (float) $remaining <= 0.005) {
            $nextTitle = 'Saldo individual esgotado';
            $nextText = 'Todas as novas indicações precisam respeitar o teto da Lei Orgânica definido para o exercício.';
            $badge = 'Sem saldo';
            $badgeTone = 'warning';
        } elseif ($minimumAmount !== null && $remaining !== null && (float) $remaining + 0.005 < $minimumAmount) {
            $nextTitle = 'Saldo abaixo do mínimo';
            $nextText = 'A norma municipal exige proposta mínima de R$ '.number_format($minimumAmount, 2, ',', '.').', acima do saldo disponível.';
            $badge = 'Sem valor mínimo';
            $badgeTone = 'warning';
        } elseif ($healthGap !== null && (float) $healthGap > 0.005) {
            $nextTitle = 'Priorize uma proposta de saúde';
            $nextText = 'Para protocolar sem bloqueio, direcione pelo menos R$ '.number_format((float) $healthGap, 2, ',', '.').' para saúde.';
            $badge = 'Saúde pendente';
            $badgeTone = 'warning';
        } else {
            $nextTitle = 'Pronto para indicar';
            $nextText = 'Informe objeto, beneficiário, valor estimado e envie para conferência legislativa.';
            $badge = 'Pode indicar';
            $badgeTone = 'success';
        }

        return [
            'canCreate' => $canCreate,
            'quotaProgress' => $quotaProgress,
            'healthProgress' => $healthProgress,
            'nextTitle' => $nextTitle,
            'nextText' => $nextText,
            'badge' => $badge,
            'badgeTone' => $badgeTone,
            'simpleCards' => [
                [
                    'icon' => 'wallet-cards',
                    'label' => 'Saldo para novas propostas',
                    'value' => $remaining === null ? 'A configurar' : 'R$ '.number_format((float) $remaining, 2, ',', '.'),
                    'description' => 'Valor ainda livre dentro da sua cota individual.',
                    'tone' => $remaining !== null && (float) $remaining <= 0.005 ? 'warning' : 'neutral',
                ],
                [
                    'icon' => 'heart-pulse',
                    'label' => 'Saúde obrigatória',
                    'value' => $healthGap !== null && (float) $healthGap > 0.005
                        ? 'Faltam R$ '.number_format((float) $healthGap, 2, ',', '.')
                        : 'Em dia',
                    'description' => 'O sistema calcula a reserva mínima pela norma municipal ativa.',
                    'tone' => $healthGap !== null && (float) $healthGap > 0.005 ? 'warning' : 'success',
                ],
                [
                    'icon' => 'file-plus-2',
                    'label' => 'Seu próximo passo',
                    'value' => $nextTitle,
                    'description' => $nextText,
                    'tone' => $badgeTone,
                ],
            ],
            'plainChecklist' => [
                [
                    'label' => 'Saldo disponível',
                    'done' => $remaining !== null && (float) $remaining > 0.005,
                    'detail' => $remaining === null ? 'Aguardando norma ativa' : 'R$ '.number_format((float) $remaining, 2, ',', '.'),
                ],
                [
                    'label' => 'Regra municipal ativa',
                    'done' => $profile !== null,
                    'detail' => $profile ? 'Exercício '.$profile->fiscal_year.' liberado' : 'Gestor precisa ativar',
                ],
                [
                    'label' => 'Saúde calculada',
                    'done' => $healthGap === null || (float) $healthGap <= 0.005,
                    'detail' => $healthGap !== null && (float) $healthGap > 0.005 ? 'Priorize saúde' : 'Sem bloqueio',
                ],
                [
                    'label' => 'Pode enviar',
                    'done' => $canCreate,
                    'detail' => $canCreate ? 'Formulário liberado' : $badge,
                ],
            ],
            'statusCounts' => $statusCounts,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $guide
     * @return array<string, mixed>
     */
    private function councilorProposalGroups(
        Municipality $municipality,
        int $userId,
        int $year,
        ?array $guide,
    ): array {
        $proposals = $municipality->legislativeProposals()
            ->where('fiscal_year', $year)
            ->where('submitted_by', $userId)
            ->latest('updated_at')
            ->get();

        $groups = collect([
            [
                'key' => 'action',
                'title' => 'Precisa de você',
                'description' => 'Rascunhos e propostas devolvidas para ajuste antes de seguir.',
                'icon' => 'pencil',
                'statuses' => [LegislativeProposal::STATUS_DRAFT, LegislativeProposal::STATUS_RETURNED],
                'empty' => 'Nenhuma proposta aguardando ajuste seu.',
                'tone' => 'warning',
            ],
            [
                'key' => 'chamber',
                'title' => 'Com a Câmara',
                'description' => 'Itens enviados para conferência, aprovação ou protocolo.',
                'icon' => 'landmark',
                'statuses' => [LegislativeProposal::STATUS_SUBMITTED, LegislativeProposal::STATUS_APPROVED],
                'empty' => 'Nenhuma proposta parada na Câmara.',
                'tone' => 'info',
            ],
            [
                'key' => 'executive',
                'title' => 'Com o Executivo',
                'description' => 'Propostas protocoladas, recebidas ou com reserva aberta pela Prefeitura.',
                'icon' => 'building-2',
                'statuses' => [LegislativeProposal::STATUS_SENT, LegislativeProposal::STATUS_RECEIVED, LegislativeProposal::STATUS_RESERVED],
                'empty' => 'Nenhuma proposta em andamento no Executivo.',
                'tone' => 'success',
            ],
            [
                'key' => 'closed',
                'title' => 'Encerradas',
                'description' => 'Registros rejeitados ou sem continuidade neste fluxo.',
                'icon' => 'archive',
                'statuses' => [LegislativeProposal::STATUS_REJECTED],
                'empty' => 'Nenhuma proposta encerrada.',
                'tone' => 'muted',
            ],
        ])->map(function (array $group) use ($proposals, $year): array {
            $items = $proposals->whereIn('status', $group['statuses'])->values();

            return [
                ...$group,
                'count' => $items->count(),
                'amount' => (float) $items->sum('estimated_amount'),
                'items' => $items->take(3)->map(function (LegislativeProposal $proposal): LegislativeProposal {
                    $nextStep = $this->proposalNextStep($proposal, 'councilor');

                    $proposal->setAttribute('councilor_next_step', $nextStep);
                    $proposal->setAttribute('councilor_action_url', $nextStep['href']);

                    return $proposal;
                })->values(),
                'filter_url' => route('legislative.index', ['year' => $year, 'status' => $group['statuses'][0]]),
                'hidden_count' => max(0, $items->count() - 3),
            ];
        })->all();

        $actionItem = collect($groups)->firstWhere('key', 'action')['items']->first() ?? null;
        $executiveItem = collect($groups)->firstWhere('key', 'executive')['items']->first() ?? null;
        $chamberItem = collect($groups)->firstWhere('key', 'chamber')['items']->first() ?? null;
        $createUrl = ($guide['canCreate'] ?? false) ? route('legislative.create', ['year' => $year]) : null;
        $summary = collect($groups)->mapWithKeys(fn (array $group): array => [
            $group['key'] => [
                'count' => $group['count'],
                'amount' => $group['amount'],
                'url' => $group['filter_url'],
            ],
        ]);

        return [
            'groups' => $groups,
            'summary' => $summary->all(),
            'total_count' => (int) $proposals->count(),
            'total_amount' => (float) $proposals->sum('estimated_amount'),
            'next_url' => $actionItem?->getAttribute('councilor_action_url')
                ?? $createUrl
                ?? $executiveItem?->getAttribute('councilor_action_url')
                ?? $chamberItem?->getAttribute('councilor_action_url'),
            'next_label' => $actionItem ? 'Corrigir proposta'
                : (($guide['canCreate'] ?? false) ? 'Criar proposta'
                    : ($executiveItem ? 'Acompanhar Executivo'
                        : ($chamberItem ? 'Ver tramitação' : 'Ver propostas'))),
        ];
    }

    /**
     * @param  Collection<int, LegislativeProposal>  $proposals
     * @return array<int, array<string, mixed>>
     */
    private function executiveBoard(Collection $proposals, int $year): array
    {
        return collect([
            [
                'key' => 'review',
                'title' => 'Conferência da Câmara',
                'description' => 'Propostas enviadas pelos vereadores ou aprovadas para protocolo.',
                'icon' => 'badge-check',
                'statuses' => [LegislativeProposal::STATUS_SUBMITTED, LegislativeProposal::STATUS_APPROVED],
                'action' => 'Analisar ou protocolar',
                'fragment' => '#conferencia-legislativa',
                'empty' => 'Sem proposta aguardando conferência ou protocolo.',
                'sla_days' => 3,
                'tone' => 'review',
            ],
            [
                'key' => 'receive',
                'title' => 'Receber no Executivo',
                'description' => 'Protocoladas pela Câmara e aguardando processo executivo.',
                'icon' => 'inbox',
                'statuses' => [LegislativeProposal::STATUS_SENT],
                'action' => 'Receber proposta',
                'fragment' => '#recebimento-executivo',
                'empty' => 'Nenhum protocolo aguardando recebimento formal.',
                'sla_days' => 2,
                'tone' => 'receive',
            ],
            [
                'key' => 'budget',
                'title' => 'Reservar orçamento',
                'description' => 'Recebidas e aguardando reserva orçamentária formal.',
                'icon' => 'wallet-cards',
                'statuses' => [LegislativeProposal::STATUS_RECEIVED],
                'action' => 'Registrar reserva',
                'fragment' => '#reserva-orcamentaria',
                'empty' => 'Nenhuma proposta aguardando reserva orçamentária.',
                'sla_days' => 5,
                'tone' => 'budget',
            ],
            [
                'key' => 'execution',
                'title' => 'Acompanhar execução',
                'description' => 'Com reserva registrada e fluxo executivo aberto.',
                'icon' => 'gauge',
                'statuses' => [LegislativeProposal::STATUS_RESERVED],
                'action' => 'Abrir acompanhamento',
                'fragment' => '#acompanhamento-executivo',
                'empty' => 'Nenhuma emenda com reserva para acompanhar.',
                'sla_days' => 7,
                'tone' => 'execution',
            ],
        ])->map(function (array $column) use ($proposals, $year): array {
            $allItems = $proposals->whereIn('status', $column['statuses'])->values();
            $items = $proposals
                ->whereIn('status', $column['statuses'])
                ->take(5)
                ->map(function (LegislativeProposal $proposal) use ($column): LegislativeProposal {
                    $nextStep = $this->proposalNextStep($proposal, 'executive');

                    $proposal->setAttribute('executive_board_url', route('legislative.show', $proposal).$column['fragment']);
                    $proposal->setAttribute('executive_next_step', $nextStep);

                    return $proposal;
                })
                ->values();
            $oldest = $allItems->sortBy('updated_at')->first();

            return [
                ...$column,
                'items' => $items,
                'count' => $allItems->count(),
                'amount' => (float) $proposals->whereIn('status', $column['statuses'])->sum('estimated_amount'),
                'filter_url' => route('legislative.index', ['year' => $year, 'status' => $column['statuses'][0]]),
                'health_count' => $allItems->where('health_related', true)->count(),
                'health_amount' => (float) $allItems->where('health_related', true)->sum('estimated_amount'),
                'oldest' => $oldest,
                'hidden_count' => max(0, $allItems->count() - $items->count()),
                'stale_count' => $allItems->filter(fn (LegislativeProposal $proposal): bool => $this->proposalAgeDays($proposal) >= (int) $column['sla_days'])->count(),
            ];
        })->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $board
     * @return array<string, mixed>
     */
    private function executiveDesk(array $board, Request $request, FormSubmission $formSubmission, ?MunicipalRegulatoryProfile $profile, string $role): array
    {
        $exerciseLockedUrl = $role === User::ROLE_MANAGER
            ? route('municipal-onboarding.index')
            : route('municipal-rules.index');
        $exerciseLocked = $profile === null;
        $actionable = collect($board)->whereIn('key', ['review', 'receive', 'budget']);
        $total = (int) $actionable->sum('count');
        $amount = (float) $actionable->sum('amount');
        $focus = $actionable->first(fn (array $column): bool => (int) $column['count'] > 0);
        $execution = collect($board)->firstWhere('key', 'execution') ?? ['count' => 0, 'amount' => 0, 'health_amount' => 0];
        $staleItems = $actionable
            ->flatMap(fn (array $column) => $column['items']->map(fn (LegislativeProposal $proposal): array => [
                'proposal' => $proposal,
                'column' => $column,
                'age' => $this->proposalAgeDays($proposal),
                'url' => route('legislative.show', $proposal).$column['fragment'],
            ]))
            ->filter(fn (array $item): bool => $item['age'] >= (int) $item['column']['sla_days'])
            ->sortByDesc('age')
            ->take(4)
            ->values();
        $quickActions = $actionable
            ->flatMap(fn (array $column) => $column['items']->map(fn (LegislativeProposal $proposal): array => [
                'proposal' => $proposal,
                'column' => $column,
                'age' => $this->proposalAgeDays($proposal),
                'late' => $this->proposalAgeDays($proposal) >= (int) $column['sla_days'],
                'url' => route('legislative.show', $proposal).$column['fragment'],
                'receive_token' => $column['key'] === 'receive'
                    ? $formSubmission->issue($request, "legislative-proposal-receive-{$proposal->id}")
                    : null,
                'reserve_token' => $column['key'] === 'budget'
                    ? $formSubmission->issue($request, "legislative-proposal-reserve-{$proposal->id}")
                    : null,
            ]))
            ->sortBy([
                ['late', 'desc'],
                ['age', 'desc'],
            ])
            ->take(6)
            ->values();
        $focusItem = $focus ? $focus['items']->first() : null;
        $review = collect($board)->firstWhere('key', 'review');
        $receive = collect($board)->firstWhere('key', 'receive');
        $budget = collect($board)->firstWhere('key', 'budget');
        $executionColumn = collect($board)->firstWhere('key', 'execution');
        $firstPriority = $quickActions->first();
        $stageShortcuts = collect($executionColumn['items'] ?? [])
            ->filter(fn (LegislativeProposal $proposal): bool => $proposal->amendment !== null)
            ->take(4)
            ->map(function (LegislativeProposal $proposal): array {
                $amendment = $proposal->amendment;
                $nextStep = $proposal->getAttribute('executive_next_step') ?? $this->proposalNextStep($proposal, 'executive');

                return [
                    'proposal' => $proposal,
                    'amendment' => $amendment,
                    'next_step' => $nextStep,
                    'amount' => (float) ($proposal->budget_reserved_amount ?: $proposal->estimated_amount),
                    'links' => [
                        ['label' => 'Abrir emenda', 'icon' => 'file-text', 'href' => route('emendas.show', $amendment)],
                        ['label' => 'Plano', 'icon' => 'clipboard-list', 'href' => route('emendas.work-plan', $amendment)],
                        ['label' => 'Execução', 'icon' => 'gauge', 'href' => route('emendas.execution', $amendment)],
                        ['label' => 'Prestação', 'icon' => 'archive', 'href' => route('emendas.accountability', $amendment)],
                        ['label' => 'Dossiê', 'icon' => 'package-check', 'href' => route('emendas.accountability.dossier.pdf', $amendment)],
                    ],
                ];
            })
            ->values();
        $executionPriority = $stageShortcuts->first();
        $cockpit = $firstPriority
            ? [
                'tone' => $firstPriority['late'] ? 'danger' : $firstPriority['column']['tone'],
                'icon' => $firstPriority['late'] ? 'timer-reset' : $firstPriority['column']['icon'],
                'kicker' => $firstPriority['late'] ? 'Atraso operacional' : 'Próxima decisão sugerida',
                'title' => match ($firstPriority['column']['key']) {
                    'review' => 'Conferir a proposta da Câmara',
                    'receive' => 'Receber protocolo no Executivo',
                    'budget' => 'Registrar reserva orçamentária',
                    default => 'Abrir acompanhamento',
                },
                'description' => match ($firstPriority['column']['key']) {
                    'review' => 'A proposta precisa de validação legislativa antes de virar protocolo formal.',
                    'receive' => 'O protocolo já chegou da Câmara e precisa virar processo administrativo municipal.',
                    'budget' => 'O processo foi recebido; falta confirmar dotação para liberar plano e execução.',
                    default => 'Há uma emenda reservada aguardando continuidade operacional.',
                },
                'proposal' => $firstPriority['proposal'],
                'amount' => (float) $firstPriority['proposal']->estimated_amount,
                'age' => $firstPriority['age'],
                'url' => $firstPriority['url'],
                'label' => match ($firstPriority['column']['key']) {
                    'receive' => 'Receber agora',
                    'budget' => 'Reservar agora',
                    'review' => 'Conferir agora',
                    default => 'Abrir agora',
                },
            ]
            : ($executionPriority
                ? [
                    'tone' => $executionPriority['next_step']['tone'] ?? 'execution',
                    'icon' => $executionPriority['next_step']['icon'] ?? 'route',
                    'kicker' => 'Continuidade executiva',
                    'title' => $executionPriority['next_step']['title'],
                    'description' => $executionPriority['next_step']['description'],
                    'proposal' => $executionPriority['proposal'],
                    'amount' => (float) $executionPriority['amount'],
                    'age' => $this->proposalAgeDays($executionPriority['proposal']),
                    'url' => $executionPriority['next_step']['href'],
                    'label' => $executionPriority['next_step']['label'],
                ]
                : [
                    'tone' => 'clear',
                    'icon' => 'circle-check',
                    'kicker' => 'Mesa em dia',
                    'title' => 'Nenhuma decisão crítica agora',
                    'description' => 'As filas principais estão sem pendência. Acompanhe novas entradas da Câmara e atualizações de execução.',
                    'proposal' => null,
                    'amount' => 0.0,
                    'age' => 0,
                    'url' => route('legislative.index', ['year' => $request->query('year')]),
                    'label' => 'Atualizar mesa',
                ]);
        $automationCards = collect([
            [
                'key' => 'review',
                'title' => 'Conferência',
                'icon' => 'badge-check',
                'count' => (int) $review['count'],
                'amount' => (float) $review['amount'],
                'status' => (int) $review['count'] > 0 ? 'Precisa de decisão da Câmara' : 'Sem conferência pendente',
                'url' => $review['items']->first()?->getAttribute('executive_board_url') ?? $review['filter_url'],
                'label' => (int) $review['count'] > 0 ? 'Conferir' : 'Ver fila',
                'tone' => 'review',
            ],
            [
                'key' => 'receive',
                'title' => 'Recebimento',
                'icon' => 'inbox',
                'count' => (int) $receive['count'],
                'amount' => (float) $receive['amount'],
                'status' => (int) $receive['count'] > 0 ? 'Protocolos aguardando processo' : 'Nada para receber',
                'url' => $receive['items']->first()?->getAttribute('executive_board_url') ?? $receive['filter_url'],
                'label' => (int) $receive['count'] > 0 ? 'Receber' : 'Ver fila',
                'tone' => 'receive',
            ],
            [
                'key' => 'budget',
                'title' => 'Reserva',
                'icon' => 'wallet-cards',
                'count' => (int) $budget['count'],
                'amount' => (float) $budget['amount'],
                'status' => (int) $budget['count'] > 0 ? 'Dotação precisa ser confirmada' : 'Sem reserva pendente',
                'url' => $budget['items']->first()?->getAttribute('executive_board_url') ?? $budget['filter_url'],
                'label' => (int) $budget['count'] > 0 ? 'Reservar' : 'Ver fila',
                'tone' => 'budget',
            ],
            [
                'key' => 'execution',
                'title' => 'Execução',
                'icon' => 'route',
                'count' => (int) $executionColumn['count'],
                'amount' => (float) $executionColumn['amount'],
                'status' => (int) $executionColumn['count'] > 0 ? 'Plano, entrega ou prestação em aberto' : 'Sem execução aberta',
                'url' => $executionPriority['next_step']['href'] ?? ($executionColumn['items']->first()?->getAttribute('executive_board_url') ?? $executionColumn['filter_url']),
                'label' => (int) $executionColumn['count'] > 0 ? 'Continuar' : 'Ver fila',
                'tone' => 'execution',
            ],
        ]);

        return [
            'total' => $total,
            'amount' => $amount,
            'focus' => $focus,
            'ready' => $total === 0,
            'done' => (int) $execution['count'],
            'execution_amount' => (float) $execution['amount'],
            'health_amount' => (float) collect($board)->sum('health_amount'),
            'health_decision_amount' => (float) $actionable->sum('health_amount'),
            'stale' => $staleItems,
            'stale_count' => (int) $actionable->sum('stale_count'),
            'quick_actions' => $quickActions,
            'stage_shortcuts' => $stageShortcuts,
            'cockpit' => $cockpit,
            'automation_cards' => $automationCards,
            'next_decisions' => $quickActions->take(3)->map(fn (array $action): array => [
                'proposal' => $action['proposal'],
                'column' => $action['column'],
                'age' => $action['age'],
                'late' => $action['late'],
                'url' => $action['url'],
                'label' => match ($action['column']['key']) {
                    'receive' => 'Receber como processo municipal',
                    'budget' => 'Confirmar reserva orçamentária',
                    'review' => 'Concluir conferência ou protocolo',
                    default => 'Abrir acompanhamento',
                },
            ])->values(),
            'focus_item' => $focusItem,
            'focus_url' => $focusItem ? route('legislative.show', $focusItem).$focus['fragment'] : null,
            'focus_class' => $total === 0 ? 'is-clear' : ($staleItems->isNotEmpty() ? 'is-danger' : ''),
            'focus_icon' => $total === 0 ? 'circle-check' : ($focus['icon'] ?? 'alert-circle'),
            'focus_kicker' => $total === 0 ? 'Sem gargalo operacional' : 'Foco recomendado agora',
            'focus_title' => $total === 0 ? 'Nenhuma proposta aguardando ação do Executivo' : ($focus['title'] ?? 'Revisar fila'),
            'focus_text' => $total === 0 ? 'As propostas ativas estão sem pendência imediata de decisão, recebimento ou reserva.' : ($focus['description'] ?? 'Abra a fila abaixo para tratar os itens pendentes.'),
            'command_cards' => [
                [
                    'icon' => 'inbox',
                    'label' => 'Receber',
                    'value' => (int) collect($board)->firstWhere('key', 'receive')['count'],
                    'tone' => 'receive',
                    'description' => 'Protocolos da Câmara que ainda precisam virar processo municipal.',
                ],
                [
                    'icon' => 'wallet-cards',
                    'label' => 'Reservar',
                    'value' => (int) collect($board)->firstWhere('key', 'budget')['count'],
                    'tone' => 'budget',
                    'description' => 'Propostas recebidas aguardando confirmação orçamentária.',
                ],
                [
                    'icon' => 'heart-pulse',
                    'label' => 'Saúde',
                    'value' => 'R$ '.number_format((float) $actionable->sum('health_amount'), 2, ',', '.'),
                    'tone' => 'health',
                    'description' => 'Valor marcado como saúde ainda sob decisão do Executivo.',
                ],
                [
                    'icon' => 'gauge',
                    'label' => 'Execução',
                    'value' => (int) $execution['count'],
                    'tone' => 'execution',
                    'description' => 'Emendas já reservadas que devem seguir para plano e entrega.',
                ],
            ],
            'flow_steps' => [
                [
                    'label' => 'Câmara envia',
                    'description' => 'Proposta aprovada chega protocolada.',
                    'action' => $exerciseLocked ? 'Ativar exercício' : ((int) $review['count'] > 0 ? 'Ir para conferência' : 'Ver conferência'),
                    'url' => $exerciseLocked ? $exerciseLockedUrl : ($review['items']->first()?->getAttribute('executive_board_url') ?? $review['filter_url']),
                    'count' => (int) $review['count'],
                    'locked' => $exerciseLocked,
                ],
                [
                    'label' => 'Executivo recebe',
                    'description' => 'Sistema sugere processo e secretaria.',
                    'action' => $exerciseLocked ? 'Ativar exercício' : ((int) $receive['count'] > 0 ? 'Receber agora' : 'Ver recebimento'),
                    'url' => $exerciseLocked ? $exerciseLockedUrl : ($receive['items']->first()?->getAttribute('executive_board_url') ?? $receive['filter_url']),
                    'count' => (int) $receive['count'],
                    'locked' => $exerciseLocked,
                ],
                [
                    'label' => 'Reserva orçamentária',
                    'description' => 'Valor integral e dotação ficam registrados.',
                    'action' => $exerciseLocked ? 'Ativar exercício' : ((int) $budget['count'] > 0 ? 'Reservar agora' : 'Ver reserva'),
                    'url' => $exerciseLocked ? $exerciseLockedUrl : ($budget['items']->first()?->getAttribute('executive_board_url') ?? $budget['filter_url']),
                    'count' => (int) $budget['count'],
                    'locked' => $exerciseLocked,
                ],
                [
                    'label' => 'Plano e execução',
                    'description' => 'Central cria pendências para entregar e prestar contas.',
                    'action' => $exerciseLocked ? 'Ativar exercício' : ((int) $executionColumn['count'] > 0 ? 'Acompanhar' : 'Ver execução'),
                    'url' => $exerciseLocked ? $exerciseLockedUrl : ($executionColumn['items']->first()?->getAttribute('executive_board_url') ?? $executionColumn['filter_url']),
                    'count' => (int) $executionColumn['count'],
                    'locked' => $exerciseLocked,
                ],
            ],
        ];
    }

    private function proposalAgeDays(LegislativeProposal $proposal): int
    {
        return (int) floor($proposal->updated_at->diffInHours(now()) / 24);
    }

    /**
     * @return array{title: string, description: string, href: string, label: string, owner: string, icon: string, tone: string}
     */
    private function proposalNextStep(LegislativeProposal $proposal, string $audience): array
    {
        $showUrl = route('legislative.show', $proposal);
        $isCouncilor = $audience === 'councilor';

        if ($isCouncilor) {
            return match ($proposal->status) {
                LegislativeProposal::STATUS_DRAFT => [
                    'title' => 'Terminar e enviar',
                    'description' => 'A proposta ainda está com você. Revise valor, destino e justificativa para mandar à Câmara.',
                    'href' => $showUrl.'#editor-proposta',
                    'label' => 'Editar proposta',
                    'owner' => 'Vereador',
                    'icon' => 'pencil-line',
                    'tone' => 'warning',
                ],
                LegislativeProposal::STATUS_RETURNED => [
                    'title' => 'Corrigir devolução',
                    'description' => 'A Câmara devolveu para ajuste. Abra a proposta, corrija os pontos e reenvie.',
                    'href' => $showUrl.'#editor-proposta',
                    'label' => 'Corrigir agora',
                    'owner' => 'Vereador',
                    'icon' => 'undo-2',
                    'tone' => 'warning',
                ],
                LegislativeProposal::STATUS_SUBMITTED => [
                    'title' => 'Em conferência na Câmara',
                    'description' => 'A proposta já saiu da sua mão e aguarda a checagem legislativa mínima.',
                    'href' => $showUrl.'#historico-proposta',
                    'label' => 'Ver histórico',
                    'owner' => 'Câmara',
                    'icon' => 'landmark',
                    'tone' => 'info',
                ],
                LegislativeProposal::STATUS_APPROVED => [
                    'title' => 'Aguardando protocolo',
                    'description' => 'A Câmara aprovou a conferência e precisa encaminhar oficialmente ao Executivo.',
                    'href' => $showUrl.'#historico-proposta',
                    'label' => 'Ver protocolo',
                    'owner' => 'Câmara',
                    'icon' => 'send',
                    'tone' => 'info',
                ],
                LegislativeProposal::STATUS_SENT => [
                    'title' => 'Aguardando recebimento',
                    'description' => 'O Executivo precisa abrir o processo municipal para iniciar a reserva orçamentária.',
                    'href' => $showUrl.'#acompanhamento-executivo',
                    'label' => 'Acompanhar',
                    'owner' => 'Executivo',
                    'icon' => 'inbox',
                    'tone' => 'primary',
                ],
                LegislativeProposal::STATUS_RECEIVED => [
                    'title' => 'Aguardando reserva',
                    'description' => 'A Prefeitura recebeu a proposta e deve confirmar dotação para executar.',
                    'href' => $showUrl.'#acompanhamento-executivo',
                    'label' => 'Ver Executivo',
                    'owner' => 'Executivo',
                    'icon' => 'wallet-cards',
                    'tone' => 'primary',
                ],
                LegislativeProposal::STATUS_RESERVED => [
                    'title' => 'No fluxo de execução',
                    'description' => 'A reserva foi registrada. Acompanhe plano, entrega, pagamento e prestação de contas.',
                    'href' => $showUrl.'#acompanhamento-executivo',
                    'label' => 'Ver andamento',
                    'owner' => 'Prefeitura',
                    'icon' => 'route',
                    'tone' => 'success',
                ],
                LegislativeProposal::STATUS_REJECTED => [
                    'title' => 'Encerrada na análise',
                    'description' => 'Consulte a fundamentação registrada pela Câmara para entender a decisão.',
                    'href' => $showUrl.'#historico-proposta',
                    'label' => 'Ver decisão',
                    'owner' => 'Câmara',
                    'icon' => 'circle-x',
                    'tone' => 'danger',
                ],
                default => [
                    'title' => 'Acompanhar andamento',
                    'description' => 'Abra a proposta para consultar a situação atual e o histórico.',
                    'href' => $showUrl.'#historico-proposta',
                    'label' => 'Ver histórico',
                    'owner' => 'Sistema',
                    'icon' => 'eye',
                    'tone' => 'muted',
                ],
            };
        }

        if ($proposal->status === LegislativeProposal::STATUS_RESERVED && $proposal->amendment) {
            $amendment = $proposal->amendment;

            return match ($amendment->status) {
                ParliamentaryAmendment::STATUS_PLAN_PENDING => [
                    'title' => 'Abrir plano de trabalho',
                    'description' => 'A reserva já existe. O próximo passo é planejar cronograma, entrega e responsáveis.',
                    'href' => route('emendas.work-plan', $amendment),
                    'label' => 'Abrir plano',
                    'owner' => 'Executivo',
                    'icon' => 'clipboard-list',
                    'tone' => 'budget',
                ],
                ParliamentaryAmendment::STATUS_EXECUTING,
                ParliamentaryAmendment::STATUS_RESOURCE_RECEIVED,
                ParliamentaryAmendment::STATUS_APPROVED => [
                    'title' => 'Acompanhar execução',
                    'description' => 'Registre entrega física, empenhos, liquidações e pagamentos.',
                    'href' => route('emendas.execution', $amendment),
                    'label' => 'Abrir execução',
                    'owner' => 'Prefeitura',
                    'icon' => 'gauge',
                    'tone' => 'execution',
                ],
                ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING => [
                    'title' => 'Fechar prestação',
                    'description' => 'Consolide documentos, pagamentos, evidências e decisão final.',
                    'href' => route('emendas.accountability', $amendment),
                    'label' => 'Prestar contas',
                    'owner' => 'Prefeitura',
                    'icon' => 'archive',
                    'tone' => 'success',
                ],
                ParliamentaryAmendment::STATUS_COMPLETED => [
                    'title' => 'Baixar dossiê final',
                    'description' => 'O fluxo está concluído. Use o pacote final para auditoria, Câmara ou Controle Interno.',
                    'href' => route('emendas.accountability.dossier.pdf', $amendment),
                    'label' => 'Ver dossiê',
                    'owner' => 'Controle',
                    'icon' => 'package-check',
                    'tone' => 'success',
                ],
                default => [
                    'title' => 'Abrir fluxo executivo',
                    'description' => 'Continue pela emenda executiva vinculada à proposta legislativa.',
                    'href' => route('emendas.show', $amendment),
                    'label' => 'Abrir emenda',
                    'owner' => 'Executivo',
                    'icon' => 'file-text',
                    'tone' => 'primary',
                ],
            };
        }

        return match ($proposal->status) {
            LegislativeProposal::STATUS_SUBMITTED => [
                'title' => 'Conferir requisitos da Câmara',
                'description' => 'Valide objeto, valor, saúde, beneficiário e compatibilidade antes do protocolo.',
                'href' => $showUrl.'#conferencia-legislativa',
                'label' => 'Conferir',
                'owner' => 'Câmara',
                'icon' => 'badge-check',
                'tone' => 'review',
            ],
            LegislativeProposal::STATUS_APPROVED => [
                'title' => 'Protocolar ao Executivo',
                'description' => 'Registre o protocolo da Câmara e envie formalmente à Prefeitura.',
                'href' => $showUrl.'#protocolo-executivo',
                'label' => 'Protocolar',
                'owner' => 'Câmara',
                'icon' => 'send',
                'tone' => 'review',
            ],
            LegislativeProposal::STATUS_SENT => [
                'title' => 'Receber como processo',
                'description' => 'Abra o processo administrativo municipal e vincule a secretaria responsável.',
                'href' => $showUrl.'#recebimento-executivo',
                'label' => 'Receber',
                'owner' => 'Executivo',
                'icon' => 'inbox',
                'tone' => 'receive',
            ],
            LegislativeProposal::STATUS_RECEIVED => [
                'title' => 'Registrar reserva orçamentária',
                'description' => 'Confirme dotação e valor para liberar o plano de trabalho automaticamente.',
                'href' => $showUrl.'#reserva-orcamentaria',
                'label' => 'Reservar',
                'owner' => 'Executivo',
                'icon' => 'wallet-cards',
                'tone' => 'budget',
            ],
            LegislativeProposal::STATUS_RESERVED => [
                'title' => 'Abrir acompanhamento executivo',
                'description' => 'A proposta já tem reserva. Continue pelo plano, execução e prestação de contas.',
                'href' => $showUrl.'#acompanhamento-executivo',
                'label' => 'Acompanhar',
                'owner' => 'Prefeitura',
                'icon' => 'route',
                'tone' => 'execution',
            ],
            default => [
                'title' => 'Consultar histórico',
                'description' => 'Sem ação executiva imediata. Consulte eventos, documentos e decisão registrada.',
                'href' => $showUrl.'#historico-proposta',
                'label' => 'Ver histórico',
                'owner' => 'Sistema',
                'icon' => 'eye',
                'tone' => 'muted',
            ],
        };
    }

    public function create(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
    ): View|RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        abort_unless($request->user()->roleForMunicipality($municipality->id) === User::ROLE_COUNCILOR, 403);
        $year = ctype_digit((string) $request->query('year')) ? (int) $request->query('year') : $service->defaultYear($municipality);

        if (! $service->profile($municipality, $year)) {
            return redirect()
                ->route('legislative.index', ['year' => $year])
                ->with('warning', 'O cadastro está indisponível porque não existe uma configuração normativa vigente para este exercício.');
        }

        return view('legislative.create', [
            ...$this->formOptions($municipality, $request, $service, $year),
            'prefill' => [
                'estimated_amount' => is_numeric($request->query('suggested_amount'))
                    ? number_format((float) $request->query('suggested_amount'), 2, '.', '')
                    : null,
                'health_related' => $request->boolean('suggested_health'),
                'responsible_department' => $request->boolean('suggested_health') ? 'Secretaria Municipal de Saúde' : null,
            ],
            'submissionToken' => $formSubmission->issue($request, 'legislative-proposal-create'),
        ]);
    }

    public function store(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        abort_unless($request->user()->roleForMunicipality($municipality->id) === User::ROLE_COUNCILOR, 403);
        $validated = $this->validateProposal($request);
        if (! $formSubmission->has($request, 'legislative-proposal-create')) {
            return redirect()->route('legislative.index')->with('warning', 'Esta proposta já foi cadastrada.');
        }
        $profile = $service->profile($municipality, (int) $validated['fiscal_year']);
        if (! $profile) {
            return back()->withInput()->withErrors(['fiscal_year' => 'Não existe configuração normativa vigente para este exercício.']);
        }
        $membership = $request->user()->municipalities()->whereKey($municipality->id)->firstOrFail()->pivot;
        $authorName = trim((string) ($membership->legislative_name ?: $request->user()->name));
        $draftErrors = $service->draftErrors($municipality, $profile, $authorName, $validated);
        if ($draftErrors !== []) {
            return back()->withInput()->withErrors($draftErrors);
        }
        if (! $formSubmission->consume($request, 'legislative-proposal-create')) {
            return redirect()->route('legislative.index')->with('warning', 'Esta proposta já foi cadastrada.');
        }

        $proposal = DB::transaction(function () use ($request, $municipality, $profile, $membership, $validated): LegislativeProposal {
            Municipality::query()->lockForUpdate()->findOrFail($municipality->id);
            $sequence = $municipality->legislativeProposals()->where('fiscal_year', $validated['fiscal_year'])->count() + 1;
            $reference = 'LEG-'.$validated['fiscal_year'].'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);

            $proposal = $municipality->legislativeProposals()->create([
                ...$validated,
                'municipal_regulatory_profile_id' => $profile->id,
                'submitted_by' => $request->user()->id,
                'author_name' => trim((string) ($membership->legislative_name ?: $request->user()->name)),
                'author_party' => trim((string) $membership->legislative_party),
                'reference' => $reference,
                'status' => LegislativeProposal::STATUS_DRAFT,
            ]);
            $this->event($proposal, $request->user()->id, 'created', null, LegislativeProposal::STATUS_DRAFT, 'Proposta legislativa iniciada.');

            return $proposal;
        });
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_created', [
            'proposal_id' => $proposal->id, 'reference' => $proposal->reference, 'fiscal_year' => $proposal->fiscal_year,
        ]);

        return redirect()->route('legislative.show', $proposal)->with('status', 'Proposta salva como rascunho.');
    }

    public function show(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal)->load([
            'regulatoryProfile', 'submitter:id,name,email', 'reviewer:id,name', 'receiver:id,name',
            'events.actor:id,name', 'amendment.municipalWorkPlan',
            'amendment.executionStages',
            'amendment.financialCommitments.liquidations', 'amendment.financialCommitments.payments',
            'amendment.accountabilityProcess',
        ]);
        $role = $request->user()->roleForMunicipality($municipality->id);

        return view('legislative.show', [
            ...$this->formOptions($municipality, $request, $service, $proposal->fiscal_year),
            'municipality' => $municipality,
            'proposal' => $proposal,
            'role' => $role,
            'quota' => $service->quota($municipality, $proposal->regulatoryProfile, $proposal->author_name, $proposal),
            'reviewChecklist' => $service->reviewChecklist(),
            'reviewBlockers' => $service->reviewBlockers($proposal),
            'submissionReadiness' => $service->submissionReadiness($proposal, $request->user()),
            'canEdit' => $role === User::ROLE_COUNCILOR && $proposal->submitted_by === $request->user()->id && $proposal->isEditable(),
            'canReview' => in_array($role, [User::ROLE_MANAGER, User::ROLE_LEGISLATIVE_REVIEWER], true),
            'canReceive' => in_array($role, [User::ROLE_MANAGER, User::ROLE_EDITOR], true),
            'executiveReceiveSuggestion' => $this->executiveReceiveSuggestion($proposal),
            'updateToken' => $formSubmission->issue($request, "legislative-proposal-update-{$proposal->id}"),
            'submitToken' => $formSubmission->issue($request, "legislative-proposal-submit-{$proposal->id}"),
            'reviewToken' => $formSubmission->issue($request, "legislative-proposal-review-{$proposal->id}"),
            'protocolToken' => $formSubmission->issue($request, "legislative-proposal-protocol-{$proposal->id}"),
            'receiveToken' => $formSubmission->issue($request, "legislative-proposal-receive-{$proposal->id}"),
            'reserveToken' => $formSubmission->issue($request, "legislative-proposal-reserve-{$proposal->id}"),
        ]);
    }

    public function update(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal)->load('regulatoryProfile');
        abort_unless($proposal->submitted_by === $request->user()->id && $proposal->isEditable(), 409, 'Esta proposta não pode mais ser editada.');
        $validated = $this->validateProposal($request, false);
        $draftErrors = $service->draftErrors($municipality, $proposal->regulatoryProfile, $proposal->author_name, $validated, $proposal);
        if ($draftErrors !== []) {
            return back()->withInput()->withErrors($draftErrors);
        }
        if (! $formSubmission->consume($request, "legislative-proposal-update-{$proposal->id}")) {
            return back()->with('warning', 'Esta alteração já foi processada.');
        }
        unset($validated['fiscal_year']);
        $before = $proposal->only(array_keys($validated));
        $proposal->update($validated);
        $this->event($proposal, $request->user()->id, 'updated', $proposal->status, $proposal->status, 'Conteúdo da proposta atualizado.', ['before' => $before]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_updated', [
            'proposal_id' => $proposal->id, 'reference' => $proposal->reference,
        ], $before);

        return back()->with('status', 'Rascunho atualizado.');
    }

    public function submit(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
        LegislativeNotificationService $notifications,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal);
        abort_unless($proposal->submitted_by === $request->user()->id, 404);
        $request->validate(['_submission_token' => ['nullable', 'string']]);
        $formSubmission->consume($request, "legislative-proposal-submit-{$proposal->id}");
        if (! $proposal->isEditable()) {
            return back()->with('status', 'Esta indicação já foi enviada ou já avançou no fluxo.');
        }
        $errors = $service->submissionErrors($proposal, $request->user());
        if ($errors !== []) {
            return back()->withErrors($errors);
        }
        $from = $proposal->status;
        $proposal->update(['status' => LegislativeProposal::STATUS_SUBMITTED, 'submitted_at' => now()]);
        $this->event($proposal, $request->user()->id, 'submitted', $from, $proposal->status, 'Encaminhada à conferência mínima da Câmara.');
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_submitted', ['proposal_id' => $proposal->id, 'reference' => $proposal->reference]);
        $notifications->roles($proposal, [User::ROLE_MANAGER, User::ROLE_LEGISLATIVE_REVIEWER], 'Nova indicação para conferência legislativa', "{$proposal->author_name} enviou a indicação {$proposal->reference}.");

        return back()->with('status', 'Indicação enviada para a conferência mínima da Câmara.');
    }

    public function review(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
        LegislativeNotificationService $notifications,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal);
        $rules = ['_submission_token' => ['nullable', 'string'], 'decision' => ['required', Rule::in(['approve', 'return', 'reject'])], 'review_notes' => ['required', 'string', 'min:20', 'max:5000']];
        foreach (array_keys($service->reviewChecklist()) as $field) {
            $rules[$field] = ['nullable', 'boolean'];
        }
        $validated = $request->validate($rules, ['review_notes.min' => 'Fundamente a análise com pelo menos 20 caracteres.']);
        $formSubmission->consume($request, "legislative-proposal-review-{$proposal->id}");
        if ($proposal->status !== LegislativeProposal::STATUS_SUBMITTED) {
            return back()->with('status', 'Esta proposta já saiu da conferência legislativa. A etapa atual foi mantida.');
        }
        $checks = collect(array_keys($service->reviewChecklist()))->mapWithKeys(fn (string $field) => [$field => $request->boolean($field)])->all();
        $proposal->forceFill($checks);
        if ($validated['decision'] === 'approve' && $service->reviewBlockers($proposal) !== []) {
            return back()->withErrors(['review' => 'Para aprovar, conclua todos os pontos da análise técnica.']);
        }
        $to = match ($validated['decision']) {
            'approve' => LegislativeProposal::STATUS_APPROVED,
            'return' => LegislativeProposal::STATUS_RETURNED,
            default => LegislativeProposal::STATUS_REJECTED,
        };
        $from = $proposal->status;
        $proposal->forceFill([
            ...$checks, 'status' => $to, 'reviewed_by' => $request->user()->id,
            'review_notes' => trim($validated['review_notes']), 'reviewed_at' => now(),
        ])->save();
        $this->event($proposal, $request->user()->id, 'reviewed', $from, $to, $proposal->review_notes, ['checks' => $checks]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_reviewed', ['proposal_id' => $proposal->id, 'reference' => $proposal->reference, 'decision' => $to]);
        $notifications->submitter($proposal, 'Análise legislativa concluída', "A proposta {$proposal->reference} foi marcada como {$proposal->statusLabel()}.", $to === LegislativeProposal::STATUS_REJECTED ? 'critical' : ($to === LegislativeProposal::STATUS_RETURNED ? 'warning' : 'info'));

        return back()->with('status', 'Análise legislativa registrada e preservada.');
    }

    public function protocol(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeProposalService $service,
        LegislativeNotificationService $notifications,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal);
        $validated = $request->validate([
            '_submission_token' => ['nullable', 'string'],
            'protocol_number' => ['required', 'string', 'min:3', 'max:180'],
        ]);
        $formSubmission->consume($request, "legislative-proposal-protocol-{$proposal->id}");
        if ($proposal->status !== LegislativeProposal::STATUS_APPROVED) {
            return back()->with('status', 'Esta proposta já foi protocolada ou não está mais nessa etapa.');
        }
        $proposal->forceFill(['protocol_number' => trim($validated['protocol_number'])]);
        $blockers = $service->protocolBlockers($proposal);
        if ($blockers !== []) {
            return back()->withErrors(['protocol' => implode(' ', $blockers)]);
        }
        $snapshot = $service->protocolSnapshot($proposal);
        $from = $proposal->status;
        $proposal->forceFill([
            'status' => LegislativeProposal::STATUS_SENT, 'sent_at' => now(),
            'protocol_snapshot' => $snapshot, 'protocol_sha256' => $service->hash($snapshot),
        ])->save();
        $this->event($proposal, $request->user()->id, 'protocolled', $from, $proposal->status, 'Encaminhamento formal da Câmara ao Executivo.', ['protocol' => $proposal->protocol_number, 'sha256' => $proposal->protocol_sha256]);
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_protocolled', ['proposal_id' => $proposal->id, 'reference' => $proposal->reference, 'protocol' => $proposal->protocol_number, 'sha256' => $proposal->protocol_sha256]);
        $notifications->roles($proposal, [User::ROLE_MANAGER, User::ROLE_EDITOR], 'Proposta protocolada pela Câmara', "A proposta {$proposal->reference} aguarda recebimento formal pelo Executivo.", 'warning');

        return back()->with('status', 'Proposta protocolada no Executivo com fotografia e hash preservados.');
    }

    public function receive(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeNotificationService $notifications,
        MunicipalTransparencyTrail $transparencyTrail,
        MunicipalWorkItemService $workItems,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal);
        $suggestion = $this->executiveReceiveSuggestion($proposal);
        $validated = $request->validate([
            '_submission_token' => ['nullable', 'string'],
            'executive_process_number' => ['nullable', 'string', 'min:3', 'max:180'],
            'executive_notes' => ['nullable', 'string', 'min:20', 'max:5000'],
        ], ['executive_notes.min' => 'Registre a conferência inicial do Executivo com pelo menos 20 caracteres.']);
        $validated['executive_process_number'] = trim((string) (($validated['executive_process_number'] ?? null) ?: $suggestion['process_number']));
        $validated['executive_notes'] = trim((string) (($validated['executive_notes'] ?? null) ?: $suggestion['notes']));
        $formSubmission->consume($request, "legislative-proposal-receive-{$proposal->id}");
        if ($proposal->status !== LegislativeProposal::STATUS_SENT) {
            $proposal->load('amendment');

            if ($proposal->amendment) {
                return redirect()->route('emendas.show', $proposal->amendment)->with('status', 'Recebimento já confirmado. A emenda executiva está aberta.');
            }

            return back()->with('status', 'Esta proposta não está mais aguardando recebimento pelo Executivo.');
        }

        $amendment = DB::transaction(function () use ($request, $municipality, $proposal, $validated, $transparencyTrail): ParliamentaryAmendment {
            $locked = LegislativeProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($locked->status !== LegislativeProposal::STATUS_SENT || $locked->parliamentary_amendment_id !== null) {
                return $locked->amendment()->firstOrFail();
            }
            $amendment = $municipality->amendments()->create([
                'municipal_regulatory_profile_id' => $locked->municipal_regulatory_profile_id,
                'created_by' => $request->user()->id,
                'reference' => $locked->reference,
                'fiscal_year' => $locked->fiscal_year,
                'government_sphere' => 'municipal',
                'authorship_type' => 'individual',
                'transfer_type' => $locked->transfer_type,
                'author_name' => $locked->author_name,
                'author_party' => $locked->author_party,
                'object' => $locked->object,
                'expense_destination' => $locked->expense_destination,
                'indicated_for_health' => $locked->health_related,
                'responsible_department' => $locked->responsible_department,
                'beneficiary_location' => $locked->beneficiary_location,
                'administrative_process' => trim($validated['executive_process_number']),
                'expected_amount' => $locked->estimated_amount,
                'status' => ParliamentaryAmendment::STATUS_IDENTIFIED,
                'indicated_at' => $locked->sent_at?->toDateString() ?? now()->toDateString(),
                'notes' => 'Recebida do Portal Legislativo. Beneficiário indicado: '.$locked->beneficiary_name.'. '.$validated['executive_notes'],
            ]);
            $transparencyTrail->recordCreation($amendment);
            $from = $locked->status;
            $locked->update([
                'status' => LegislativeProposal::STATUS_RECEIVED,
                'received_by' => $request->user()->id,
                'received_at' => now(),
                'executive_process_number' => trim($validated['executive_process_number']),
                'executive_notes' => trim($validated['executive_notes']),
                'parliamentary_amendment_id' => $amendment->id,
            ]);
            $this->event($locked, $request->user()->id, 'received', $from, $locked->status, $locked->executive_notes, ['amendment_id' => $amendment->id, 'process' => $locked->executive_process_number]);

            return $amendment;
        });
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_received', ['proposal_id' => $proposal->id, 'reference' => $proposal->reference, 'amendment_id' => $amendment->id]);
        $stats = $workItems->synchronize($municipality->fresh());
        $notifications->submitter($proposal->fresh(), 'Proposta recebida pelo Executivo', "A proposta {$proposal->reference} foi vinculada ao processo {$validated['executive_process_number']}.");

        return back()->with('status', 'Recebimento confirmado. A emenda foi aberta e a Central recebeu '.$stats['created'].' pendência(s) automática(s).');
    }

    public function reserve(
        Request $request,
        int $proposal,
        CurrentMunicipality $currentMunicipality,
        FormSubmission $formSubmission,
        LegislativeNotificationService $notifications,
        MunicipalTransparencyTrail $transparencyTrail,
        MunicipalWorkItemService $workItems,
        AuditTrail $auditTrail,
    ): RedirectResponse {
        $municipality = $currentMunicipality->get($request);
        $proposal = $this->proposal($request, $municipality, $proposal)->load('amendment');
        $suggestion = $this->budgetReservationSuggestion($proposal);
        $validated = $request->validate([
            '_submission_token' => ['nullable', 'string'],
            'budget_reservation_number' => ['nullable', 'string', 'min:3', 'max:180'],
            'budget_reserved_amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'budget_reserved_at' => ['nullable', 'date', 'before_or_equal:today'],
            'executive_notes' => ['nullable', 'string', 'min:20', 'max:5000'],
        ], ['executive_notes.min' => 'Registre a reanálise orçamentária com pelo menos 20 caracteres.']);
        $validated['budget_reservation_number'] = trim((string) (($validated['budget_reservation_number'] ?? null) ?: $suggestion['reservation_number']));
        $validated['budget_reserved_amount'] = $validated['budget_reserved_amount'] ?? $suggestion['amount'];
        $validated['budget_reserved_at'] = $validated['budget_reserved_at'] ?? $suggestion['reserved_at'];
        $validated['executive_notes'] = trim((string) (($validated['executive_notes'] ?? null) ?: $suggestion['notes']));
        if (abs((float) $validated['budget_reserved_amount'] - (float) $proposal->estimated_amount) > 0.01) {
            return back()->withErrors(['budget_reserved_amount' => 'A reserva deve corresponder integralmente ao valor protocolado. Divergências exigem devolução formal à Câmara.']);
        }
        $formSubmission->consume($request, "legislative-proposal-reserve-{$proposal->id}");
        if ($proposal->status !== LegislativeProposal::STATUS_RECEIVED || ! $proposal->amendment) {
            return back()->with('status', 'Esta proposta já avançou ou ainda não está disponível para reserva orçamentária.');
        }

        DB::transaction(function () use ($request, $proposal, $validated, $transparencyTrail): void {
            $from = $proposal->status;
            $proposal->update([
                'status' => LegislativeProposal::STATUS_RESERVED,
                'budget_reservation_number' => trim($validated['budget_reservation_number']),
                'budget_reserved_amount' => $validated['budget_reserved_amount'],
                'budget_reserved_at' => $validated['budget_reserved_at'],
                'executive_notes' => trim($validated['executive_notes']),
            ]);
            $oldAmendment = $proposal->amendment->getOriginal();
            $proposal->amendment->update(['status' => ParliamentaryAmendment::STATUS_PLAN_PENDING]);
            $transparencyTrail->recordAmendmentChanges($proposal->amendment, $oldAmendment);
            $this->event($proposal, $request->user()->id, 'budget_reserved', $from, $proposal->status, $proposal->executive_notes, [
                'reservation' => $proposal->budget_reservation_number, 'amount' => (float) $proposal->budget_reserved_amount,
            ]);
        });
        $auditTrail->recordMunicipalityOperation($request, $municipality, 'legislative_proposal_budget_reserved', ['proposal_id' => $proposal->id, 'reference' => $proposal->reference, 'reservation' => $proposal->budget_reservation_number]);
        $stats = $workItems->synchronize($municipality->fresh());
        $notifications->submitter($proposal, 'Reserva orçamentária registrada', "A proposta {$proposal->reference} avançou para a solicitação e análise do Plano de Trabalho.");

        return redirect()
            ->route('emendas.work-plan', $proposal->amendment)
            ->with('status', 'Reserva registrada. Revise o Plano de Trabalho guiado e envie para análise técnica. A Central recebeu '.$stats['created'].' pendência(s) automática(s).');
    }

    /** @return array<string, mixed> */
    private function executiveReceiveSuggestion(LegislativeProposal $proposal): array
    {
        $sequence = preg_replace('/^LEG-\d{4}-/i', '', $proposal->reference) ?: str_pad((string) $proposal->id, 3, '0', STR_PAD_LEFT);
        $processNumber = 'PREF-'.$proposal->fiscal_year.'-'.$sequence;
        $department = $proposal->responsible_department ?: ($proposal->health_related ? 'Secretaria Municipal de Saúde' : 'Unidade executora a confirmar');
        $classification = $proposal->health_related ? 'Saúde / ASPS' : ($proposal->expense_destination === 'investment' ? 'Investimento municipal' : 'Custeio municipal');
        $notes = 'Recebimento automático da proposta '.$proposal->reference
            .' protocolada pela Câmara'.($proposal->protocol_number ? ' sob '.$proposal->protocol_number : '')
            .'. Secretaria sugerida: '.$department
            .'. Classificação inicial: '.$classification
            .'. Valor a reanalisar e reservar: R$ '.number_format((float) $proposal->estimated_amount, 2, ',', '.').'.';

        return [
            'process_number' => $processNumber,
            'department' => $department,
            'classification' => $classification,
            'notes' => $notes,
            'items' => [
                ['label' => 'Processo sugerido', 'value' => $processNumber],
                ['label' => 'Secretaria sugerida', 'value' => $department],
                ['label' => 'Classificação', 'value' => $classification],
                ['label' => 'Valor recebido', 'value' => 'R$ '.number_format((float) $proposal->estimated_amount, 2, ',', '.')],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function budgetReservationSuggestion(LegislativeProposal $proposal): array
    {
        $sequence = preg_replace('/^LEG-\d{4}-/i', '', $proposal->reference) ?: str_pad((string) $proposal->id, 3, '0', STR_PAD_LEFT);
        $reservationNumber = 'RES-'.$proposal->fiscal_year.'-'.$sequence;
        $amount = (float) $proposal->estimated_amount;
        $process = $proposal->executive_process_number ?: 'processo executivo pendente';
        $notes = 'Reserva orçamentária automática da proposta '.$proposal->reference
            .' vinculada ao '.$process
            .'. Valor integral reservado: R$ '.number_format($amount, 2, ',', '.')
            .'. A etapa seguinte deve solicitar e revisar o Plano de Trabalho antes da execução.';

        return [
            'reservation_number' => $reservationNumber,
            'amount' => $amount,
            'reserved_at' => now()->toDateString(),
            'notes' => $notes,
        ];
    }

    /** @return array<string, mixed> */
    private function validateProposal(Request $request, bool $includeYear = true): array
    {
        $rules = [
            '_submission_token' => ['required', 'string'],
            'object' => ['required', 'string', 'min:20', 'max:5000'],
            'justification' => ['required', 'string', 'min:30', 'max:5000'],
            'priority' => ['nullable', Rule::in(array_keys(LegislativeProposal::priorities()))],
            'beneficiary_type' => ['nullable', Rule::in(array_keys(LegislativeProposal::beneficiaryTypes()))],
            'beneficiary_name' => ['required', 'string', 'min:3', 'max:255'],
            'beneficiary_cnpj' => ['nullable', 'required_if:beneficiary_type,third_sector', 'string', 'max:20'],
            'beneficiary_location' => ['nullable', 'string', 'min:3', 'max:255'],
            'expense_destination' => ['required', Rule::in(array_keys(ParliamentaryAmendment::expenseDestinations()))],
            'transfer_type' => ['nullable', Rule::in(array_keys(ParliamentaryAmendment::transferTypes()))],
            'health_related' => ['nullable', 'boolean'],
            'responsible_department' => ['required', 'string', 'min:3', 'max:255'],
            'program_reference' => ['nullable', 'string', 'max:255'],
            'action_reference' => ['nullable', 'string', 'max:255'],
            'public_need' => ['nullable', 'string', 'min:30', 'max:5000'],
            'target_population' => ['nullable', 'string', 'max:255'],
            'estimated_quantity' => ['nullable', 'string', 'max:255'],
            'estimated_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999.99'],
            'estimate_source' => ['nullable', 'string', 'min:5', 'max:255'],
            'desired_contract_at' => ['nullable', 'date'],
            'third_sector_conflict_declaration' => ['nullable', 'boolean'],
        ];
        if ($includeYear) {
            $rules['fiscal_year'] = ['required', 'integer', 'between:'.now()->year.','.(now()->year + 2)];
        }
        $validated = $request->validate($rules, [
            'object.min' => 'Descreva uma entrega específica; evite objetos genéricos.',
            'justification.min' => 'Explique com mais detalhes por que a proposta atende ao interesse público.',
            'public_need.min' => 'Identifique a necessidade pública e quem será atendido.',
            'beneficiary_cnpj.required_if' => 'Informe o CNPJ da organização da sociedade civil.',
        ]);
        unset($validated['_submission_token']);
        $municipality = app(CurrentMunicipality::class)->get($request);
        $validated['priority'] = $validated['priority'] ?? 'normal';
        $validated['beneficiary_type'] = $validated['beneficiary_type'] ?? 'municipal_body';
        $validated['beneficiary_location'] = blank($validated['beneficiary_location'] ?? null) ? $municipality->name : $validated['beneficiary_location'];
        $validated['transfer_type'] = $validated['transfer_type'] ?? 'direct_execution';
        $validated['public_need'] = blank($validated['public_need'] ?? null) ? $validated['justification'] : $validated['public_need'];
        $validated['estimate_source'] = blank($validated['estimate_source'] ?? null) ? 'Estimativa declarada pelo vereador' : $validated['estimate_source'];
        foreach (['beneficiary_cnpj', 'program_reference', 'action_reference', 'target_population', 'estimated_quantity', 'desired_contract_at'] as $field) {
            $validated[$field] = blank($validated[$field] ?? null) ? null : trim((string) $validated[$field]);
        }
        foreach (['object', 'justification', 'beneficiary_name', 'beneficiary_location', 'responsible_department', 'public_need', 'estimate_source'] as $field) {
            $validated[$field] = trim((string) $validated[$field]);
        }
        $validated['health_related'] = $request->boolean('health_related');
        $validated['third_sector_conflict_declaration'] = $request->boolean('third_sector_conflict_declaration');

        return $validated;
    }

    /** @return array<string, mixed> */
    private function formOptions(
        Municipality $municipality,
        Request $request,
        LegislativeProposalService $service,
        int $year,
    ): array {
        $membership = $request->user()->municipalities()->whereKey($municipality->id)->firstOrFail()->pivot;
        $profile = $service->profile($municipality, $year);

        return [
            'municipality' => $municipality,
            'profile' => $profile,
            'membership' => $membership,
            'year' => $year,
            'priorities' => LegislativeProposal::priorities(),
            'beneficiaryTypes' => LegislativeProposal::beneficiaryTypes(),
            'expenseDestinations' => ParliamentaryAmendment::expenseDestinations(),
            'transferTypes' => collect(ParliamentaryAmendment::transferTypes())->only(['direct_execution', 'defined_purpose', 'fund_to_fund', 'other'])->all(),
            'quota' => $service->quota($municipality, $profile, (string) ($membership->legislative_name ?: $request->user()->name)),
            'automation' => $service->proposalAutomation($municipality, $profile, (string) ($membership->legislative_name ?: $request->user()->name)),
            'institutionSuggestions' => $this->institutionSuggestions($municipality),
        ];
    }

    /** @return array<string, Collection<int, MunicipalInstitution>> */
    private function institutionSuggestions(Municipality $municipality): array
    {
        $institutions = $municipality->institutions()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return [
            'departments' => $institutions->whereIn('type', [
                MunicipalInstitution::TYPE_DEPARTMENT,
                MunicipalInstitution::TYPE_EXECUTING_UNIT,
            ])->values(),
            'beneficiaries' => $institutions->whereIn('type', [
                MunicipalInstitution::TYPE_BENEFICIARY,
                MunicipalInstitution::TYPE_DEPARTMENT,
                MunicipalInstitution::TYPE_EXECUTING_UNIT,
            ])->values(),
        ];
    }

    private function proposal(Request $request, Municipality $municipality, int $proposal): LegislativeProposal
    {
        $item = $municipality->legislativeProposals()->findOrFail($proposal);
        if ($request->user()->roleForMunicipality($municipality->id) === User::ROLE_COUNCILOR) {
            abort_unless($item->submitted_by === $request->user()->id, 404);
        }

        return $item;
    }

    /** @param array<string, mixed>|null $snapshot */
    private function event(
        LegislativeProposal $proposal,
        ?int $actorId,
        string $type,
        ?string $from,
        ?string $to,
        ?string $notes,
        ?array $snapshot = null,
    ): void {
        $proposal->events()->create([
            'municipality_id' => $proposal->municipality_id,
            'actor_id' => $actorId,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $notes,
            'snapshot' => $snapshot,
        ]);
    }
}
