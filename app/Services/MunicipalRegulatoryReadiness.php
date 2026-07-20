<?php

namespace App\Services;

use App\Models\MunicipalRegulatoryProfile;

class MunicipalRegulatoryReadiness
{
    /** @return array{blockers: array<int, string>, warnings: array<int, string>, checks: array<int, array{label: string, complete: bool}>, score: int, ceiling: ?float} */
    public function evaluate(MunicipalRegulatoryProfile $profile): array
    {
        $profile->loadMissing('instruments');
        $types = $profile->instruments->pluck('type')->unique();
        $blockers = [];
        $warnings = [];
        $checks = [];

        $this->check($checks, $blockers, $profile->regime_status !== MunicipalRegulatoryProfile::REGIME_UNDER_REVIEW, 'Situação do regime definida');
        $this->check($checks, $blockers, $types->contains('organic_law'), 'Lei Orgânica registrada');

        if ($profile->regime_status === MunicipalRegulatoryProfile::REGIME_INSTITUTED) {
            foreach (['internal_rules' => 'Regimento Interno', 'ppa' => 'PPA', 'ldo' => 'LDO', 'loa' => 'LOA'] as $type => $label) {
                $this->check($checks, $blockers, $types->contains($type), $label.' registrado');
            }

            $this->check($checks, $blockers, $profile->previous_year_rcl !== null, 'RCL do exercício anterior informada');
            $this->check($checks, $blockers, $profile->individual_limit_percentage !== null, 'Percentual-limite validado');
            $this->check($checks, $blockers, $profile->health_reserve_percentage !== null && $profile->health_reserve_method !== null, 'Reserva da saúde parametrizada');
            $this->check($checks, $blockers, $profile->prior_technical_review_required !== null, 'Análise técnica prévia definida');
            $this->check($checks, $blockers, $profile->generic_amendments_prohibited !== null, 'Tratamento de objetos genéricos definido');
            $this->check($checks, $blockers, $profile->work_plan_required !== null, 'Exigência de plano de trabalho definida');
            $this->check($checks, $blockers, $profile->impediment_notice_days !== null, 'Prazo de comunicação do impedimento informado');
            $this->check($checks, $blockers, $profile->impediment_correction_days !== null, 'Prazo de saneamento informado');
            $this->check($checks, $blockers, $profile->bank_traceability_rule !== null, 'Regra de rastreabilidade bancária definida');
        }

        $legalReviewComplete = filled($profile->legal_review_responsible)
            && filled($profile->legal_review_reference)
            && $profile->legal_reviewed_at !== null;
        $this->check($checks, $blockers, $legalReviewComplete, 'Revisão jurídica municipal registrada');

        if ($profile->publication_business_days === null) {
            $warnings[] = 'Defina o prazo local para atualização do portal de transparência.';
        } elseif ($profile->municipality?->state === 'SP' && $profile->publication_business_days > 1) {
            $warnings[] = 'No TCESP, a referência de tempo real é o próximo dia útil; revise o prazo informado.';
        }

        if ($profile->audesp_registration_status !== 'ready' && $profile->municipality?->state === 'SP') {
            $warnings[] = 'A preparação para o cadastro e a remessa Audesp ainda não foi concluída.';
        }
        if (! $types->contains('regulation')) {
            $warnings[] = 'Nenhum decreto ou ato regulamentador foi vinculado.';
        }
        if ($profile->document_retention_years === null) {
            $warnings[] = 'A política municipal de retenção documental ainda não foi definida.';
        }

        $total = count($checks);
        $complete = count(array_filter($checks, fn (array $check) => $check['complete']));
        $ceiling = $profile->previous_year_rcl !== null && $profile->individual_limit_percentage !== null
            ? (float) $profile->previous_year_rcl * (float) $profile->individual_limit_percentage / 100
            : null;

        return [
            'blockers' => $blockers,
            'warnings' => $warnings,
            'checks' => $checks,
            'score' => $total === 0 ? 0 : (int) round($complete / $total * 100),
            'ceiling' => $ceiling,
        ];
    }

    /** @param array<int, array{label: string, complete: bool}> $checks @param array<int, string> $blockers */
    private function check(array &$checks, array &$blockers, bool $complete, string $label): void
    {
        $checks[] = ['label' => $label, 'complete' => $complete];
        if (! $complete) {
            $blockers[] = $label;
        }
    }
}
