<?php

namespace Tests\Feature;

use App\Models\HealthAspsAssessment;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class HealthAspsTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_assessment_can_be_issued_as_eligible_and_exported(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $amendment = $this->healthAmendment($municipality, $manager);
        $this->actingAs($manager)->post(route('health-asps.save', $amendment), [
            ...$this->completePayload(),
            '_submission_token' => $this->token($municipality, "health-asps-save-{$amendment->id}"),
        ])->assertSessionHas('status');
        $assessment = HealthAspsAssessment::firstOrFail();

        $this->post(route('health-asps.submit', $assessment), [
            '_submission_token' => $this->token($municipality, "health-asps-submit-{$assessment->id}"),
        ])->assertSessionHas('status');
        $this->post(route('health-asps.decision', $assessment), [
            '_submission_token' => $this->token($municipality, "health-asps-decision-{$assessment->id}"),
            'action' => 'eligible',
            'reviewer_notes' => 'Critérios, fonte e vinculação ao Fundo Municipal de Saúde conferidos.',
        ])->assertSessionHas('status');

        $assessment->refresh();
        $this->assertSame(HealthAspsAssessment::STATUS_ISSUED, $assessment->status);
        $this->assertSame(HealthAspsAssessment::CONCLUSION_ELIGIBLE, $assessment->conclusion);
        $this->assertSame(64, strlen($assessment->snapshot_sha256));
        $this->assertDatabaseMissing('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => 'health-asps:assessment-pending',
            'status' => 'open',
        ]);
        $this->get(route('health-asps.pdf', $assessment))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('health-asps.show', $amendment))->assertOk()->assertSee('Computável como ASPS')->assertSee($assessment->code());
    }

    public function test_eligible_decision_is_blocked_when_mandatory_criteria_are_missing(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $amendment = $this->healthAmendment($municipality, $manager);
        $payload = $this->completePayload();
        unset($payload['criteria']['health_plan_alignment']);
        $this->actingAs($manager)->post(route('health-asps.save', $amendment), [
            ...$payload, '_submission_token' => $this->token($municipality, "health-asps-save-{$amendment->id}"),
        ]);
        $assessment = HealthAspsAssessment::firstOrFail();

        $this->post(route('health-asps.submit', $assessment), [
            '_submission_token' => $this->token($municipality, "health-asps-submit-{$assessment->id}"),
        ])->assertSessionHasErrors('assessment');
        $this->assertSame(HealthAspsAssessment::STATUS_DRAFT, $assessment->fresh()->status);
    }

    public function test_ineligible_assessment_creates_critical_alert_and_work_action(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $amendment = $this->healthAmendment($municipality, $manager);
        $payload = $this->completePayload();
        $payload['exclusion_reasons'] = ['social_assistance'];
        $this->actingAs($manager)->post(route('health-asps.save', $amendment), [
            ...$payload, '_submission_token' => $this->token($municipality, "health-asps-save-{$amendment->id}"),
        ]);
        $assessment = HealthAspsAssessment::firstOrFail();
        $this->post(route('health-asps.submit', $assessment), [
            '_submission_token' => $this->token($municipality, "health-asps-submit-{$assessment->id}"),
        ])->assertSessionHas('status');
        $this->post(route('health-asps.decision', $assessment), [
            '_submission_token' => $this->token($municipality, "health-asps-decision-{$assessment->id}"),
            'action' => 'ineligible',
            'reviewer_notes' => 'O objeto pertence à assistência social e não atende aos critérios de ASPS.',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => 'health-asps:ineligible',
            'severity' => 'critical',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'parliamentary_amendment_id' => $amendment->id,
            'category' => 'health',
            'priority' => 'critical',
        ]);
    }

    public function test_issued_assessment_is_immutable_and_revision_preserves_history(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $amendment = $this->healthAmendment($municipality, $manager);
        $assessment = $this->issuedAssessment($manager, $municipality, $amendment);
        $this->post(route('health-asps.revise', $assessment), [
            '_submission_token' => $this->token($municipality, "health-asps-revise-{$assessment->id}"),
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('health_asps_assessments', [
            'parliamentary_amendment_id' => $amendment->id,
            'version' => 2,
            'status' => HealthAspsAssessment::STATUS_DRAFT,
            'supersedes_id' => $assessment->id,
        ]);
        $this->expectException(LogicException::class);
        $assessment->fresh()->update(['technical_justification' => 'Tentativa posterior.']);
    }

    public function test_roles_and_active_municipality_are_enforced(): void
    {
        [$editor, $municipality] = $this->member(User::ROLE_EDITOR);
        $amendment = $this->healthAmendment($municipality, $editor);
        $this->actingAs($editor)->post(route('health-asps.save', $amendment), [
            ...$this->completePayload(),
            '_submission_token' => $this->token($municipality, "health-asps-save-{$amendment->id}"),
        ]);
        $assessment = HealthAspsAssessment::firstOrFail();
        $this->post(route('health-asps.decision', $assessment))->assertForbidden();

        [$viewer, $otherMunicipality] = $this->member(User::ROLE_VIEWER);
        $this->actingAs($viewer)->withSession(['active_municipality_id' => $otherMunicipality->id]);
        $this->get(route('health-asps.index'))->assertOk();
        $this->get(route('health-asps.show', $amendment))->assertNotFound();
        $this->post(route('health-asps.save', $amendment))->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function completePayload(): array
    {
        return [
            'asps_category' => 'health_inputs',
            'budget_function' => '10',
            'budget_subfunction' => '301',
            'funding_source_code' => '01 - Tesouro Municipal',
            'application_code' => '8001',
            'health_fund_reference' => 'Fundo Municipal de Saúde - CNPJ próprio',
            'health_plan_reference' => 'Diretriz 2, Meta 2.3 do Plano Municipal de Saúde 2026-2029',
            'technical_justification' => 'Aquisição de equipamentos destinados ao atendimento universal nas unidades públicas do SUS municipal.',
            'criteria' => [
                'universal_free_access' => '1',
                'health_plan_alignment' => '1',
                'health_sector_responsibility' => '1',
                'health_fund_financing' => '1',
                'sus_scope' => '1',
            ],
        ];
    }

    private function issuedAssessment(User $manager, Municipality $municipality, ParliamentaryAmendment $amendment): HealthAspsAssessment
    {
        $this->actingAs($manager)->post(route('health-asps.save', $amendment), [
            ...$this->completePayload(),
            '_submission_token' => $this->token($municipality, "health-asps-save-{$amendment->id}"),
        ]);
        $assessment = HealthAspsAssessment::firstOrFail();
        $this->post(route('health-asps.submit', $assessment), [
            '_submission_token' => $this->token($municipality, "health-asps-submit-{$assessment->id}"),
        ]);
        $this->post(route('health-asps.decision', $assessment), [
            '_submission_token' => $this->token($municipality, "health-asps-decision-{$assessment->id}"),
            'action' => 'eligible',
            'reviewer_notes' => 'Classificação conferida e aprovada pelo responsável municipal competente.',
        ]);

        return $assessment->fresh();
    }

    private function healthAmendment(Municipality $municipality, User $user): ParliamentaryAmendment
    {
        $amendment = ParliamentaryAmendment::factory()->create([
            'municipality_id' => $municipality->id, 'created_by' => $user->id,
            'reference' => 'EM-SAÚDE-2026-01', 'fiscal_year' => 2026,
            'government_sphere' => 'municipal', 'authorship_type' => 'individual',
            'author_name' => 'Vereadora Helena Lima', 'object' => 'Aquisição de equipamentos para unidades básicas de saúde',
            'responsible_department' => 'Secretaria Municipal de Saúde', 'expected_amount' => 180000,
            'received_amount' => 150000, 'received_at' => '2026-06-10', 'status' => ParliamentaryAmendment::STATUS_EXECUTING,
            'funding_source_code' => '01', 'application_code_fixed' => '800', 'application_code_variable' => '1',
        ]);
        $amendment->municipalWorkPlan()->create([
            'municipality_id' => $municipality->id, 'created_by' => $user->id, 'updated_by' => $user->id,
            'status' => 'approved', 'revision_number' => 1, 'health_related' => true,
            'health_reserve_verified' => true, 'approved_at' => now(),
        ]);

        return $amendment;
    }

    private function member(string $role): array
    {
        $user = User::factory()->create();
        $sequence = Municipality::count() + 1;
        $municipality = Municipality::factory()->create([
            'state' => 'SP', 'ibge_code' => (string) (3542000 + $sequence),
            'cnpj' => sprintf('32345678%06d', $sequence), 'transparency_enabled' => true,
        ]);
        $municipality->users()->attach($user->id, ['role' => $role]);

        return [$user, $municipality];
    }

    private function token(Municipality $municipality, string $scope): string
    {
        $token = (string) Str::uuid();
        $tokens = session('form_submission_tokens', []);
        $tokens[$scope] = [$token => now()->timestamp];
        $this->withSession(['active_municipality_id' => $municipality->id, 'form_submission_tokens' => $tokens]);

        return $token;
    }
}
