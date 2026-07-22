<?php

namespace App\Services;

use App\Models\LegislativeProposal;
use App\Models\Municipality;
use App\Models\MunicipalRegulatoryProfile;
use App\Models\ParliamentaryAmendment;
use App\Models\User;

class LegislativeProposalService
{
    /** @return array<string, array{label: string, guidance: string}> */
    public function reviewChecklist(): array
    {
        return [
            'review_ppa' => ['label' => 'Compatibilidade com o PPA', 'guidance' => 'A proposta se relaciona a programa, objetivo ou entrega prevista no planejamento plurianual.'],
            'review_ldo' => ['label' => 'Compatibilidade com a LDO', 'guidance' => 'A proposta observa prioridades, prazos e regras do exercício.'],
            'review_loa' => ['label' => 'Enquadramento na LOA', 'guidance' => 'Programa, ação e classificação comportam o objeto indicado.'],
            'review_sector_plan' => ['label' => 'Coerência com o plano setorial', 'guidance' => 'O objeto é coerente com a política pública e o plano da área responsável.'],
            'review_budget_limit' => ['label' => 'Teto e quantidade conferidos', 'guidance' => 'A cota do vereador, o valor mínimo e a quantidade máxima foram respeitados.'],
            'review_health_reserve' => ['label' => 'Reserva da saúde conferida', 'guidance' => 'A classificação e a proporção definida na norma municipal foram verificadas.'],
            'review_object' => ['label' => 'Objeto preciso e executável', 'guidance' => 'O objeto não é genérico e representa entrega completa ou etapa útil.'],
            'review_beneficiary' => ['label' => 'Beneficiário identificado', 'guidance' => 'Nome, localização, CNPJ e declaração de conflito foram conferidos quando aplicáveis.'],
            'review_viability' => ['label' => 'Viabilidade preliminar', 'guidance' => 'Estimativa, quantitativo, prazo e capacidade de manutenção são minimamente coerentes.'],
        ];
    }

    public function profile(Municipality $municipality, int $year): ?MunicipalRegulatoryProfile
    {
        return $municipality->regulatoryProfiles()
            ->where('fiscal_year', $year)
            ->where('status', MunicipalRegulatoryProfile::STATUS_ACTIVE)
            ->latest('version')
            ->first();
    }

    /** @return array<string, mixed> */
    public function quota(
        Municipality $municipality,
        ?MunicipalRegulatoryProfile $profile,
        string $authorName,
        ?LegislativeProposal $current = null,
        ?float $projectedAmount = null,
        ?bool $projectedHealth = null,
    ): array {
        $globalCeiling = $profile?->previous_year_rcl !== null && $profile?->individual_limit_percentage !== null
            ? (float) $profile->previous_year_rcl * (float) $profile->individual_limit_percentage / 100
            : null;
        $seats = $profile?->councilor_seats;
        $authorCeiling = $globalCeiling === null
            ? null
            : ($seats ? $globalCeiling / $seats : $globalCeiling);

        $amendments = $profile
            ? $profile->amendments()
                ->where('authorship_type', 'individual')
                ->whereRaw('LOWER(author_name) = ?', [mb_strtolower(trim($authorName))])
                ->with('municipalWorkPlan:id,parliamentary_amendment_id,health_related')
                ->get()
            : collect();
        $proposals = $profile
            ? LegislativeProposal::query()
                ->where('municipal_regulatory_profile_id', $profile->id)
                ->whereRaw('LOWER(author_name) = ?', [mb_strtolower(trim($authorName))])
                ->whereNull('parliamentary_amendment_id')
                ->where('status', '!=', LegislativeProposal::STATUS_REJECTED)
                ->when($current, fn ($query) => $query->where('id', '!=', $current->id))
                ->get()
            : collect();

        $used = (float) $amendments->sum('expected_amount') + (float) $proposals->sum('estimated_amount');
        $health = (float) $amendments
            ->filter(fn (ParliamentaryAmendment $amendment) => $amendment->indicated_for_health === true || $amendment->municipalWorkPlan?->health_related === true)
            ->sum('expected_amount')
            + (float) $proposals->where('health_related', true)->sum('estimated_amount');
        $count = $amendments->count() + $proposals->count();

        if ($projectedAmount !== null) {
            $used += $projectedAmount;
            $count++;
            if ($projectedHealth === true) {
                $health += $projectedAmount;
            }
        } elseif ($current !== null) {
            $used += (float) $current->estimated_amount;
            $count++;
            if ($current->health_related) {
                $health += (float) $current->estimated_amount;
            }
        }

        $healthPercentage = $profile?->health_reserve_percentage === null
            ? null
            : (float) $profile->health_reserve_percentage;
        $healthRequired = $healthPercentage === null ? null : $used * $healthPercentage / 100;

        return [
            'profile' => $profile,
            'global_ceiling' => $globalCeiling,
            'councilor_seats' => $seats,
            'author_ceiling' => $authorCeiling,
            'used' => $used,
            'remaining' => $authorCeiling === null ? null : max(0, $authorCeiling - $used),
            'count' => $count,
            'count_limit' => $profile?->amendments_per_councilor_limit,
            'health_percentage' => $healthPercentage,
            'health_allocated' => $health,
            'health_required' => $healthRequired,
            'health_gap' => $healthRequired === null ? null : max(0, $healthRequired - $health),
            'legacy_ceiling' => $globalCeiling !== null && ! $seats,
        ];
    }

    /** @return array<string, string> */
    public function submissionErrors(LegislativeProposal $proposal, User $user): array
    {
        $proposal->loadMissing(['municipality', 'regulatoryProfile']);
        $profile = $proposal->regulatoryProfile;
        $errors = [];

        if ($profile === null) {
            $errors['profile'] = 'O Município ainda não ativou a configuração normativa deste exercício.';
        } elseif ($profile->regime_status !== MunicipalRegulatoryProfile::REGIME_INSTITUTED) {
            $errors['profile'] = 'A configuração vigente não reconhece o regime impositivo como instituído na Lei Orgânica.';
        }

        $membership = $user->municipalities()->whereKey($proposal->municipality_id)->first()?->pivot;
        if (! $membership || blank($membership->legislative_name) || blank($membership->legislative_party)) {
            $errors['author'] = 'O cadastro institucional do vereador precisa conter nome e partido antes do envio.';
        }

        if ($profile) {
            $quota = $this->quota($proposal->municipality, $profile, $proposal->author_name, $proposal);
            if ($profile->minimum_amendment_amount !== null
                && (float) $proposal->estimated_amount < (float) $profile->minimum_amendment_amount) {
                $errors['estimated_amount'] = 'O valor está abaixo do mínimo municipal de R$ '
                    .number_format((float) $profile->minimum_amendment_amount, 2, ',', '.').'.';
            }
            if ($quota['count_limit'] !== null && $quota['count'] > $quota['count_limit']) {
                $errors['count'] = "A quantidade projetada supera o limite de {$quota['count_limit']} propostas por vereador.";
            }
            if ($quota['author_ceiling'] !== null && $quota['used'] > $quota['author_ceiling'] + 0.005) {
                $errors['estimated_amount'] = 'A carteira chegaria a R$ '.number_format($quota['used'], 2, ',', '.')
                    .', acima da cota de R$ '.number_format($quota['author_ceiling'], 2, ',', '.').'.';
            }
        }

        if ($proposal->beneficiary_type === 'third_sector' && ! $proposal->third_sector_conflict_declaration) {
            $errors['third_sector_conflict_declaration'] = 'A declaração preliminar de inexistência de conflito é obrigatória para entidade do Terceiro Setor.';
        }

        return $errors;
    }

    /** @return array<int, string> */
    public function reviewBlockers(LegislativeProposal $proposal): array
    {
        $blockers = [];
        foreach ($this->reviewChecklist() as $field => $item) {
            if ($proposal->{$field} !== true) {
                $blockers[] = $item['label'];
            }
        }

        return $blockers;
    }

    /** @return array<int, string> */
    public function protocolBlockers(LegislativeProposal $proposal): array
    {
        $proposal->loadMissing(['municipality', 'regulatoryProfile']);
        $blockers = [];
        if ($proposal->status !== LegislativeProposal::STATUS_APPROVED) {
            $blockers[] = 'A conferência legislativa ainda não liberou a indicação para protocolo.';
        }
        if (blank($proposal->protocol_number)) {
            $blockers[] = 'Informe o protocolo ou referência de encaminhamento da Câmara.';
        }
        $quota = $this->quota($proposal->municipality, $proposal->regulatoryProfile, $proposal->author_name, $proposal);
        if (($quota['health_gap'] ?? 0) > 0.005) {
            $blockers[] = 'A carteira do vereador ainda possui déficit de R$ '
                .number_format((float) $quota['health_gap'], 2, ',', '.').' na reserva de saúde.';
        }

        return $blockers;
    }

    /** @return array<string, mixed> */
    public function protocolSnapshot(LegislativeProposal $proposal): array
    {
        $proposal->loadMissing(['municipality', 'regulatoryProfile', 'submitter', 'reviewer']);

        return [
            'proposal' => $proposal->only([
                'reference', 'fiscal_year', 'author_name', 'author_party', 'object', 'justification',
                'priority', 'beneficiary_type', 'beneficiary_name', 'beneficiary_cnpj',
                'beneficiary_location', 'expense_destination', 'transfer_type', 'health_related',
                'responsible_department', 'program_reference', 'action_reference', 'public_need',
                'target_population', 'estimated_quantity', 'estimated_amount', 'estimate_source',
                'desired_contract_at', 'third_sector_conflict_declaration', 'protocol_number',
            ]),
            'municipality' => ['id' => $proposal->municipality_id, 'name' => $proposal->municipality->name, 'state' => $proposal->municipality->state],
            'regulatory_profile' => $proposal->regulatoryProfile?->only([
                'id', 'fiscal_year', 'version', 'previous_year_rcl', 'individual_limit_percentage',
                'councilor_seats', 'health_reserve_percentage', 'health_reserve_method',
            ]),
            'review' => collect(array_keys($this->reviewChecklist()))
                ->mapWithKeys(fn (string $field) => [$field => $proposal->{$field}])
                ->merge(['reviewer' => $proposal->reviewer?->name, 'notes' => $proposal->review_notes, 'reviewed_at' => $proposal->reviewed_at?->toIso8601String()])
                ->all(),
            'submitted_by' => $proposal->submitter->only(['id', 'name', 'email']),
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
