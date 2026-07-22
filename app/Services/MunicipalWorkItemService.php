<?php

namespace App\Services;

use App\Models\AccountabilityProcess;
use App\Models\AccountabilityRequirement;
use App\Models\AudespHomologationBatch;
use App\Models\AudespHomologationItem;
use App\Models\ExecutionStage;
use App\Models\ExternalFinancialReconciliation;
use App\Models\FinancialCommitment;
use App\Models\HealthAspsAssessment;
use App\Models\MunicipalAuditPlan;
use App\Models\MunicipalAuditPlanItem;
use App\Models\MunicipalInternalControlAction;
use App\Models\Municipality;
use App\Models\MunicipalWorkItem;
use App\Models\MunicipalWorkPlan;
use App\Models\ParliamentaryAmendment;
use App\Models\TechnicalDiligence;
use App\Models\TechnicalImpediment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MunicipalWorkItemService
{
    public function __construct(
        private readonly MunicipalWorkPlanService $workPlanService,
        private readonly MunicipalRuleApplicationService $municipalRules,
        private readonly MunicipalTransparencyReadiness $transparencyReadiness,
        private readonly AudespTraceabilityService $audespTraceability,
    ) {}

    /** @return array{active: int, created: int, reopened: int, completed: int} */
    public function synchronize(Municipality $municipality): array
    {
        $requiredDocumentTypes = $municipality->documentTypes()
            ->active()
            ->where('is_required', true)
            ->orderBy('sort_order')
            ->get();
        $amendments = $municipality->amendments()->with([
            'municipality:id,state,ibge_code',
            'documents:id,parliamentary_amendment_id,document_type_id',
            'municipalWorkPlan.stages',
            'healthAspsAssessments',
            'auditPlanItems.plan',
            'internalControlReviews.actions',
            'transparencyEvents',
            'regulatoryProfile',
            'technicalImpediments.diligences',
            'technicalImpediments.remappings',
            'executionStages',
            'financialCommitments',
            'financialCommitments.liquidations.payments',
            'financialPayments',
            'audespRegistration',
            'audespHomologationItems.batch',
            'accountabilityProcess.requirements',
            'accountabilityProcess.diligences',
            'externalCandidates.latestFinancialReconciliation',
        ])->get();

        return DB::transaction(function () use ($municipality, $amendments, $requiredDocumentTypes): array {
            $stats = ['active' => 0, 'created' => 0, 'reopened' => 0, 'completed' => 0];
            $activeKeys = [];

            foreach ($amendments as $amendment) {
                if ($amendment->status === ParliamentaryAmendment::STATUS_COMPLETED) {
                    continue;
                }

                foreach ($this->specifications($amendment, $requiredDocumentTypes) as $specification) {
                    $activeKeys[] = $specification['source_key'];
                    $item = $municipality->workItems()->firstOrNew([
                        'source_key' => $specification['source_key'],
                    ]);
                    $isNew = ! $item->exists;
                    $wasCompleted = $item->status === MunicipalWorkItem::STATUS_COMPLETED;

                    $item->fill([
                        'parliamentary_amendment_id' => $amendment->id,
                        'category' => $specification['category'],
                        'title' => $specification['title'],
                        'guidance' => $specification['guidance'],
                        'action_url' => $specification['action_url'],
                        'priority' => $this->priority($specification['due_at'], $specification['base_priority']),
                        'due_at' => $specification['due_at'],
                        'last_evaluated_at' => now(),
                    ]);

                    if ($isNew) {
                        $item->responsible_user_id = $specification['responsible_user_id'];
                        $item->status = MunicipalWorkItem::STATUS_PENDING;
                        $item->first_detected_at = now();
                        $stats['created']++;
                    } elseif ($wasCompleted) {
                        $item->status = MunicipalWorkItem::STATUS_PENDING;
                        $item->completed_at = null;
                        $item->completion_reason = null;
                        $stats['reopened']++;
                    } elseif ($item->responsible_user_id === null) {
                        $item->responsible_user_id = $specification['responsible_user_id'];
                    }

                    $item->save();
                    if ($isNew) {
                        $this->recordSystemEvent($item, 'created', null, MunicipalWorkItem::STATUS_PENDING, 'Ação identificada na avaliação municipal.');
                    } elseif ($wasCompleted) {
                        $this->recordSystemEvent($item, 'reopened', MunicipalWorkItem::STATUS_COMPLETED, MunicipalWorkItem::STATUS_PENDING, 'A pendência voltou a ser identificada na fonte.');
                    }
                    $stats['active']++;
                }
            }

            $activeKeySet = array_fill_keys($activeKeys, true);
            $resolvedItems = $municipality->workItems()
                ->whereIn('status', [MunicipalWorkItem::STATUS_PENDING, MunicipalWorkItem::STATUS_IN_PROGRESS])
                ->get()
                ->reject(fn (MunicipalWorkItem $item) => isset($activeKeySet[$item->source_key]));

            foreach ($resolvedItems as $item) {
                $fromStatus = $item->status;
                $item->update([
                    'status' => MunicipalWorkItem::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'completion_reason' => 'Resolvida após atualização dos dados de origem.',
                    'last_evaluated_at' => now(),
                ]);
                $this->recordSystemEvent($item, 'auto_completed', $fromStatus, MunicipalWorkItem::STATUS_COMPLETED, 'Pendência de origem corrigida.');
                $stats['completed']++;
            }

            return $stats;
        });
    }

    /**
     * @return array<int, array{source_key: string, category: string, title: string, guidance: string, action_url: string, due_at: Carbon|null, base_priority: string, responsible_user_id: int|null}>
     */
    private function specifications(ParliamentaryAmendment $amendment, iterable $requiredDocumentTypes): array
    {
        $items = [];
        $nextDeadline = $amendment->nextDeadline()['date'] ?? null;

        if ($amendment->responsible_user_id === null) {
            $items[] = $this->specification(
                "amendment:{$amendment->id}:responsible",
                'responsibility',
                'Definir responsável pela emenda',
                'Escolha um gestor ou editor para centralizar o acompanhamento e receber os próximos alertas.',
                route('emendas.edit', $amendment, false),
                $nextDeadline,
                MunicipalWorkItem::PRIORITY_HIGH,
            );
        }

        foreach ($this->municipalRules->assessment($amendment)['violations'] as $violation) {
            $actionUrl = in_array($violation['code'], ['profile_missing', 'regime_not_instituted'], true)
                ? route('municipal-rules.index', [], false)
                : ($violation['code'] === 'health_reserve_unverified'
                    ? route('emendas.work-plan', $amendment, false)
                    : route('emendas.edit', $amendment, false));
            $items[] = $this->specification(
                "amendment:{$amendment->id}:normative:{$violation['code']}",
                'normative',
                $violation['title'],
                $violation['message'],
                $actionUrl,
                $nextDeadline,
                $violation['severity'] === 'critical'
                    ? MunicipalWorkItem::PRIORITY_HIGH
                    : MunicipalWorkItem::PRIORITY_NORMAL,
                $amendment->responsible_user_id,
            );
        }

        if ($amendment->supportsTcespCompliance()) {
            $transparency = $this->transparencyReadiness->evaluate($amendment);
            if (! $transparency['complete']) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:transparency:minimum-list",
                    'transparency',
                    'Completar transparência da emenda',
                    'Preencha o rol mínimo municipal: '.implode(', ', $transparency['missing']).'.',
                    route('emendas.edit', $amendment, false),
                    $transparency['publication_deadline'],
                    $transparency['publication_deadline']->isBefore(now())
                        ? MunicipalWorkItem::PRIORITY_CRITICAL
                        : MunicipalWorkItem::PRIORITY_HIGH,
                    $amendment->responsible_user_id,
                );
            }

            $audesp = $this->audespTraceability->evaluate($amendment);
            if (! $audesp['ready']) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:audesp-readiness",
                    'financial',
                    'Preparar cadastro contábil Audesp',
                    collect($audesp['blockers'])->take(2)->join(' '),
                    route('emendas.audesp', $amendment, false),
                    null,
                    MunicipalWorkItem::PRIORITY_CRITICAL,
                    $amendment->responsible_user_id,
                );
            }

            $latestHomologationItem = $amendment->audespHomologationItems->first();
            if ($latestHomologationItem?->batch?->status === AudespHomologationBatch::STATUS_UNDER_REVIEW
                && $latestHomologationItem->status !== AudespHomologationItem::STATUS_MATCHED) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:audesp-homologation:{$latestHomologationItem->batch->id}",
                    'financial',
                    'Corrigir divergência do XML Audesp',
                    $latestHomologationItem->status === AudespHomologationItem::STATUS_UNMATCHED
                        ? 'O registro do Siafic não encontrou cadastro correspondente no TrilhaGov.'
                        : count($latestHomologationItem->differences ?? []).' campo(s) divergem entre o Siafic e o cadastro municipal.',
                    route('audesp-homologations.show', $latestHomologationItem->batch, false),
                    null,
                    MunicipalWorkItem::PRIORITY_CRITICAL,
                    $amendment->responsible_user_id,
                );
            }

            if ($latestHomologationItem?->batch?->status === AudespHomologationBatch::STATUS_REJECTED) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:audesp-rejection:{$latestHomologationItem->batch->id}",
                    'financial',
                    'Tratar rejeição do Audesp',
                    'Analise o retorno anexado, corrija a origem com a contabilidade e registre um novo lote vinculado à tentativa rejeitada.',
                    route('audesp-homologations.show', $latestHomologationItem->batch, false),
                    null,
                    MunicipalWorkItem::PRIORITY_CRITICAL,
                    $amendment->responsible_user_id,
                );
            }

            $plan = $amendment->municipalWorkPlan;

            if ($plan === null) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:municipal-work-plan:create",
                    'planning',
                    'Iniciar plano de trabalho municipal',
                    'Estruture beneficiário, metas, custos e cronograma antes da análise de admissibilidade.',
                    route('emendas.work-plan', $amendment, false),
                    $amendment->communication_deadline,
                    MunicipalWorkItem::PRIORITY_HIGH,
                    $amendment->responsible_user_id,
                );
            } elseif ($plan->isEditable()) {
                $readiness = $this->workPlanService->readiness($plan, $amendment);
                $isAdjustment = $plan->status === MunicipalWorkPlan::STATUS_ADJUSTMENTS_REQUESTED;
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:municipal-work-plan:prepare",
                    'planning',
                    $isAdjustment ? 'Corrigir plano devolvido' : 'Concluir plano de trabalho',
                    $isAdjustment
                        ? 'Atenda os ajustes registrados no parecer e envie uma nova revisão para análise.'
                        : ($readiness['blockers'][0] ?? 'Confira o plano e envie a primeira revisão para análise técnica.'),
                    route('emendas.work-plan', $amendment, false),
                    $amendment->communication_deadline,
                    $isAdjustment ? MunicipalWorkItem::PRIORITY_HIGH : MunicipalWorkItem::PRIORITY_NORMAL,
                    $amendment->responsible_user_id,
                );
            } elseif ($plan->status === MunicipalWorkPlan::STATUS_UNDER_REVIEW) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:municipal-work-plan:review:{$plan->revision_number}",
                    'planning',
                    "Emitir parecer de admissibilidade R{$plan->revision_number}",
                    'Avalie todos os critérios e conclua pela aprovação, devolução para ajustes ou rejeição fundamentada.',
                    route('emendas.work-plan', $amendment, false).'#parecer',
                    $amendment->communication_deadline,
                    MunicipalWorkItem::PRIORITY_HIGH,
                );
            }

            if ($plan?->health_related) {
                $issuedAsps = $amendment->healthAspsAssessments
                    ->first(fn (HealthAspsAssessment $assessment) => $assessment->status === HealthAspsAssessment::STATUS_ISSUED);
                if (! $issuedAsps) {
                    $items[] = $this->specification(
                        "amendment:{$amendment->id}:health-asps:assessment",
                        'health',
                        'Concluir enquadramento ASPS',
                        'Classifique a despesa pelos arts. 2º a 4º da LC 141 e submeta o parecer ao Controle Interno.',
                        route('health-asps.show', $amendment, false),
                        $amendment->communication_deadline,
                        MunicipalWorkItem::PRIORITY_HIGH,
                        $amendment->responsible_user_id,
                    );
                } elseif ($issuedAsps->conclusion === HealthAspsAssessment::CONCLUSION_INELIGIBLE) {
                    $items[] = $this->specification(
                        "amendment:{$amendment->id}:health-asps:ineligible:{$issuedAsps->id}",
                        'health',
                        'Tratar despesa não computável como ASPS',
                        'O parecer emitido identificou impedimento ao cômputo em ASPS. Revise o objeto, a fonte ou a classificação antes do consolidado.',
                        route('health-asps.show', $amendment, false),
                        null,
                        MunicipalWorkItem::PRIORITY_CRITICAL,
                        $amendment->responsible_user_id,
                    );
                }
            }

            foreach ($amendment->internalControlReviews->flatMap->actions as $controlAction) {
                if ($controlAction->status === MunicipalInternalControlAction::STATUS_RESOLVED) {
                    continue;
                }

                $awaitingValidation = $controlAction->status === MunicipalInternalControlAction::STATUS_RESPONDED;
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:internal-control-action:{$controlAction->id}",
                    'control',
                    $awaitingValidation ? 'Validar saneamento do Controle Interno' : 'Responder apontamento do Controle Interno',
                    $awaitingValidation
                        ? 'Confira a resposta e a evidência; encerre a providência ou devolva-a com fundamentação e novo prazo.'
                        : $controlAction->instructions,
                    route('emendas.internal-control', $amendment, false).'#providencia-'.$controlAction->id,
                    $controlAction->due_at,
                    $controlAction->isOverdue() ? MunicipalWorkItem::PRIORITY_CRITICAL : MunicipalWorkItem::PRIORITY_HIGH,
                    $awaitingValidation ? null : $controlAction->responsible_user_id,
                );
            }

            foreach ($amendment->auditPlanItems as $auditPlanItem) {
                if ($auditPlanItem->plan->status !== MunicipalAuditPlan::STATUS_ISSUED
                    || in_array($auditPlanItem->status, [MunicipalAuditPlanItem::STATUS_COMPLETED, MunicipalAuditPlanItem::STATUS_CANCELLED], true)) {
                    continue;
                }

                $items[] = $this->specification(
                    "amendment:{$amendment->id}:audit-plan-item:{$auditPlanItem->id}",
                    'control',
                    $auditPlanItem->status === MunicipalAuditPlanItem::STATUS_IN_PROGRESS
                        ? 'Concluir verificação do Plano Anual'
                        : 'Executar verificação do Plano Anual',
                    $auditPlanItem->scope_notes,
                    route('audit-plans.show', $auditPlanItem->plan, false).'#item-'.$auditPlanItem->id,
                    $auditPlanItem->planned_at,
                    $auditPlanItem->isOverdue() ? MunicipalWorkItem::PRIORITY_CRITICAL : MunicipalWorkItem::PRIORITY_HIGH,
                    $auditPlanItem->assigned_user_id,
                );
            }
        }

        foreach ($amendment->technicalImpediments as $impediment) {
            if ($impediment->communicated_at === null && $impediment->communication_due_at !== null) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:technical-impediment:{$impediment->id}:communication",
                    'communication',
                    'Comunicar impedimento formalmente',
                    'Registre a data e o protocolo da comunicação ao Legislativo dentro do prazo municipal.',
                    route('emendas.impediments', $amendment, false).'#impedimento-'.$impediment->id,
                    $impediment->communication_due_at,
                    MunicipalWorkItem::PRIORITY_HIGH,
                    $impediment->assigned_user_id ?? $amendment->responsible_user_id,
                );
            }

            if ($impediment->isOpen()) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:technical-impediment:{$impediment->id}",
                    'impediment',
                    "Resolver impedimento: {$impediment->title}",
                    $impediment->nature === TechnicalImpediment::NATURE_INSURMOUNTABLE
                        ? 'Fundamente a conclusão e avalie o remanejamento do objeto antes de encerrar a ocorrência.'
                        : 'Conduza a análise, reúna evidências e registre a solução ou a classificação definitiva.',
                    route('emendas.impediments', $amendment, false).'#impedimento-'.$impediment->id,
                    $impediment->resolution_due_at,
                    $impediment->isOverdue() ? MunicipalWorkItem::PRIORITY_CRITICAL : MunicipalWorkItem::PRIORITY_HIGH,
                    $impediment->assigned_user_id ?? $amendment->responsible_user_id,
                );
            }

            foreach ($impediment->diligences->where('status', TechnicalDiligence::STATUS_OPEN) as $diligence) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:technical-diligence:{$diligence->id}",
                    'impediment',
                    "Responder diligência: {$diligence->title}",
                    'Prepare a resposta, vincule a evidência e registre o protocolo dentro do prazo.',
                    route('emendas.impediments', $amendment, false).'#impedimento-'.$impediment->id,
                    $diligence->due_at,
                    MunicipalWorkItem::PRIORITY_HIGH,
                    $diligence->assigned_user_id ?? $impediment->assigned_user_id ?? $amendment->responsible_user_id,
                );
            }

            foreach ($impediment->remappings->where('status', 'submitted') as $remapping) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:remapping-decision:{$remapping->id}",
                    'impediment',
                    'Decidir proposta de remanejamento',
                    'Confira o novo objeto, o valor e a justificativa; registre a decisão e sua referência formal.',
                    route('emendas.impediments', $amendment, false).'#impedimento-'.$impediment->id,
                    $impediment->resolution_due_at,
                    MunicipalWorkItem::PRIORITY_HIGH,
                );
            }
        }

        if ($amendment->communication_completed_at === null && $amendment->communication_deadline !== null) {
            $items[] = $this->specification(
                "amendment:{$amendment->id}:communication",
                'communication',
                'Concluir comunicação e publicidade',
                'Registre a data de conclusão e mantenha a evidência correspondente no dossiê da emenda.',
                route('emendas.edit', $amendment, false),
                $amendment->communication_deadline,
                MunicipalWorkItem::PRIORITY_NORMAL,
                $amendment->responsible_user_id,
            );
        }

        $documentTypeIds = $amendment->documents->pluck('document_type_id')->unique();
        foreach ($requiredDocumentTypes as $documentType) {
            if ($documentTypeIds->contains($documentType->id)) {
                continue;
            }

            $items[] = $this->specification(
                "amendment:{$amendment->id}:document:{$documentType->id}",
                'document',
                "Anexar {$documentType->name}",
                $documentType->description ?: 'Anexe o documento exigido pelo checklist municipal e preserve sua versão no histórico.',
                route('emendas.show', $amendment, false).'#documentos',
                $nextDeadline,
                MunicipalWorkItem::PRIORITY_NORMAL,
                $amendment->responsible_user_id,
            );
        }

        if ($this->hasReceivedResources($amendment)) {
            if ($amendment->executionStages->isEmpty()) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:execution-plan",
                    'execution',
                    'Planejar etapas da execução',
                    'Divida o objeto em entregas verificáveis, atribua responsáveis e informe os prazos de cada etapa.',
                    route('emendas.execution', $amendment, false),
                    $amendment->execution_deadline,
                    MunicipalWorkItem::PRIORITY_HIGH,
                    $amendment->responsible_user_id,
                );
            }

            foreach ($amendment->executionStages as $stage) {
                if ($stage->status === ExecutionStage::STATUS_COMPLETED) {
                    continue;
                }

                $items[] = $this->specification(
                    "amendment:{$amendment->id}:stage:{$stage->id}",
                    'execution',
                    "Acompanhar etapa: {$stage->title}",
                    'Atualize o progresso, registre impedimentos e anexe evidências da entrega física.',
                    route('emendas.execution', $amendment, false).'#etapa-'.$stage->id,
                    $stage->planned_end_at,
                    $stage->status === ExecutionStage::STATUS_BLOCKED
                        ? MunicipalWorkItem::PRIORITY_HIGH
                        : MunicipalWorkItem::PRIORITY_NORMAL,
                    $stage->responsible_user_id ?? $amendment->responsible_user_id,
                );
            }

            if ($amendment->financialCommitments->where('status', FinancialCommitment::STATUS_ACTIVE)->isEmpty()) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:first-commitment",
                    'financial',
                    'Registrar contratação e primeiro empenho',
                    'Vincule o processo de contratação, o fornecedor e o empenho à execução municipal.',
                    route('emendas.execution', $amendment, false).'#commitments',
                    $amendment->execution_deadline,
                    MunicipalWorkItem::PRIORITY_NORMAL,
                    $amendment->responsible_user_id,
                );
            }
        }

        foreach ($amendment->externalCandidates as $candidate) {
            $reconciliation = $candidate->latestFinancialReconciliation;
            if ($reconciliation?->status === ExternalFinancialReconciliation::STATUS_CONSISTENT) {
                continue;
            }

            $items[] = $this->specification(
                "amendment:{$amendment->id}:financial-reconciliation:{$candidate->id}",
                'financial',
                'Conferir repasse com a fonte oficial',
                $reconciliation
                    ? 'Revise as diferenças entre os valores municipais e os dados financeiros publicados pelo Transferegov.'
                    : 'Consulte empenhos federais, ordens bancárias e o saldo publicado para criar a primeira conciliação.',
                route('integrations.index', [], false).'#candidato-'.$candidate->id,
                $nextDeadline,
                $reconciliation?->status === ExternalFinancialReconciliation::STATUS_DIVERGENT
                    ? MunicipalWorkItem::PRIORITY_HIGH
                    : MunicipalWorkItem::PRIORITY_NORMAL,
                $amendment->responsible_user_id,
            );
        }

        $process = $amendment->accountabilityProcess;
        if ($process === null && $this->shouldStartAccountability($amendment)) {
            $items[] = $this->specification(
                "amendment:{$amendment->id}:start-accountability",
                'accountability',
                'Iniciar preparação da prestação de contas',
                'Abra o processo, confira o checklist e distribua as pendências antes do prazo final.',
                route('emendas.accountability', $amendment, false),
                $amendment->accountability_deadline,
                MunicipalWorkItem::PRIORITY_HIGH,
                $amendment->responsible_user_id,
            );
        }

        if ($process !== null && $process->status !== AccountabilityProcess::STATUS_APPROVED) {
            foreach ($process->requirements->where('status', AccountabilityRequirement::STATUS_PENDING) as $requirement) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:accountability-requirement:{$requirement->id}",
                    'accountability',
                    "Resolver checklist: {$requirement->title}",
                    $requirement->description ?: 'Conclua o item ou registre, com justificativa, quando ele não for aplicável.',
                    route('emendas.accountability', $amendment, false).'#requisito-'.$requirement->id,
                    $process->due_at ?? $amendment->accountability_deadline,
                    $requirement->is_required ? MunicipalWorkItem::PRIORITY_HIGH : MunicipalWorkItem::PRIORITY_NORMAL,
                    $process->responsible_user_id ?? $amendment->responsible_user_id,
                );
            }

            foreach ($process->diligences->where('status', 'open') as $diligence) {
                $items[] = $this->specification(
                    "amendment:{$amendment->id}:diligence:{$diligence->id}",
                    'accountability',
                    "Responder diligência: {$diligence->title}",
                    'Prepare a resposta, registre o protocolo e preserve a documentação enviada ao órgão responsável.',
                    route('emendas.accountability', $amendment, false).'#diligencia-'.$diligence->id,
                    $diligence->due_at,
                    MunicipalWorkItem::PRIORITY_HIGH,
                    $diligence->assigned_user_id ?? $process->responsible_user_id ?? $amendment->responsible_user_id,
                );
            }
        }

        return $items;
    }

    /**
     * @return array{source_key: string, category: string, title: string, guidance: string, action_url: string, due_at: Carbon|null, base_priority: string, responsible_user_id: int|null}
     */
    private function specification(
        string $sourceKey,
        string $category,
        string $title,
        string $guidance,
        string $actionUrl,
        ?Carbon $dueAt,
        string $basePriority,
        ?int $responsibleUserId = null,
    ): array {
        return [
            'source_key' => $sourceKey,
            'category' => $category,
            'title' => $title,
            'guidance' => $guidance,
            'action_url' => $actionUrl,
            'due_at' => $dueAt,
            'base_priority' => $basePriority,
            'responsible_user_id' => $responsibleUserId,
        ];
    }

    private function priority(?Carbon $dueAt, string $basePriority): string
    {
        if ($dueAt?->isBefore(today())) {
            return MunicipalWorkItem::PRIORITY_CRITICAL;
        }

        if ($dueAt?->lessThanOrEqualTo(today()->addDays(7))) {
            return MunicipalWorkItem::PRIORITY_HIGH;
        }

        return $basePriority;
    }

    private function hasReceivedResources(ParliamentaryAmendment $amendment): bool
    {
        return in_array($amendment->status, [
            ParliamentaryAmendment::STATUS_RESOURCE_RECEIVED,
            ParliamentaryAmendment::STATUS_EXECUTING,
            ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING,
        ], true);
    }

    private function shouldStartAccountability(ParliamentaryAmendment $amendment): bool
    {
        return $amendment->status === ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING
            || $amendment->execution_completed_at !== null
            || ($amendment->accountability_deadline !== null
                && $amendment->accountability_deadline->lessThanOrEqualTo(today()->addDays(60)));
    }

    private function recordSystemEvent(
        MunicipalWorkItem $item,
        string $eventType,
        ?string $fromStatus,
        string $toStatus,
        string $description,
    ): void {
        $item->events()->create([
            'municipality_id' => $item->municipality_id,
            'actor_name' => 'Sistema TrilhaGov',
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'description' => $description,
        ]);
    }
}
