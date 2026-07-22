<?php

namespace App\Services;

use App\Models\Municipality;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\ParliamentaryAmendment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class MunicipalRuleApplicationService
{
    /** @var array<string, MunicipalRegulatoryProfile|null> */
    private array $activeProfiles = [];

    public function activeProfile(Municipality $municipality, int $fiscalYear): ?MunicipalRegulatoryProfile
    {
        $key = $municipality->id.':'.$fiscalYear;

        return $this->activeProfiles[$key] ??= $municipality->regulatoryProfiles()
            ->with('instruments')
            ->where('fiscal_year', $fiscalYear)
            ->where('status', MunicipalRegulatoryProfile::STATUS_ACTIVE)
            ->latest('version')
            ->first();
    }

    public function profileFor(Municipality $municipality, int $fiscalYear, string $governmentSphere): ?MunicipalRegulatoryProfile
    {
        return $governmentSphere === 'municipal'
            ? $this->activeProfile($municipality, $fiscalYear)
            : null;
    }

    /** @param array<string, mixed> $data @return array<string, string> */
    public function blockingErrors(
        Municipality $municipality,
        array $data,
        ?ParliamentaryAmendment $current = null,
    ): array {
        $profile = $current?->regulatoryProfile
            ?? $this->profileFor($municipality, (int) $data['fiscal_year'], (string) $data['government_sphere']);

        if ($current?->municipal_regulatory_profile_id !== null
            && ((int) $data['fiscal_year'] !== $current->fiscal_year || $data['government_sphere'] !== $current->government_sphere)) {
            return ['fiscal_year' => 'O exercício e a esfera não podem ser alterados depois que a emenda foi vinculada a uma versão normativa.'];
        }

        if ($profile === null
            || $profile->regime_status !== MunicipalRegulatoryProfile::REGIME_INSTITUTED
            || ($data['authorship_type'] ?? null) !== 'individual') {
            return [];
        }

        $errors = [];
        $amount = (float) ($data['expected_amount'] ?? 0);
        if ($profile->minimum_amendment_amount !== null && $amount < (float) $profile->minimum_amendment_amount) {
            $errors['expected_amount'] = 'O valor é inferior ao mínimo municipal de R$ '
                .number_format((float) $profile->minimum_amendment_amount, 2, ',', '.')
                ." definido na revisão {$profile->fiscal_year}/v{$profile->version}.";
        }

        $authorQuery = $this->authorQuery($profile, (string) $data['author_name'], $current?->id);
        $existingCount = (clone $authorQuery)->count();
        if ($profile->amendments_per_councilor_limit !== null
            && $existingCount + 1 > $profile->amendments_per_councilor_limit) {
            $errors['author_name'] = "O autor já atingiu o limite municipal de {$profile->amendments_per_councilor_limit} emenda(s) neste exercício.";
        }

        $ceiling = $this->ceiling($profile);
        $authorTotal = (float) (clone $authorQuery)->sum('expected_amount') + $amount;
        if ($ceiling !== null && $authorTotal > $ceiling + 0.005) {
            $errors['expected_amount'] = 'A soma das emendas deste autor chegaria a R$ '
                .number_format($authorTotal, 2, ',', '.')
                .', acima do teto municipal de R$ '.number_format($ceiling, 2, ',', '.').'.';
        }

        return $errors;
    }

    /** @return array{profile: ?MunicipalRegulatoryProfile, author_count: int, author_total: float, ceiling: ?float, remaining: ?float, violations: array<int, array{code: string, title: string, message: string, severity: string}>} */
    public function assessment(ParliamentaryAmendment $amendment): array
    {
        $amendment->loadMissing(['municipality', 'regulatoryProfile', 'municipalWorkPlan']);
        $profile = $amendment->regulatoryProfile
            ?? $this->profileFor($amendment->municipality, $amendment->fiscal_year, $amendment->government_sphere);
        $violations = [];
        $authorCount = 0;
        $authorTotal = 0.0;
        $ceiling = null;

        if ($amendment->government_sphere === 'municipal' && $profile === null) {
            $violations[] = [
                'code' => 'profile_missing',
                'title' => 'Norma municipal não vinculada',
                'message' => 'Ative a configuração normativa deste exercício para aplicar limites e prazos locais.',
                'severity' => 'warning',
            ];
        } elseif ($profile !== null && $profile->regime_status !== MunicipalRegulatoryProfile::REGIME_INSTITUTED) {
            $violations[] = [
                'code' => 'regime_not_instituted',
                'title' => 'Regime impositivo não instituído',
                'message' => 'A versão normativa vinculada não reconhece o regime como instituído na Lei Orgânica.',
                'severity' => 'critical',
            ];
        } elseif ($profile !== null && $amendment->authorship_type === 'individual') {
            $query = $this->authorQuery($profile, $amendment->author_name);
            $authorCount = (clone $query)->count();
            $authorTotal = (float) (clone $query)->sum('expected_amount');
            $ceiling = $this->ceiling($profile);

            if ($profile->minimum_amendment_amount !== null
                && (float) $amendment->expected_amount < (float) $profile->minimum_amendment_amount) {
                $violations[] = [
                    'code' => 'minimum_amount',
                    'title' => 'Valor abaixo do mínimo municipal',
                    'message' => 'Valor informado: R$ '.number_format((float) $amendment->expected_amount, 2, ',', '.')
                        .'. Mínimo da revisão: R$ '.number_format((float) $profile->minimum_amendment_amount, 2, ',', '.').'.',
                    'severity' => 'critical',
                ];
            }
            if ($profile->amendments_per_councilor_limit !== null && $authorCount > $profile->amendments_per_councilor_limit) {
                $violations[] = [
                    'code' => 'author_count',
                    'title' => 'Quantidade acima do limite',
                    'message' => "O autor possui {$authorCount} emendas; a revisão permite {$profile->amendments_per_councilor_limit}.",
                    'severity' => 'critical',
                ];
            }
            if ($ceiling !== null && $authorTotal > $ceiling + 0.005) {
                $violations[] = [
                    'code' => 'author_ceiling',
                    'title' => 'Teto individual ultrapassado',
                    'message' => 'Total do autor: R$ '.number_format($authorTotal, 2, ',', '.')
                        .'. Teto calculado: R$ '.number_format($ceiling, 2, ',', '.').'.',
                    'severity' => 'critical',
                ];
            }
        }

        if ($profile !== null
            && (float) $profile->health_reserve_percentage > 0
            && $amendment->municipalWorkPlan?->health_related
            && ! $amendment->municipalWorkPlan->health_reserve_verified) {
            $violations[] = [
                'code' => 'health_reserve_unverified',
                'title' => 'Reserva da saúde não conferida',
                'message' => 'O plano indica objeto de saúde, mas a conferência da reserva municipal ainda está pendente.',
                'severity' => 'warning',
            ];
        }

        return [
            'profile' => $profile,
            'author_count' => $authorCount,
            'author_total' => $authorTotal,
            'ceiling' => $ceiling,
            'remaining' => $ceiling === null ? null : max(0, $ceiling - $authorTotal),
            'violations' => $violations,
        ];
    }

    public function suggestedImpedimentDueDate(ParliamentaryAmendment $amendment, mixed $identifiedAt): ?string
    {
        $profile = $amendment->regulatoryProfile;
        if ($profile?->impediment_correction_days === null || blank($identifiedAt)) {
            return null;
        }

        return Carbon::parse($identifiedAt)->addDays($profile->impediment_correction_days)->toDateString();
    }

    public function suggestedImpedimentCommunicationDate(ParliamentaryAmendment $amendment, mixed $identifiedAt): ?string
    {
        $profile = $amendment->regulatoryProfile;
        if ($profile?->impediment_notice_days === null || blank($identifiedAt)) {
            return null;
        }

        return Carbon::parse($identifiedAt)->addDays($profile->impediment_notice_days)->toDateString();
    }

    /** @return array{amendment_count: int, author_count: int, total: float, health_total: float, health_required: ?float, shortfall: ?float, unclassified: int, authors_below: int, status: string} */
    public function portfolioAssessment(MunicipalRegulatoryProfile $profile): array
    {
        $amendments = $profile->amendments()
            ->where('government_sphere', 'municipal')
            ->where('authorship_type', 'individual')
            ->with('municipalWorkPlan:id,parliamentary_amendment_id,health_related')
            ->get();
        $total = (float) $amendments->sum('expected_amount');
        $healthTotal = (float) $amendments
            ->filter(fn (ParliamentaryAmendment $amendment) => $amendment->municipalWorkPlan?->health_related === true)
            ->sum('expected_amount');
        $unclassified = $amendments->filter(fn (ParliamentaryAmendment $amendment) => $amendment->municipalWorkPlan === null)->count();
        $percentage = $profile->health_reserve_percentage === null
            ? null
            : (float) $profile->health_reserve_percentage / 100;
        $required = $percentage === null ? null : $total * $percentage;
        $shortfall = $required === null ? null : max(0.0, $required - $healthTotal);
        $authorsBelow = 0;

        if ($percentage !== null && $profile->health_reserve_method === 'per_councilor') {
            $shortfall = 0.0;
            foreach ($amendments->groupBy(fn (ParliamentaryAmendment $amendment) => mb_strtolower(trim($amendment->author_name))) as $authorAmendments) {
                $authorTotal = (float) $authorAmendments->sum('expected_amount');
                $authorHealth = (float) $authorAmendments
                    ->filter(fn (ParliamentaryAmendment $amendment) => $amendment->municipalWorkPlan?->health_related === true)
                    ->sum('expected_amount');
                $authorShortfall = max(0.0, $authorTotal * $percentage - $authorHealth);
                $shortfall += $authorShortfall;
                if ($authorShortfall > 0.005) {
                    $authorsBelow++;
                }
            }
        }

        $status = match (true) {
            $percentage === null || $profile->health_reserve_method === null => 'not_configured',
            $unclassified > 0 => 'pending_classification',
            ($shortfall ?? 0) > 0.005 => 'attention',
            default => 'compliant',
        };

        return [
            'amendment_count' => $amendments->count(),
            'author_count' => $amendments->pluck('author_name')->map(fn (string $name) => mb_strtolower(trim($name)))->unique()->count(),
            'total' => $total,
            'health_total' => $healthTotal,
            'health_required' => $required,
            'shortfall' => $shortfall,
            'unclassified' => $unclassified,
            'authors_below' => $authorsBelow,
            'status' => $status,
        ];
    }

    private function ceiling(MunicipalRegulatoryProfile $profile): ?float
    {
        if ($profile->previous_year_rcl === null || $profile->individual_limit_percentage === null) {
            return null;
        }

        $global = (float) $profile->previous_year_rcl * (float) $profile->individual_limit_percentage / 100;

        return $profile->councilor_seats ? $global / $profile->councilor_seats : $global;
    }

    private function authorQuery(MunicipalRegulatoryProfile $profile, string $authorName, ?int $ignoreId = null): Builder
    {
        return ParliamentaryAmendment::query()
            ->where('municipal_regulatory_profile_id', $profile->id)
            ->where('authorship_type', 'individual')
            ->whereRaw('LOWER(author_name) = ?', [mb_strtolower(trim($authorName))])
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId));
    }
}
