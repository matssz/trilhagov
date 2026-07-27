<?php

namespace App\Services;

use App\Models\MunicipalRegulatoryProfile;

class MunicipalOrganicLawAutomation
{
    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'regime_status' => MunicipalRegulatoryProfile::REGIME_INSTITUTED,
            'individual_limit_percentage' => 1.55,
            'health_reserve_percentage' => 50,
            'health_reserve_method' => 'per_councilor',
            'generic_amendments_prohibited' => true,
            'prior_technical_review_required' => true,
            'work_plan_required' => true,
            'pca_check_required' => true,
            'impediment_notice_days' => 30,
            'impediment_correction_days' => 30,
            'publication_business_days' => 1,
            'document_retention_years' => 5,
            'bank_traceability_rule' => 'direct_execution_traceability',
            'audesp_registration_status' => 'in_progress',
        ];
    }

    /** @return array<string, mixed> */
    public function applyTo(MunicipalRegulatoryProfile $profile, bool $overwrite = false): array
    {
        $values = [];

        foreach ($this->defaults() as $field => $value) {
            if ($overwrite || $profile->{$field} === null || $profile->{$field} === MunicipalRegulatoryProfile::REGIME_UNDER_REVIEW) {
                $values[$field] = $value;
            }
        }

        if ($values !== []) {
            $profile->update($values);
        }

        return $values;
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    public function mergeInto(array $validated): array
    {
        foreach ($this->defaults() as $field => $value) {
            $validated[$field] = $value;
        }

        return $validated;
    }
}
