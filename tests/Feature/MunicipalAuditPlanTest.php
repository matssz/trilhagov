<?php

namespace Tests\Feature;

use App\Models\MunicipalAuditPlan;
use App\Models\MunicipalAuditPlanItem;
use App\Models\MunicipalInternalControlReview;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\IntegrityAlertService;
use App\Services\MunicipalInternalControlService;
use App\Services\MunicipalWorkItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class MunicipalAuditPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_creates_versioned_draft_with_risk_recommendations_and_idempotency(): void
    {
        [$manager, , $municipality, $amendment] = $this->context();
        $token = $this->token($municipality, 'municipal-audit-plan-create');
        $payload = ['_submission_token' => $token, ...$this->planPayload()];

        $response = $this->actingAs($manager)->post(route('audit-plans.store'), $payload);

        $plan = MunicipalAuditPlan::firstOrFail();
        $response->assertRedirect(route('audit-plans.show', $plan));
        $this->assertSame('PAA-2026-V1', $plan->reference());
        $this->assertSame(MunicipalAuditPlan::STATUS_DRAFT, $plan->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'municipal_audit_plan_created']);

        $this->get(route('audit-plans.show', $plan))
            ->assertOk()
            ->assertSee('Seleção orientada')
            ->assertSee($amendment->reference);

        $this->post(route('audit-plans.store'), $payload)->assertSessionHas('warning');
        $this->assertDatabaseCount('municipal_audit_plans', 1);
    }

    public function test_auditor_builds_agenda_issues_snapshot_pdf_and_plan_becomes_immutable(): void
    {
        [$manager, $auditor, $municipality, $amendment] = $this->context();
        $plan = $this->draftPlan($municipality, $manager);
        $this->actingAs($auditor)->withSession(['active_municipality_id' => $municipality->id]);

        $this->post(route('audit-plan-items.store', $plan), [
            '_submission_token' => $this->token($municipality, "municipal-audit-plan-item-create-{$plan->id}"),
            ...$this->itemPayload($amendment, $auditor),
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $item = MunicipalAuditPlanItem::firstOrFail();
        $this->patch(route('audit-plan-items.update', $item), [
            '_submission_token' => $this->token($municipality, "municipal-audit-plan-item-update-{$item->id}"),
            ...$this->itemPayload($amendment, $manager, ['priority' => 'critical']),
        ])->assertSessionHasNoErrors();
        $this->assertSame('critical', $item->fresh()->priority);

        $this->post(route('audit-plans.issue', $plan), [
            '_submission_token' => $this->token($municipality, "municipal-audit-plan-issue-{$plan->id}"),
            'confirm_plan' => 1,
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $plan->refresh();
        $this->assertSame(MunicipalAuditPlan::STATUS_ISSUED, $plan->status);
        $this->assertSame(64, strlen($plan->snapshot_sha256));
        $this->assertSame($amendment->reference, $plan->snapshot['items'][0]['amendment']['reference']);
        $this->get(route('audit-plans.pdf', $plan))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->expectException(LogicException::class);
        $plan->update(['title' => 'Tentativa posterior à emissão']);
    }

    public function test_issued_item_feeds_work_center_alerts_and_preserves_progress_events(): void
    {
        [$manager, $auditor, $municipality, $amendment] = $this->context();
        $plan = $this->issuedPlan($municipality, $manager);
        $item = $this->planItem($plan, $amendment, $auditor, ['planned_at' => today()]);

        app(MunicipalWorkItemService::class)->synchronize($municipality);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:audit-plan-item:{$item->id}",
            'category' => 'control',
            'responsible_user_id' => $auditor->id,
        ]);

        app(IntegrityAlertService::class)->sync($municipality);
        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => "deadline:audit-plan:{$item->id}",
            'assigned_user_id' => $auditor->id,
            'status' => 'open',
        ]);

        $this->actingAs($auditor)->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('audit-plan-items.progress', $item), [
                '_submission_token' => $this->token($municipality, "municipal-audit-plan-item-progress-{$item->id}"),
                'status' => MunicipalAuditPlanItem::STATUS_RESCHEDULED,
                'status_notes' => 'Reprogramada para compatibilizar a agenda da equipe de Controle Interno.',
                'planned_at' => today()->addDays(5)->format('Y-m-d'),
            ])->assertSessionHasNoErrors();

        $this->assertSame(MunicipalAuditPlanItem::STATUS_RESCHEDULED, $item->fresh()->status);
        $this->assertDatabaseHas('municipal_audit_plan_item_events', [
            'municipal_audit_plan_item_id' => $item->id,
            'event_type' => MunicipalAuditPlanItem::STATUS_RESCHEDULED,
            'from_status' => MunicipalAuditPlanItem::STATUS_PLANNED,
        ]);
    }

    public function test_linked_internal_control_review_completes_the_correct_plan_item(): void
    {
        [$manager, $auditor, $municipality, $amendment] = $this->context();
        $plan = $this->issuedPlan($municipality, $manager);
        $item = $this->planItem($plan, $amendment, $auditor);
        app(MunicipalWorkItemService::class)->synchronize($municipality);

        $this->actingAs($auditor)->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('internal-control-reviews.store', $amendment), [
                '_submission_token' => $this->token($municipality, "internal-control-review-create-{$amendment->id}"),
                'municipal_audit_plan_item_id' => $item->id,
                ...$this->reviewPayload(),
            ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $review = MunicipalInternalControlReview::firstOrFail();
        $this->assertSame($item->id, $review->municipal_audit_plan_item_id);
        $this->assertSame($item->formalReference(), $review->annual_audit_plan_reference);
        $this->assertSame(MunicipalAuditPlanItem::STATUS_COMPLETED, $item->fresh()->status);
        $this->assertSame($auditor->id, $item->fresh()->completed_by);
        $this->assertDatabaseHas('municipal_audit_plan_item_events', [
            'municipal_audit_plan_item_id' => $item->id,
            'event_type' => 'completed',
            'to_status' => MunicipalAuditPlanItem::STATUS_COMPLETED,
        ]);

        app(MunicipalWorkItemService::class)->synchronize($municipality);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:audit-plan-item:{$item->id}",
            'status' => 'completed',
        ]);
    }

    public function test_plan_item_must_match_review_phase_and_municipality_access_is_isolated(): void
    {
        [$manager, $auditor, $municipality, $amendment] = $this->context();
        $plan = $this->issuedPlan($municipality, $manager);
        $item = $this->planItem($plan, $amendment, $auditor, ['phase' => 'prior']);

        $this->actingAs($auditor)->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('internal-control-reviews.store', $amendment), [
                '_submission_token' => $this->token($municipality, "internal-control-review-create-{$amendment->id}"),
                'municipal_audit_plan_item_id' => $item->id,
                ...$this->reviewPayload(['phase' => 'concomitant']),
            ])->assertSessionHasErrors('municipal_audit_plan_item_id');
        $this->assertDatabaseCount('municipal_internal_control_reviews', 0);

        $viewer = User::factory()->create();
        $municipality->users()->attach($viewer, ['role' => User::ROLE_VIEWER]);
        $this->actingAs($viewer)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('audit-plans.show', $plan))->assertOk();
        $this->post(route('audit-plans.store'))->assertForbidden();

        [, , $otherMunicipality] = $this->context();
        $this->withSession(['active_municipality_id' => $municipality->id]);
        $otherPlan = $this->draftPlan($otherMunicipality, User::factory()->create());
        $this->get(route('audit-plans.show', $otherPlan))->assertNotFound();
    }

    public function test_module_is_hidden_outside_supported_municipal_scope(): void
    {
        [$manager, , $municipality] = $this->context();
        $municipality->update(['state' => 'MG']);

        $this->actingAs($manager)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('audit-plans.index'))->assertNotFound();
    }

    /** @return array{User, User, Municipality, ParliamentaryAmendment} */
    private function context(): array
    {
        $manager = User::factory()->create();
        $auditor = User::factory()->create();
        $municipality = Municipality::factory()->create([
            'state' => 'SP',
            'ibge_code' => fake()->unique()->numerify('354####'),
            'cnpj' => fake()->unique()->numerify('##############'),
        ]);
        $municipality->users()->attach($manager, ['role' => User::ROLE_MANAGER]);
        $municipality->users()->attach($auditor, ['role' => User::ROLE_AUDITOR]);
        $amendment = ParliamentaryAmendment::factory()->for($municipality)->for($manager, 'creator')->create([
            'fiscal_year' => 2026,
            'government_sphere' => 'municipal',
            'transfer_type' => 'direct_execution',
            'transferegov_code' => null,
            'expected_amount' => 1250000,
            'responsible_user_id' => $manager->id,
            'administrative_process' => 'PA-2026-0091',
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
        ]);

        return [$manager, $auditor, $municipality, $amendment];
    }

    private function draftPlan(Municipality $municipality, User $creator): MunicipalAuditPlan
    {
        return $municipality->auditPlans()->create([
            ...$this->planPayload(),
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'version' => 1,
            'status' => MunicipalAuditPlan::STATUS_DRAFT,
        ]);
    }

    private function issuedPlan(Municipality $municipality, User $creator): MunicipalAuditPlan
    {
        $plan = $this->draftPlan($municipality, $creator);
        $plan->update([
            'status' => MunicipalAuditPlan::STATUS_ISSUED,
            'issued_by' => $creator->id,
            'issued_at' => now(),
            'snapshot' => ['reference' => $plan->reference()],
            'snapshot_sha256' => hash('sha256', $plan->reference()),
        ]);

        return $plan->fresh();
    }

    private function planItem(MunicipalAuditPlan $plan, ParliamentaryAmendment $amendment, User $auditor, array $overrides = []): MunicipalAuditPlanItem
    {
        return $plan->items()->create(array_merge([
            'municipality_id' => $plan->municipality_id,
            'parliamentary_amendment_id' => $amendment->id,
            'assigned_user_id' => $auditor->id,
            'created_by' => $auditor->id,
            'phase' => 'concomitant',
            'priority' => 'high',
            'frequency' => 'milestones',
            'status' => MunicipalAuditPlanItem::STATUS_PLANNED,
            'planned_at' => today()->addDay(),
            'scope_notes' => 'Verificar a regularidade da execução e as evidências do processo municipal.',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function planPayload(): array
    {
        return [
            'fiscal_year' => 2026,
            'title' => 'Plano Anual de Auditoria das Emendas Municipais',
            'objective' => 'Avaliar preventivamente e de forma concomitante a regularidade das emendas municipais.',
            'methodology' => 'Aplicar verificações por risco, materialidade e criticidade nas fases prévia, concomitante e final.',
            'risk_criteria' => 'Priorizar ausência de parecer, alertas ativos, materialidade e execução financeira iniciada.',
            'normative_basis' => 'Comunicado GP 15/2026 e Manual TCESP, item 7.3.',
            'coordination_unit' => 'Unidade Central de Controle Interno',
            'planned_start_at' => '2026-01-01',
            'planned_end_at' => '2026-12-31',
            'management_notes' => 'Plano construído para a realidade operacional municipal.',
        ];
    }

    /** @return array<string, mixed> */
    private function itemPayload(ParliamentaryAmendment $amendment, User $auditor, array $overrides = []): array
    {
        return array_merge([
            'parliamentary_amendment_id' => $amendment->id,
            'assigned_user_id' => $auditor->id,
            'phase' => 'concomitant',
            'priority' => 'high',
            'frequency' => 'milestones',
            'planned_at' => today()->addDay()->format('Y-m-d'),
            'scope_notes' => 'Verificar a regularidade da execução e as evidências do processo municipal.',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function reviewPayload(array $overrides = []): array
    {
        $criteria = collect(app(MunicipalInternalControlService::class)->criteria())
            ->mapWithKeys(fn ($definition, $code) => [$code => ['status' => 'compliant', 'notes' => null]])
            ->all();

        return array_merge([
            'phase' => MunicipalInternalControlReview::PHASE_CONCOMITANT,
            'conclusion' => MunicipalInternalControlReview::CONCLUSION_REGULAR,
            'criteria' => $criteria,
            'summary' => 'A verificação padronizada não identificou impropriedades na data desta análise.',
            'legal_basis' => 'Comunicado GP 15/2026 e Manual TCESP, item 7.3.',
        ], $overrides);
    }

    private function token(Municipality $municipality, string $scope): string
    {
        $token = (string) Str::uuid();
        $tokens = session('form_submission_tokens', []);
        $tokens[$scope][$token] = now()->timestamp;
        $this->withSession(['active_municipality_id' => $municipality->id, 'form_submission_tokens' => $tokens]);

        return $token;
    }
}
