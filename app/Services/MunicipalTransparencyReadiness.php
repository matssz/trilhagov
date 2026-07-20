<?php

namespace App\Services;

use App\Models\ParliamentaryAmendment;
use Illuminate\Support\Carbon;

class MunicipalTransparencyReadiness
{
    /** @return array{missing: array<int, string>, complete: bool, publication_deadline: Carbon, latest_event_at: ?Carbon, on_time: bool} */
    public function evaluate(ParliamentaryAmendment $amendment): array
    {
        $missing = collect([
            'expense_destination' => 'Destinação em custeio ou investimento',
            'beneficiary_location' => 'Município ou localidade beneficiada',
            'administrative_process' => 'Processo administrativo',
            'application_deadline' => 'Prazo para aplicação dos recursos',
            'bank_tracking_type' => 'Forma de rastreabilidade dos recursos',
        ])->filter(fn (string $label, string $field) => blank($amendment->getAttribute($field)))
            ->values()
            ->all();

        if ($amendment->bank_tracking_type === 'specific_account' && blank($amendment->bank_account_number)) {
            $missing[] = 'Número da conta bancária específica';
        }

        if ($amendment->bank_tracking_type === 'municipal_direct_codes') {
            foreach ([
                'funding_source_code' => 'Fonte de Recursos',
                'application_code_fixed' => 'Código de Aplicação Fixo',
                'application_code_variable' => 'Código de Aplicação Variável',
            ] as $field => $label) {
                if (blank($amendment->getAttribute($field))) {
                    $missing[] = $label;
                }
            }
        }

        if (! $amendment->municipalWorkPlan?->stages?->count()) {
            $missing[] = 'Cronograma físico-financeiro';
        }

        $latestEvent = $amendment->transparencyEvents?->max('occurred_at');
        $deadline = $this->nextBusinessDay($amendment->updated_at->copy());

        return [
            'missing' => $missing,
            'complete' => $missing === [],
            'publication_deadline' => $deadline,
            'latest_event_at' => $latestEvent,
            'on_time' => $latestEvent !== null && $latestEvent->lessThanOrEqualTo($deadline->endOfDay()),
        ];
    }

    private function nextBusinessDay(Carbon $date): Carbon
    {
        do {
            $date->addDay();
        } while ($date->isWeekend());

        return $date;
    }
}
