<?php

namespace App\Http\Controllers;

use App\Models\MunicipalRegulatoryProfile;
use App\Models\MunicipalWorkItem;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\AmendmentAnalyticsService;
use App\Services\CurrentMunicipality;
use App\Services\FormSubmission;
use App\Services\IntegrityAlertService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentMunicipality $currentMunicipality,
        IntegrityAlertService $integrityAlertService,
        AmendmentAnalyticsService $analyticsService,
        FormSubmission $formSubmission,
    ): View {
        $municipality = $currentMunicipality->get($request);
        $integrityAlertService->syncIfDue($municipality);
        $filters = $request->only(['year', 'sphere', 'status', 'department']);
        if (isset($filters['sphere']) && ! $municipality->allowsGovernmentSphere((string) $filters['sphere'])) {
            unset($filters['sphere']);
        }
        $analytics = $analyticsService->dashboard($municipality, $filters);
        $options = $analyticsService->filterOptions($municipality);
        $amendments = $analytics['amendments'];

        $deadlines = $amendments
            ->map(fn (ParliamentaryAmendment $amendment) => [
                'amendment' => $amendment,
                'deadline' => $amendment->nextDeadline(),
            ])
            ->filter(fn (array $item) => $item['deadline'] !== null)
            ->sortBy(fn (array $item) => $item['deadline']['date'])
            ->take(6);

        $isManager = $request->user()->roleForMunicipality($municipality->id) === 'manager';
        $canEdit = $request->user()->canEditMunicipality($municipality->id);

        return view('dashboard', [
            'municipality' => $municipality,
            'analytics' => $analytics,
            'municipalHealth' => $this->municipalHealth($municipality, $isManager, $canEdit),
            'filters' => $filters,
            'years' => $options['years'],
            'departments' => $options['departments'],
            'statuses' => ParliamentaryAmendment::statuses(),
            'spheres' => $municipality->enabledGovernmentSpheres(),
            'deadlines' => $deadlines,
            'recentAmendments' => $amendments->take(5),
            'canEdit' => $canEdit,
            'isManager' => $isManager,
            'transparencyToken' => $isManager
                ? $formSubmission->issue($request, "transparency-settings-{$municipality->id}")
                : null,
        ]);
    }

    /** @return array{score: int, tone: string, title: string, subtitle: string, checks: array<int, array{label: string, value: string, ok: bool, icon: string, route: string, action: string}>} */
    private function municipalHealth($municipality, bool $isManager, bool $canEdit): array
    {
        $activeProfile = $municipality->regulatoryProfiles()
            ->where('status', MunicipalRegulatoryProfile::STATUS_ACTIVE)
            ->latest('fiscal_year')
            ->latest('version')
            ->first();
        $hasCouncil = $municipality->users()
            ->wherePivotIn('role', [User::ROLE_COUNCILOR, User::ROLE_LEGISLATIVE_REVIEWER])
            ->exists();
        $activeWork = $municipality->workItems()
            ->whereIn('status', [MunicipalWorkItem::STATUS_PENDING, MunicipalWorkItem::STATUS_IN_PROGRESS]);
        $overdueWork = (clone $activeWork)->whereDate('due_at', '<', today())->count();
        $unassignedAmendments = $municipality->amendments()->whereNull('responsible_user_id')->count();
        $hasFirstFlow = $municipality->amendments()->exists() || $municipality->legislativeProposals()->exists();
        $criticalAlerts = $municipality->integrityAlerts()->where('status', 'open')->where('severity', 'critical')->count();
        $openDocuments = (clone $activeWork)->where('category', 'document')->count();

        $checks = [
            [
                'label' => 'Exercício ativo',
                'value' => $activeProfile ? "{$activeProfile->fiscal_year} liberado" : 'Pendente',
                'ok' => $activeProfile !== null,
                'icon' => 'landmark',
                'route' => route('municipal-rules.index'),
                'action' => $activeProfile ? 'Ver normas' : 'Ativar exercício',
            ],
            [
                'label' => 'Câmara liberada',
                'value' => $hasCouncil ? 'Acesso legislativo ativo' : 'Sem vereador vinculado',
                'ok' => $hasCouncil,
                'icon' => 'users',
                'route' => $isManager ? route('users.index') : route('work-center.index'),
                'action' => $isManager ? ($hasCouncil ? 'Ver usuários' : 'Convidar Câmara') : 'Ver pendências',
            ],
            [
                'label' => 'Primeiro fluxo',
                'value' => $hasFirstFlow ? 'Operação iniciada' : 'Nada cadastrado',
                'ok' => $hasFirstFlow,
                'icon' => 'send',
                'route' => $canEdit ? route('legislative.index') : route('emendas.index'),
                'action' => $canEdit ? ($hasFirstFlow ? 'Acompanhar fluxo' : 'Iniciar proposta') : 'Consultar emendas',
            ],
            [
                'label' => 'Prazos críticos',
                'value' => $overdueWork.' vencido(s)',
                'ok' => $overdueWork === 0 && $criticalAlerts === 0,
                'icon' => 'triangle-alert',
                'route' => route('work-center.index', ['queue' => 'overdue']),
                'action' => 'Ver atrasadas',
            ],
            [
                'label' => 'Responsáveis',
                'value' => $unassignedAmendments.' emenda(s) sem dono',
                'ok' => $unassignedAmendments === 0,
                'icon' => 'user-round-check',
                'route' => route('work-center.index', ['queue' => 'unassigned']),
                'action' => 'Atribuir',
            ],
            [
                'label' => 'Documentos',
                'value' => $openDocuments.' pendência(s)',
                'ok' => $openDocuments === 0,
                'icon' => 'file-check-2',
                'route' => route('work-center.index', ['queue' => 'documents']),
                'action' => 'Anexar',
            ],
        ];
        $score = (int) round(collect($checks)->where('ok', true)->count() / count($checks) * 100);

        return [
            'score' => $score,
            'tone' => $score >= 85 ? 'ready' : ($score >= 55 ? 'attention' : 'blocked'),
            'title' => $score >= 85 ? 'Município pronto para operar' : 'Município ainda exige atenção',
            'subtitle' => $score >= 85
                ? 'A base municipal está consistente para acompanhar Câmara, Executivo e controle.'
                : 'Resolva os pontos abaixo para reduzir dúvidas, atrasos e retrabalho operacional.',
            'checks' => $checks,
        ];
    }
}
