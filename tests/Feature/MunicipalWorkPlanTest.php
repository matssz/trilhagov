<?php

namespace Tests\Feature;

use App\Models\MunicipalAdmissibilityReview;
use App\Models\Municipality;
use App\Models\MunicipalWorkPlan;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\MunicipalWorkItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MunicipalWorkPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_start_and_complete_structured_work_plan(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $this->createPlan($manager, $municipality, $amendment);
        $plan = $amendment->municipalWorkPlan()->firstOrFail();

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('emendas.work-plan.update', $amendment), $this->payloadWithToken(
                "municipal-work-plan-update-{$plan->id}",
                $this->planPayload(),
            ))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Plano de trabalho salvo.');

        $this->assertDatabaseHas('municipal_work_plans', [
            'id' => $plan->id,
            'beneficiary_type' => 'municipal_body',
            'budget_program' => 'Saúde para Todos',
            'pca_status' => 'included',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ParliamentaryAmendment::class,
            'auditable_id' => $amendment->id,
            'action' => 'municipal_work_plan_updated',
        ]);
    }

    public function test_submission_requires_complete_schedule_matching_amendment_amount(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $plan = $this->readyPlan($manager, $municipality, $amendment, stageAmount: 90000);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->from(route('emendas.work-plan', $amendment))
            ->post(route('emendas.work-plan.submit', $amendment), $this->payloadWithToken(
                "municipal-work-plan-submit-{$plan->id}",
            ))
            ->assertSessionHasErrors('work_plan');

        $this->assertSame(MunicipalWorkPlan::STATUS_DRAFT, $plan->fresh()->status);

        $stage = $plan->stages()->firstOrFail();
        $stage->update(['planned_amount' => 100000]);

        $this->post(route('emendas.work-plan.submit', $amendment), $this->payloadWithToken(
            "municipal-work-plan-submit-{$plan->id}",
        ))->assertSessionHas('status', 'Plano enviado para parecer de admissibilidade.');

        $plan->refresh();
        $this->assertSame(MunicipalWorkPlan::STATUS_UNDER_REVIEW, $plan->status);
        $this->assertSame(1, $plan->revision_number);
        $this->assertNotNull($plan->submitted_at);
    }

    public function test_adjustment_and_approval_preserve_each_review_snapshot(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $plan = $this->readyPlan($manager, $municipality, $amendment);
        $this->submitPlan($manager, $municipality, $amendment, $plan);

        $adjustmentCriteria = $this->criteria('met');
        $adjustmentCriteria['budget'] = 'not_met';
        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.work-plan.review', $amendment), $this->payloadWithToken(
                "municipal-admissibility-review-{$plan->id}-1",
                [
                    'conclusion' => MunicipalAdmissibilityReview::CONCLUSION_ADJUSTMENTS,
                    'criteria' => $adjustmentCriteria,
                    'rationale' => 'A ação orçamentária informada não comporta integralmente o objeto.',
                    'corrections_requested' => 'Corrigir a ação orçamentária e justificar o enquadramento.',
                ],
            ))
            ->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame(MunicipalWorkPlan::STATUS_ADJUSTMENTS_REQUESTED, $plan->status);
        $firstReview = $plan->reviews()->firstOrFail();
        $this->assertSame('Saúde para Todos', $firstReview->plan_snapshot['plan']['budget_program']);

        $updatedPayload = $this->planPayload([
            'budget_program' => 'Atenção Básica Municipal',
            'budget_action' => 'Aquisição de equipamentos para UBS',
        ]);
        $this->patch(route('emendas.work-plan.update', $amendment), $this->payloadWithToken(
            "municipal-work-plan-update-{$plan->id}",
            $updatedPayload,
        ))->assertSessionHasNoErrors();
        $this->submitPlan($manager, $municipality, $amendment, $plan->fresh());

        $plan->refresh();
        $this->assertSame(2, $plan->revision_number);

        $this->post(route('emendas.work-plan.review', $amendment), $this->payloadWithToken(
            "municipal-admissibility-review-{$plan->id}-2",
            [
                'conclusion' => MunicipalAdmissibilityReview::CONCLUSION_APPROVED,
                'criteria' => $this->criteria('met'),
                'rationale' => 'Plano compatível com o orçamento e tecnicamente admissível.',
            ],
        ))->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame(MunicipalWorkPlan::STATUS_APPROVED, $plan->status);
        $this->assertNotNull($plan->approved_at);
        $this->assertSame(2, $plan->reviews()->count());
        $this->assertSame('Saúde para Todos', $firstReview->fresh()->plan_snapshot['plan']['budget_program']);
        $this->assertSame('Atenção Básica Municipal', $plan->reviews()->where('plan_revision', 2)->firstOrFail()->plan_snapshot['plan']['budget_program']);
    }

    public function test_invalid_admissibility_conclusions_are_rejected(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $plan = $this->readyPlan($manager, $municipality, $amendment);
        $this->submitPlan($manager, $municipality, $amendment, $plan);
        $criteria = $this->criteria('met');
        $criteria['viability'] = 'not_met';

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->from(route('emendas.work-plan', $amendment))
            ->post(route('emendas.work-plan.review', $amendment), $this->payloadWithToken(
                "municipal-admissibility-review-{$plan->id}-1",
                [
                    'conclusion' => MunicipalAdmissibilityReview::CONCLUSION_APPROVED,
                    'criteria' => $criteria,
                    'rationale' => 'Tentativa inconsistente.',
                ],
            ))
            ->assertSessionHasErrors([
                'conclusion' => 'Um plano com critério não atendido não pode ser aprovado.',
            ]);

        $this->assertDatabaseCount('municipal_admissibility_reviews', 0);
    }

    public function test_submitted_plan_is_locked_and_only_manager_can_issue_review(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $editor = User::factory()->create();
        $municipality->users()->attach($editor, ['role' => User::ROLE_EDITOR]);
        $plan = $this->readyPlan($manager, $municipality, $amendment);
        $this->submitPlan($manager, $municipality, $amendment, $plan);

        $this->actingAs($editor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('emendas.work-plan.update', $amendment), $this->planPayload())
            ->assertStatus(409);

        $this->post(route('emendas.work-plan.review', $amendment), [])->assertForbidden();
    }

    public function test_viewer_can_consult_and_download_pdf_but_cannot_modify_plan(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $viewer = User::factory()->create();
        $municipality->users()->attach($viewer, ['role' => User::ROLE_VIEWER]);
        $this->readyPlan($manager, $municipality, $amendment);

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.work-plan', $amendment))
            ->assertOk()
            ->assertSee('Plano de trabalho')
            ->assertDontSee('Salvar plano');

        $this->get(route('emendas.work-plan.pdf', $amendment))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->post(route('emendas.work-plan.store', $amendment), [])->assertForbidden();
    }

    public function test_work_plan_is_scoped_to_tcesp_municipality_and_active_tenant(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        [$otherManager, , $otherAmendment] = $this->context();

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.work-plan', $otherAmendment))
            ->assertNotFound();

        $amendment->update(['government_sphere' => 'federal']);
        $this->get(route('emendas.work-plan', $amendment))->assertNotFound();
        $this->assertNotSame($manager->id, $otherManager->id);
    }

    public function test_repeated_submission_and_review_do_not_duplicate_records(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $plan = $this->readyPlan($manager, $municipality, $amendment);
        $submitToken = $this->token("municipal-work-plan-submit-{$plan->id}");

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.work-plan.submit', $amendment), ['_submission_token' => $submitToken]);
        $this->post(route('emendas.work-plan.submit', $amendment), ['_submission_token' => $submitToken])
            ->assertStatus(409);

        $reviewToken = $this->token("municipal-admissibility-review-{$plan->id}-1");
        $payload = [
            '_submission_token' => $reviewToken,
            'conclusion' => MunicipalAdmissibilityReview::CONCLUSION_APPROVED,
            'criteria' => $this->criteria('met'),
            'rationale' => 'Plano admissível.',
        ];
        $this->post(route('emendas.work-plan.review', $amendment), $payload);
        $this->post(route('emendas.work-plan.review', $amendment), $payload)->assertStatus(409);

        $this->assertDatabaseCount('municipal_admissibility_reviews', 1);
        $this->assertSame(1, $plan->fresh()->revision_number);
    }

    public function test_issued_review_is_immutable(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $plan = $this->readyPlan($manager, $municipality, $amendment);
        $this->submitPlan($manager, $municipality, $amendment, $plan);
        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.work-plan.review', $amendment), $this->payloadWithToken(
                "municipal-admissibility-review-{$plan->id}-1",
                [
                    'conclusion' => MunicipalAdmissibilityReview::CONCLUSION_APPROVED,
                    'criteria' => $this->criteria('met'),
                    'rationale' => 'Plano admissível.',
                ],
            ));

        $this->expectException(\LogicException::class);
        MunicipalAdmissibilityReview::firstOrFail()->update(['rationale' => 'Adulterado']);
    }

    public function test_work_center_tracks_plan_from_creation_through_review(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $service = app(MunicipalWorkItemService::class);
        $service->synchronize($municipality);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:municipal-work-plan:create",
            'category' => 'planning',
        ]);

        $plan = $this->readyPlan($manager, $municipality, $amendment);
        $service->synchronize($municipality);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:municipal-work-plan:prepare",
            'status' => 'pending',
        ]);

        $this->submitPlan($manager, $municipality, $amendment, $plan);
        $service->synchronize($municipality);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:municipal-work-plan:review:1",
            'status' => 'pending',
        ]);
    }

    /** @return array{User, Municipality, ParliamentaryAmendment} */
    private function context(): array
    {
        $manager = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP']);
        $municipality->users()->attach($manager, ['role' => User::ROLE_MANAGER]);
        $amendment = ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($manager, 'creator')
            ->create([
                'government_sphere' => 'municipal',
                'transfer_type' => 'direct_execution',
                'transferegov_code' => null,
                'expected_amount' => 100000,
                'responsible_department' => 'Secretaria Municipal de Saúde',
                'indicated_at' => '2026-07-01',
                'execution_deadline' => '2026-12-31',
            ]);

        return [$manager, $municipality, $amendment];
    }

    private function createPlan(User $user, Municipality $municipality, ParliamentaryAmendment $amendment): void
    {
        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.work-plan.store', $amendment), $this->payloadWithToken(
                "municipal-work-plan-create-{$amendment->id}",
            ))
            ->assertSessionHasNoErrors();
    }

    private function readyPlan(User $user, Municipality $municipality, ParliamentaryAmendment $amendment, float $stageAmount = 100000): MunicipalWorkPlan
    {
        $this->createPlan($user, $municipality, $amendment);
        $plan = $amendment->municipalWorkPlan()->firstOrFail();
        $this->patch(route('emendas.work-plan.update', $amendment), $this->payloadWithToken(
            "municipal-work-plan-update-{$plan->id}",
            $this->planPayload(),
        ))->assertSessionHasNoErrors();
        $this->post(route('emendas.work-plan.stages.store', $amendment), $this->payloadWithToken(
            "municipal-work-plan-stage-create-{$plan->id}",
            [
                'title' => 'Aquisição e instalação',
                'physical_delivery' => '10 equipamentos entregues e instalados',
                'planned_amount' => $stageAmount,
                'planned_start_at' => '2026-08-01',
                'planned_end_at' => '2026-11-30',
                'sort_order' => 10,
            ],
        ))->assertSessionHasNoErrors();

        return $plan->fresh(['stages']);
    }

    private function submitPlan(User $user, Municipality $municipality, ParliamentaryAmendment $amendment, MunicipalWorkPlan $plan): void
    {
        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.work-plan.submit', $amendment), $this->payloadWithToken(
                "municipal-work-plan-submit-{$plan->id}",
            ))
            ->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function planPayload(array $overrides = []): array
    {
        return array_merge([
            'beneficiary_type' => 'municipal_body',
            'beneficiary_name' => 'Secretaria Municipal de Saúde',
            'beneficiary_cnpj' => null,
            'beneficiary_contact' => 'saude@municipio.sp.gov.br',
            'object_description' => 'Aquisição e instalação de dez equipamentos para unidades básicas de saúde.',
            'public_need' => 'Reduzir a fila municipal de exames e ampliar a capacidade de atendimento.',
            'physical_target' => 'Entregar e instalar dez equipamentos até novembro de 2026.',
            'finalistic_target' => 'Ampliar em vinte por cento a capacidade mensal de exames.',
            'budget_program' => 'Saúde para Todos',
            'budget_action' => 'Estruturação da atenção básica',
            'application_plan' => 'Aquisição, transporte, instalação e treinamento operacional.',
            'cost_memory' => '10 equipamentos x R$ 9.000,00 + instalação e treinamento de R$ 10.000,00.',
            'maintenance_plan' => 'Manutenção preventiva custeada pela dotação anual da Secretaria de Saúde.',
            'health_related' => '1',
            'health_reserve_verified' => '1',
            'includes_engineering' => '0',
            'engineering_project_status' => 'not_applicable',
            'environmental_license_status' => 'not_applicable',
            'pca_status' => 'included',
            'planned_start_at' => '2026-08-01',
            'planned_end_at' => '2026-12-15',
        ], $overrides);
    }

    /** @return array<string, string> */
    private function criteria(string $status): array
    {
        return array_fill_keys(['normative', 'budget', 'viability', 'schedule', 'beneficiary', 'health', 'pca'], $status);
    }

    /** @return array<string, mixed> */
    private function payloadWithToken(string $scope, array $payload = []): array
    {
        $token = $this->token($scope);

        return ['_submission_token' => $token, ...$payload];
    }

    private function token(string $scope): string
    {
        $token = (string) Str::uuid();
        $tokens = session('form_submission_tokens', []);
        $tokens[$scope][$token] = now()->timestamp;
        $this->withSession(['form_submission_tokens' => $tokens]);

        return $token;
    }
}
