<?php

namespace App\Services;

use App\Models\IntegrityAlert;
use App\Models\Municipality;
use App\Models\MunicipalityAlertSetting;
use App\Models\ParliamentaryAmendment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IntegrityAlertService
{
    public function __construct(private readonly AccountabilityService $accountabilityService) {}

    /** @return array{created: int, updated: int, resolved: int, open: int} */
    public function sync(Municipality $municipality): array
    {
        $settings = $municipality->alertSetting()->firstOrCreate([]);
        $municipality->load([
            'amendments.documents:id,parliamentary_amendment_id,document_type_id,execution_stage_id',
            'amendments.executionStages',
            'amendments.financialCommitments.payments',
            'amendments.accountabilityProcess.requirements',
            'amendments.accountabilityProcess.diligences',
            'documentTypes' => fn ($query) => $query->active()->orderBy('sort_order'),
            'users:id,name,email',
        ]);
        $operationalUserIds = $municipality->users
            ->filter(fn ($user) => in_array($user->pivot->role, ['manager', 'editor'], true))
            ->pluck('id')
            ->all();

        $detections = [];

        foreach ($municipality->amendments as $amendment) {
            $this->detectDeadlines($amendment, $settings, $detections);
            $this->detectMissingDocuments($amendment, $municipality, $detections);
            $this->detectInconsistencies($amendment, $detections);
            $this->detectExecutionControls($amendment, $settings, $detections);
            $this->detectAccountabilityControls($amendment, $settings, $detections);
            $this->detectAssignment($amendment, $operationalUserIds, $detections);
        }

        return DB::transaction(function () use ($municipality, $detections): array {
            $stats = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'open' => count($detections)];
            $detectedKeys = [];

            foreach ($detections as $detection) {
                $detectedKeys[] = $detection['compound_key'];
                $alert = IntegrityAlert::query()->firstOrCreate(
                    [
                        'parliamentary_amendment_id' => $detection['parliamentary_amendment_id'],
                        'alert_key' => $detection['alert_key'],
                    ],
                    [
                        ...$detection['attributes'],
                        'status' => IntegrityAlert::STATUS_OPEN,
                        'detected_at' => now(),
                    ],
                );
                $isNew = $alert->wasRecentlyCreated;
                $wasResolved = $alert->status === IntegrityAlert::STATUS_RESOLVED;

                $alert->fill($detection['attributes']);
                $alert->status = IntegrityAlert::STATUS_OPEN;
                $alert->resolved_at = null;

                if ($isNew || $wasResolved) {
                    $alert->detected_at = now();
                }

                if ($isNew) {
                    $stats['created']++;
                } elseif ($alert->isDirty()) {
                    $stats['updated']++;
                }

                $alert->save();
            }

            $openAlerts = $municipality->integrityAlerts()
                ->where('status', IntegrityAlert::STATUS_OPEN)
                ->get();

            foreach ($openAlerts as $alert) {
                $compoundKey = $alert->parliamentary_amendment_id.':'.$alert->alert_key;

                if (in_array($compoundKey, $detectedKeys, true)) {
                    continue;
                }

                $alert->update([
                    'status' => IntegrityAlert::STATUS_RESOLVED,
                    'resolved_at' => now(),
                ]);
                $stats['resolved']++;
            }

            $this->recalculateRisk($municipality);

            return $stats;
        });
    }

    /** @param array<int, array<string, mixed>> $detections */
    private function detectDeadlines(
        ParliamentaryAmendment $amendment,
        MunicipalityAlertSetting $settings,
        array &$detections,
    ): void {
        if ($amendment->status === ParliamentaryAmendment::STATUS_COMPLETED) {
            return;
        }

        $deadlines = [
            'communication' => ['Comunicação e publicidade', 'communication_deadline', 'communication_completed_at'],
            'execution' => ['Execução', 'execution_deadline', 'execution_completed_at'],
            'accountability' => ['Prestação de contas', 'accountability_deadline', 'accountability_completed_at'],
        ];

        foreach ($deadlines as $key => [$label, $dateField, $completedField]) {
            $dueAt = $amendment->{$dateField};

            if ($dueAt === null || $amendment->{$completedField} !== null) {
                continue;
            }

            $daysUntil = (int) today()->diffInDays($dueAt, false);

            if ($daysUntil > $settings->deadline_warning_days) {
                continue;
            }

            $severity = $daysUntil <= $settings->deadline_critical_days
                ? IntegrityAlert::SEVERITY_CRITICAL
                : IntegrityAlert::SEVERITY_INFO;
            $overdueDays = max(0, -$daysUntil);
            $escalationLevel = match (true) {
                $overdueDays >= $settings->escalation_level_two_days => 2,
                $overdueDays >= $settings->escalation_level_one_days => 1,
                default => 0,
            };

            $message = match (true) {
                $daysUntil < 0 => sprintf('%s vencida há %d dia(s).', $label, abs($daysUntil)),
                $daysUntil === 0 => $label.' vence hoje.',
                default => sprintf('%s vence em %d dia(s).', $label, $daysUntil),
            };

            $this->addDetection($detections, $amendment, "deadline:{$key}", [
                'category' => IntegrityAlert::CATEGORY_DEADLINE,
                'severity' => $severity,
                'escalation_level' => $escalationLevel,
                'title' => match ($escalationLevel) {
                    2 => 'Prazo em escalonamento máximo',
                    1 => 'Prazo escalonado',
                    default => $daysUntil < 0 ? 'Prazo vencido' : 'Prazo próximo',
                },
                'message' => $message,
                'due_at' => $dueAt,
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $detections */
    private function detectMissingDocuments(
        ParliamentaryAmendment $amendment,
        Municipality $municipality,
        array &$detections,
    ): void {
        $uploadedTypeIds = $amendment->documents->pluck('document_type_id')->unique();

        foreach ($municipality->documentTypes->where('is_required', true) as $documentType) {
            if ($uploadedTypeIds->contains($documentType->id)) {
                continue;
            }

            $this->addDetection($detections, $amendment, "document:{$documentType->id}", [
                'category' => IntegrityAlert::CATEGORY_DOCUMENT,
                'severity' => IntegrityAlert::SEVERITY_WARNING,
                'title' => 'Documento obrigatório pendente',
                'message' => "O checklist exige: {$documentType->name}.",
                'due_at' => null,
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $detections */
    private function detectInconsistencies(ParliamentaryAmendment $amendment, array &$detections): void
    {
        $receivedStatuses = [
            ParliamentaryAmendment::STATUS_RESOURCE_RECEIVED,
            ParliamentaryAmendment::STATUS_EXECUTING,
            ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING,
            ParliamentaryAmendment::STATUS_COMPLETED,
        ];

        if (in_array($amendment->status, $receivedStatuses, true)
            && ($amendment->received_amount === null || $amendment->received_at === null)) {
            $this->addDetection($detections, $amendment, 'consistency:receipt', [
                'category' => IntegrityAlert::CATEGORY_CONSISTENCY,
                'severity' => IntegrityAlert::SEVERITY_CRITICAL,
                'title' => 'Recebimento incompleto',
                'message' => 'O status informa recurso recebido, mas o valor ou a data de recebimento não foi registrado.',
                'due_at' => null,
            ]);
        }

        if ($amendment->received_amount !== null
            && (float) $amendment->received_amount > (float) $amendment->expected_amount) {
            $this->addDetection($detections, $amendment, 'consistency:amount', [
                'category' => IntegrityAlert::CATEGORY_CONSISTENCY,
                'severity' => IntegrityAlert::SEVERITY_WARNING,
                'title' => 'Valor recebido acima do previsto',
                'message' => 'Confirme os valores previsto e recebido antes de prosseguir.',
                'due_at' => null,
            ]);
        }

        if ($amendment->status === ParliamentaryAmendment::STATUS_COMPLETED) {
            $missing = collect([
                'comunicação' => $amendment->communication_completed_at,
                'execução' => $amendment->execution_completed_at,
                'prestação de contas' => $amendment->accountability_completed_at,
            ])->filter(fn ($value) => $value === null)->keys();

            if ($missing->isNotEmpty()) {
                $this->addDetection($detections, $amendment, 'consistency:completed-milestones', [
                    'category' => IntegrityAlert::CATEGORY_CONSISTENCY,
                    'severity' => IntegrityAlert::SEVERITY_CRITICAL,
                    'title' => 'Conclusão sem marcos finalizados',
                    'message' => 'Falta registrar a conclusão de: '.$missing->join(', ', ' e ').'.',
                    'due_at' => null,
                ]);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $detections */
    private function detectExecutionControls(
        ParliamentaryAmendment $amendment,
        MunicipalityAlertSetting $settings,
        array &$detections,
    ): void {
        foreach ($amendment->executionStages as $stage) {
            if ($stage->status === 'completed' || $stage->planned_end_at === null) {
                continue;
            }

            $daysUntil = (int) today()->diffInDays($stage->planned_end_at, false);

            if ($daysUntil > $settings->deadline_warning_days) {
                continue;
            }

            $overdueDays = max(0, -$daysUntil);
            $escalationLevel = match (true) {
                $overdueDays >= $settings->escalation_level_two_days => 2,
                $overdueDays >= $settings->escalation_level_one_days => 1,
                default => 0,
            };
            $severity = $daysUntil <= $settings->deadline_critical_days
                ? IntegrityAlert::SEVERITY_CRITICAL
                : IntegrityAlert::SEVERITY_INFO;
            $message = match (true) {
                $daysUntil < 0 => "A etapa {$stage->title} está atrasada há ".abs($daysUntil).' dia(s).',
                $daysUntil === 0 => "A etapa {$stage->title} vence hoje.",
                default => "A etapa {$stage->title} vence em {$daysUntil} dia(s).",
            };

            $this->addDetection($detections, $amendment, "deadline:stage:{$stage->id}", [
                'assigned_user_id' => $stage->responsible_user_id ?? $amendment->responsible_user_id,
                'category' => IntegrityAlert::CATEGORY_DEADLINE,
                'severity' => $severity,
                'escalation_level' => $escalationLevel,
                'title' => $daysUntil < 0 ? 'Etapa de execução atrasada' : 'Prazo de etapa próximo',
                'message' => $message,
                'due_at' => $stage->planned_end_at,
            ]);
        }

        $executionStatuses = [
            ParliamentaryAmendment::STATUS_EXECUTING,
            ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING,
            ParliamentaryAmendment::STATUS_COMPLETED,
        ];

        if (in_array($amendment->status, $executionStatuses, true) && $amendment->executionStages->isEmpty()) {
            $this->addDetection($detections, $amendment, 'execution:missing-stages', [
                'category' => IntegrityAlert::CATEGORY_CONSISTENCY,
                'severity' => IntegrityAlert::SEVERITY_WARNING,
                'title' => 'Execução sem etapas cadastradas',
                'message' => 'O acompanhamento físico precisa de etapas e entregas verificáveis.',
                'due_at' => null,
            ]);
        }

        $activeCommitments = $amendment->financialCommitments->where('status', 'active');
        $committedAmount = (float) $activeCommitments->sum('committed_amount');
        $paidAmount = (float) $activeCommitments->sum(fn ($commitment) => $commitment->payments->sum('amount'));
        $receivedAmount = $amendment->received_amount === null ? null : (float) $amendment->received_amount;

        if ($receivedAmount !== null && $committedAmount > $receivedAmount + 0.00001) {
            $this->addDetection($detections, $amendment, 'execution:commitments-over-received', [
                'category' => IntegrityAlert::CATEGORY_CONSISTENCY,
                'severity' => IntegrityAlert::SEVERITY_CRITICAL,
                'title' => 'Empenhos acima do valor recebido',
                'message' => 'O total empenhado supera o recurso recebido em R$ '.number_format($committedAmount - $receivedAmount, 2, ',', '.').'.',
                'due_at' => null,
            ]);
        }

        if ($receivedAmount !== null && $paidAmount > $receivedAmount + 0.00001) {
            $this->addDetection($detections, $amendment, 'execution:payments-over-received', [
                'category' => IntegrityAlert::CATEGORY_CONSISTENCY,
                'severity' => IntegrityAlert::SEVERITY_CRITICAL,
                'title' => 'Pagamentos acima do valor recebido',
                'message' => 'O total pago supera o recurso recebido em R$ '.number_format($paidAmount - $receivedAmount, 2, ',', '.').'.',
                'due_at' => null,
            ]);
        }

        if ($paidAmount > 0 && $amendment->documents->whereNotNull('execution_stage_id')->isEmpty()) {
            $this->addDetection($detections, $amendment, 'execution:payments-without-evidence', [
                'category' => IntegrityAlert::CATEGORY_DOCUMENT,
                'severity' => IntegrityAlert::SEVERITY_WARNING,
                'title' => 'Pagamento sem evidência de entrega',
                'message' => 'Há pagamento registrado, mas nenhuma evidência foi vinculada às etapas de execução.',
                'due_at' => null,
            ]);
        }

        if ($amendment->status === ParliamentaryAmendment::STATUS_COMPLETED
            && $amendment->physicalExecutionPercentage() < 100) {
            $this->addDetection($detections, $amendment, 'execution:completed-with-open-stages', [
                'category' => IntegrityAlert::CATEGORY_CONSISTENCY,
                'severity' => IntegrityAlert::SEVERITY_CRITICAL,
                'title' => 'Emenda concluída com execução física pendente',
                'message' => 'A média das etapas está abaixo de 100%. Revise as entregas antes de encerrar a emenda.',
                'due_at' => null,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $operationalUserIds
     * @param  array<int, array<string, mixed>>  $detections
     */
    private function detectAssignment(
        ParliamentaryAmendment $amendment,
        array $operationalUserIds,
        array &$detections,
    ): void {
        if ($amendment->status === ParliamentaryAmendment::STATUS_COMPLETED) {
            return;
        }

        if ($amendment->responsible_user_id === null) {
            $this->addDetection($detections, $amendment, 'assignment:missing', [
                'category' => IntegrityAlert::CATEGORY_ASSIGNMENT,
                'severity' => IntegrityAlert::SEVERITY_WARNING,
                'title' => 'Responsável operacional não definido',
                'message' => 'A emenda precisa de uma pessoa responsável para receber e tratar os alertas.',
                'due_at' => null,
            ]);

            return;
        }

        if (! in_array($amendment->responsible_user_id, $operationalUserIds, true)) {
            $this->addDetection($detections, $amendment, 'assignment:invalid', [
                'category' => IntegrityAlert::CATEGORY_ASSIGNMENT,
                'severity' => IntegrityAlert::SEVERITY_CRITICAL,
                'title' => 'Responsável sem acesso operacional',
                'message' => 'A pessoa atribuída não possui mais perfil de gestor ou editor neste município.',
                'due_at' => null,
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $detections */
    private function detectAccountabilityControls(
        ParliamentaryAmendment $amendment,
        MunicipalityAlertSetting $settings,
        array &$detections,
    ): void {
        $process = $amendment->accountabilityProcess;

        if ($process === null) {
            if ($amendment->status === ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING) {
                $this->addDetection($detections, $amendment, 'accountability:missing-process', [
                    'category' => IntegrityAlert::CATEGORY_CONSISTENCY,
                    'severity' => IntegrityAlert::SEVERITY_WARNING,
                    'title' => 'Prestação de contas não iniciada',
                    'message' => 'A emenda está aguardando prestação, mas o processo de controle ainda não foi aberto.',
                    'due_at' => $amendment->accountability_deadline,
                ]);
            }

            return;
        }

        if ($process->due_at !== null
            && ($amendment->accountability_deadline === null || ! $process->due_at->equalTo($amendment->accountability_deadline))
            && $process->status !== 'approved') {
            $daysUntil = (int) today()->diffInDays($process->due_at, false);

            if ($daysUntil <= $settings->deadline_warning_days) {
                $overdueDays = max(0, -$daysUntil);
                $escalationLevel = match (true) {
                    $overdueDays >= $settings->escalation_level_two_days => 2,
                    $overdueDays >= $settings->escalation_level_one_days => 1,
                    default => 0,
                };
                $this->addDetection($detections, $amendment, 'deadline:accountability-process', [
                    'assigned_user_id' => $process->responsible_user_id ?? $amendment->responsible_user_id,
                    'category' => IntegrityAlert::CATEGORY_DEADLINE,
                    'severity' => $daysUntil <= $settings->deadline_critical_days
                        ? IntegrityAlert::SEVERITY_CRITICAL
                        : IntegrityAlert::SEVERITY_INFO,
                    'escalation_level' => $escalationLevel,
                    'title' => $daysUntil < 0 ? 'Prestação de contas atrasada' : 'Prazo da prestação próximo',
                    'message' => $daysUntil < 0
                        ? 'A prestação está atrasada há '.abs($daysUntil).' dia(s).'
                        : 'A prestação vence em '.$daysUntil.' dia(s).',
                    'due_at' => $process->due_at,
                ]);
            }
        }

        foreach ($process->diligences->where('status', 'open') as $diligence) {
            $daysUntil = (int) today()->diffInDays($diligence->due_at, false);

            if ($daysUntil > $settings->deadline_warning_days) {
                continue;
            }

            $overdueDays = max(0, -$daysUntil);
            $escalationLevel = match (true) {
                $overdueDays >= $settings->escalation_level_two_days => 2,
                $overdueDays >= $settings->escalation_level_one_days => 1,
                default => 0,
            };
            $this->addDetection($detections, $amendment, "deadline:diligence:{$diligence->id}", [
                'assigned_user_id' => $diligence->assigned_user_id ?? $process->responsible_user_id ?? $amendment->responsible_user_id,
                'category' => IntegrityAlert::CATEGORY_DEADLINE,
                'severity' => $daysUntil <= $settings->deadline_critical_days
                    ? IntegrityAlert::SEVERITY_CRITICAL
                    : IntegrityAlert::SEVERITY_INFO,
                'escalation_level' => $escalationLevel,
                'title' => $daysUntil < 0 ? 'Diligência atrasada' : 'Prazo de diligência próximo',
                'message' => $daysUntil < 0
                    ? "A diligência {$diligence->title} está atrasada há ".abs($daysUntil).' dia(s).'
                    : "A diligência {$diligence->title} vence em {$daysUntil} dia(s).",
                'due_at' => $diligence->due_at,
            ]);
        }

        $readiness = $this->accountabilityService->readiness($amendment, $process);

        if (in_array($process->status, ['submitted', 'under_review', 'approved'], true)
            && ! $readiness['ready']) {
            $this->addDetection($detections, $amendment, 'accountability:submitted-with-pendencies', [
                'assigned_user_id' => $process->responsible_user_id ?? $amendment->responsible_user_id,
                'category' => IntegrityAlert::CATEGORY_CONSISTENCY,
                'severity' => IntegrityAlert::SEVERITY_CRITICAL,
                'title' => 'Prestação enviada com pendências',
                'message' => $readiness['blockers']->first(),
                'due_at' => $process->due_at,
            ]);
        }

        if ($process->status === 'preparing'
            && $process->due_at !== null
            && $process->due_at->lte(today()->addDays($settings->deadline_warning_days))
            && ! $readiness['ready']) {
            $this->addDetection($detections, $amendment, 'accountability:not-ready', [
                'assigned_user_id' => $process->responsible_user_id ?? $amendment->responsible_user_id,
                'category' => IntegrityAlert::CATEGORY_CONSISTENCY,
                'severity' => IntegrityAlert::SEVERITY_WARNING,
                'title' => 'Prestação ainda não está pronta',
                'message' => $readiness['blockers']->first(),
                'due_at' => $process->due_at,
            ]);
        }
    }

    private function recalculateRisk(Municipality $municipality): void
    {
        $alertsByAmendment = $municipality->integrityAlerts()
            ->where('status', IntegrityAlert::STATUS_OPEN)
            ->get()
            ->groupBy('parliamentary_amendment_id');

        foreach ($municipality->amendments as $amendment) {
            $alerts = $alertsByAmendment->get($amendment->id, collect());
            $score = $alerts->sum(fn (IntegrityAlert $alert) => match ($alert->severity) {
                IntegrityAlert::SEVERITY_CRITICAL => 25,
                IntegrityAlert::SEVERITY_WARNING => 12,
                default => 4,
            });
            $highestEscalation = (int) $alerts->max('escalation_level');
            $score += match ($highestEscalation) {
                2 => 20,
                1 => 10,
                default => 0,
            };
            $assignmentAlert = $alerts->firstWhere('category', IntegrityAlert::CATEGORY_ASSIGNMENT);

            if ($assignmentAlert !== null) {
                $score += $assignmentAlert->severity === IntegrityAlert::SEVERITY_CRITICAL ? 15 : 8;
            }

            if ($amendment->status === ParliamentaryAmendment::STATUS_BLOCKED) {
                $score += 20;
            }

            $score = min(100, $score);
            $riskLevel = match (true) {
                $score >= 70 => ParliamentaryAmendment::RISK_CRITICAL,
                $score >= 40 => ParliamentaryAmendment::RISK_HIGH,
                $score >= 20 => ParliamentaryAmendment::RISK_MODERATE,
                default => ParliamentaryAmendment::RISK_LOW,
            };
            $reasons = $alerts
                ->sortByDesc(fn (IntegrityAlert $alert) => match ($alert->severity) {
                    IntegrityAlert::SEVERITY_CRITICAL => 3,
                    IntegrityAlert::SEVERITY_WARNING => 2,
                    default => 1,
                })
                ->map(fn (IntegrityAlert $alert) => $alert->title.': '.$alert->message)
                ->when(
                    $amendment->status === ParliamentaryAmendment::STATUS_BLOCKED,
                    fn ($items) => $items->prepend('Emenda marcada com impedimento.'),
                )
                ->unique()
                ->take(5)
                ->values()
                ->all();

            $amendment->risk_score = $score;
            $amendment->risk_level = $riskLevel;
            $amendment->risk_reasons = $reasons;
            $amendment->risk_calculated_at = now();
            $amendment->timestamps = false;
            $amendment->saveQuietly();
            $amendment->timestamps = true;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $detections
     * @param  array{category: string, severity: string, title: string, message: string, due_at: Carbon|null, escalation_level?: int, assigned_user_id?: int|null}  $attributes
     */
    private function addDetection(
        array &$detections,
        ParliamentaryAmendment $amendment,
        string $alertKey,
        array $attributes,
    ): void {
        $detections[] = [
            'compound_key' => $amendment->id.':'.$alertKey,
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => $alertKey,
            'attributes' => [
                'municipality_id' => $amendment->municipality_id,
                'parliamentary_amendment_id' => $amendment->id,
                'alert_key' => $alertKey,
                'escalation_level' => 0,
                ...$attributes,
            ],
        ];
    }
}
