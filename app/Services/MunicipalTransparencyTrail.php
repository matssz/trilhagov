<?php

namespace App\Services;

use App\Models\MunicipalWorkPlanStage;
use App\Models\ParliamentaryAmendment;
use Illuminate\Support\Carbon;

class MunicipalTransparencyTrail
{
    /** @var array<string, string> */
    private const PUBLIC_FIELDS = [
        'reference' => 'Identificação',
        'author_name' => 'Autor',
        'object' => 'Objeto e finalidade',
        'responsible_department' => 'Órgão executor',
        'expected_amount' => 'Valor autorizado',
        'received_amount' => 'Valor liberado',
        'expense_destination' => 'Destinação',
        'beneficiary_location' => 'Localidade beneficiada',
        'legal_instrument' => 'Instrumento jurídico',
        'administrative_process' => 'Processo administrativo',
        'bank_tracking_type' => 'Forma de rastreabilidade bancária',
        'bank_account_number' => 'Conta bancária',
        'funding_source_code' => 'Fonte de recursos',
        'application_code_fixed' => 'Código de aplicação fixo',
        'application_code_variable' => 'Código de aplicação variável',
        'application_deadline' => 'Prazo de aplicação',
        'status' => 'Situação',
        'cancellation_reason' => 'Motivo do cancelamento',
        'cancelled_at' => 'Data do cancelamento',
    ];

    public function recordCreation(ParliamentaryAmendment $amendment): void
    {
        $this->record($amendment, 'created', 'Emenda cadastrada', 'A emenda foi incluída no acompanhamento municipal.', [
            'Valor autorizado' => $this->money($amendment->expected_amount),
            'Situação' => $amendment->statusLabel(),
        ]);
    }

    /** @param array<string, mixed> $before */
    public function recordAmendmentChanges(ParliamentaryAmendment $amendment, array $before): void
    {
        $changes = [];

        foreach (self::PUBLIC_FIELDS as $field => $label) {
            if (! $amendment->wasChanged($field)) {
                continue;
            }

            $changes[$label] = [
                'anterior' => $this->present($field, $before[$field] ?? null),
                'atual' => $this->present($field, $amendment->getAttribute($field)),
            ];
        }

        if ($changes === []) {
            return;
        }

        $oldAmount = (float) ($before['expected_amount'] ?? 0);
        $newAmount = (float) $amendment->expected_amount;
        $type = $amendment->status === ParliamentaryAmendment::STATUS_CANCELLED
            ? 'cancelled'
            : ($newAmount > $oldAmount ? 'value_increased' : ($newAmount < $oldAmount ? 'value_reduced' : 'updated'));
        $title = match ($type) {
            'cancelled' => 'Emenda cancelada',
            'value_increased' => 'Valor autorizado acrescido',
            'value_reduced' => 'Valor autorizado reduzido',
            default => 'Dados públicos atualizados',
        };

        $this->record($amendment, $type, $title, 'Alteração refletida no portal de transparência municipal.', $changes);
    }

    public function recordPayment(ParliamentaryAmendment $amendment, string $reference, mixed $amount, mixed $paidAt): void
    {
        $this->record($amendment, 'execution_updated', 'Valor executado atualizado', 'Pagamento vinculado à emenda municipal.', [
            'Referência do pagamento' => $reference,
            'Valor' => $this->money($amount),
            'Data' => $this->date($paidAt),
        ]);
    }

    public function recordSchedule(ParliamentaryAmendment $amendment, string $action, MunicipalWorkPlanStage $stage): void
    {
        $title = match ($action) {
            'created' => 'Etapa incluída no cronograma',
            'deleted' => 'Etapa retirada do cronograma',
            default => 'Etapa do cronograma atualizada',
        };

        $this->record($amendment, 'schedule_updated', $title, 'O cronograma físico-financeiro foi atualizado.', [
            'Etapa' => $stage->title,
            'Entrega física' => $stage->physical_delivery,
            'Valor planejado' => $this->money($stage->planned_amount),
            'Início' => $this->date($stage->planned_start_at),
            'Fim' => $this->date($stage->planned_end_at),
        ]);
    }

    /** @param array<string, mixed> $changes */
    private function record(ParliamentaryAmendment $amendment, string $type, string $title, string $description, array $changes): void
    {
        $amendment->transparencyEvents()->create([
            'municipality_id' => $amendment->municipality_id,
            'event_type' => $type,
            'title' => $title,
            'description' => $description,
            'changes' => $changes,
            'occurred_at' => now(),
        ]);
    }

    private function present(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'expected_amount', 'received_amount' => $this->money($value),
            'application_deadline', 'cancelled_at' => $this->date($value),
            'status' => ParliamentaryAmendment::statuses()[(string) $value] ?? (string) $value,
            'expense_destination' => ParliamentaryAmendment::expenseDestinations()[(string) $value] ?? (string) $value,
            'bank_tracking_type' => ParliamentaryAmendment::bankTrackingTypes()[(string) $value] ?? (string) $value,
            default => (string) $value,
        };
    }

    private function money(mixed $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }

    private function date(mixed $value): string
    {
        return Carbon::parse($value)->format('d/m/Y');
    }
}
