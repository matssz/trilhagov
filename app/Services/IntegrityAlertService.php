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
    /** @return array{created: int, updated: int, resolved: int, open: int} */
    public function sync(Municipality $municipality): array
    {
        $settings = $municipality->alertSetting()->firstOrCreate([]);
        $municipality->load([
            'amendments.documents:id,parliamentary_amendment_id,document_type_id',
            'documentTypes' => fn ($query) => $query->active()->orderBy('sort_order'),
        ]);

        $detections = [];

        foreach ($municipality->amendments as $amendment) {
            $this->detectDeadlines($amendment, $settings, $detections);
            $this->detectMissingDocuments($amendment, $municipality, $detections);
            $this->detectInconsistencies($amendment, $detections);
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

            $message = match (true) {
                $daysUntil < 0 => sprintf('%s vencida há %d dia(s).', $label, abs($daysUntil)),
                $daysUntil === 0 => $label.' vence hoje.',
                default => sprintf('%s vence em %d dia(s).', $label, $daysUntil),
            };

            $this->addDetection($detections, $amendment, "deadline:{$key}", [
                'category' => IntegrityAlert::CATEGORY_DEADLINE,
                'severity' => $severity,
                'title' => $daysUntil < 0 ? 'Prazo vencido' : 'Prazo próximo',
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

    /**
     * @param  array<int, array<string, mixed>>  $detections
     * @param  array{category: string, severity: string, title: string, message: string, due_at: Carbon|null}  $attributes
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
                ...$attributes,
            ],
        ];
    }
}
