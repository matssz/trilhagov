<?php

namespace Tests\Feature;

use App\Models\MunicipalAuditPlan;
use App\Models\MunicipalAuditPlanItem;
use App\Models\MunicipalAuditProcedure;
use App\Models\MunicipalAuditProgram;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\MunicipalAuditProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MunicipalAuditProgramTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditor_creates_one_program_from_an_issued_plan_item_with_segregated_supervision(): void
    {
        [$manager, $auditor, , $municipality, , $item] = $this->context();
        $this->actingAs($auditor)->withSession(['active_municipality_id' => $municipality->id]);
        $token = $this->token($municipality, "audit-program-create-{$item->id}");

        $this->post(route('audit-programs.store', $item), [
            '_submission_token' => $token,
            'supervisor_id' => $manager->id,
        ])->assertSessionHasNoErrors();

        $program = MunicipalAuditProgram::firstOrFail();
        $this->assertSame($auditor->id, $program->lead_auditor_id);
        $this->assertSame($manager->id, $program->supervisor_id);
        $this->assertSame(MunicipalAuditProgram::STATUS_DRAFT, $program->status);
        $this->assertDatabaseHas('municipal_audit_program_events', ['event_type' => 'created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'municipal_audit_program_created']);

        $this->post(route('audit-programs.store', $item), [
            '_submission_token' => $token,
            'supervisor_id' => $manager->id,
        ])->assertSessionHas('warning');
        $this->assertDatabaseCount('municipal_audit_programs', 1);
    }

    public function test_same_person_cannot_lead_and_supervise_the_program(): void
    {
        [, $auditor, , $municipality, , $item] = $this->context();

        $this->actingAs($auditor)->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('audit-programs.store', $item), [
                '_submission_token' => $this->token($municipality, "audit-program-create-{$item->id}"),
                'supervisor_id' => $auditor->id,
            ])->assertSessionHasErrors('supervisor_id');

        $this->assertDatabaseCount('municipal_audit_programs', 0);
    }

    public function test_workpaper_requires_executed_procedure_evidence_and_finding_for_an_exception(): void
    {
        [$manager, $auditor, , $municipality, , $item] = $this->context();
        $program = $this->program($item, $auditor, $manager);
        $this->actingAs($auditor)->withSession(['active_municipality_id' => $municipality->id]);

        $this->patch(route('audit-programs.update', $program), [
            '_submission_token' => $this->token($municipality, "audit-program-update-{$program->id}"),
            ...$this->programPayload($auditor, $manager),
        ])->assertSessionHasNoErrors();

        $this->post(route('audit-procedures.store', $program), [
            '_submission_token' => $this->token($municipality, "audit-procedure-create-{$program->id}"),
            ...$this->procedurePayload(),
        ])->assertSessionHasNoErrors();
        $procedure = MunicipalAuditProcedure::firstOrFail();

        $this->patch(route('audit-procedures.update', $procedure), [
            '_submission_token' => $this->token($municipality, "audit-procedure-update-{$procedure->id}"),
            ...$this->procedurePayload(),
            'status' => MunicipalAuditProcedure::STATUS_EXCEPTION,
            'result' => 'A amostra apresentou pagamento sem documento de aceite da entrega correspondente.',
        ])->assertSessionHasNoErrors();

        $blockers = app(MunicipalAuditProgramService::class)->readiness($program->fresh());
        $this->assertTrue(collect($blockers)->contains(fn ($message) => str_contains($message, 'evidência')));
        $this->assertTrue(collect($blockers)->contains(fn ($message) => str_contains($message, 'achado')));

        Storage::fake('local');
        $this->post(route('audit-program-evidences.store', $procedure), [
            '_submission_token' => $this->token($municipality, "audit-evidence-create-{$procedure->id}"),
            'description' => 'Extrato da amostra examinada',
            'evidence' => UploadedFile::fake()->create('amostra.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $this->post(route('audit-findings.store', $program), [
            '_submission_token' => $this->token($municipality, "audit-finding-create-{$program->id}"),
            ...$this->findingPayload($procedure),
        ])->assertSessionHasNoErrors();

        $evidence = $procedure->evidences()->firstOrFail();
        Storage::disk('local')->assertExists($evidence->storage_path);
        $this->assertSame(64, strlen($evidence->sha256));
        $this->assertSame([], app(MunicipalAuditProgramService::class)->readiness($program->fresh()));
    }

    public function test_supervisor_reviews_and_formally_concludes_program_with_immutable_snapshot(): void
    {
        [$manager, $auditor, $otherAuditor, $municipality, , $item] = $this->context();
        $program = $this->program($item, $auditor, $manager, $this->programPayload($auditor, $manager));
        $procedure = $program->procedures()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $auditor->id,
            'executed_by' => $auditor->id,
            'sequence' => 1,
            ...$this->procedurePayload(),
            'status' => MunicipalAuditProcedure::STATUS_COMPLIANT,
            'result' => 'A amostra apresentou documentação compatível com o objeto e com os pagamentos examinados.',
            'executed_at' => now(),
        ]);
        $procedure->evidences()->create([
            'municipality_id' => $municipality->id,
            'uploaded_by' => $auditor->id,
            'uploader_name' => $auditor->name,
            'description' => 'Planilha de testes',
            'original_name' => 'testes.csv',
            'storage_path' => 'fake/testes.csv',
            'mime_type' => 'text/csv',
            'size_bytes' => 100,
            'sha256' => hash('sha256', 'testes'),
        ]);

        $this->actingAs($auditor)->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('audit-programs.submit', $program), [
                '_submission_token' => $this->token($municipality, "audit-program-submit-{$program->id}"),
                'confirm_workpapers' => 1,
            ])->assertSessionHasNoErrors();
        $this->assertSame(MunicipalAuditProgram::STATUS_UNDER_REVIEW, $program->fresh()->status);

        $this->actingAs($otherAuditor)->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('audit-programs.review', $program), [
                '_submission_token' => $this->token($municipality, "audit-program-review-{$program->id}"),
                'decision' => 'approved',
                'supervisor_notes' => 'Papéis revisados sem ressalvas de supervisão.',
            ])->assertForbidden();

        $this->actingAs($manager)->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('audit-programs.review', $program), [
                '_submission_token' => $this->token($municipality, "audit-program-review-{$program->id}"),
                'decision' => 'approved',
                'supervisor_notes' => 'Papéis revisados sem ressalvas de supervisão.',
            ])->assertSessionHasNoErrors();
        $this->assertSame(MunicipalAuditProgram::STATUS_APPROVED, $program->fresh()->status);

        $this->post(route('audit-programs.conclude', $program), [
            '_submission_token' => $this->token($municipality, "audit-program-conclude-{$program->id}"),
            'conclusion' => 'Os procedimentos executados não identificaram exceções materiais no escopo e na amostra examinados.',
            'confirm_conclusion' => 1,
        ])->assertSessionHasNoErrors();

        $program->refresh();
        $this->assertSame(MunicipalAuditProgram::STATUS_CONCLUDED, $program->status);
        $this->assertSame(64, strlen($program->snapshot_sha256));
        $this->assertSame('testes.csv', $program->snapshot['procedures'][0]['evidences'][0]['original_name']);
        $this->assertSame(MunicipalAuditPlanItem::STATUS_COMPLETED, $item->fresh()->status);
        $this->assertDatabaseHas('municipal_audit_plan_item_events', ['event_type' => 'completed']);
        $this->get(route('audit-programs.pdf', $program))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_program_is_visible_to_viewer_and_isolated_between_municipalities(): void
    {
        [$manager, $auditor, , $municipality, , $item] = $this->context();
        $program = $this->program($item, $auditor, $manager);
        $viewer = User::factory()->create();
        $municipality->users()->attach($viewer, ['role' => User::ROLE_VIEWER]);

        $this->actingAs($viewer)->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('audit-programs.show', $program))->assertOk()->assertSee($program->title);
        $this->patch(route('audit-programs.update', $program))->assertForbidden();

        [, , , $otherMunicipality] = $this->context();
        $otherMunicipality->users()->attach($viewer, ['role' => User::ROLE_VIEWER]);
        $this->withSession(['active_municipality_id' => $otherMunicipality->id])
            ->get(route('audit-programs.show', $program))->assertNotFound();
    }

    /** @return array{User, User, User, Municipality, ParliamentaryAmendment, MunicipalAuditPlanItem} */
    private function context(): array
    {
        $manager = User::factory()->create();
        $auditor = User::factory()->create();
        $otherAuditor = User::factory()->create();
        $municipality = Municipality::factory()->create([
            'state' => 'SP',
            'ibge_code' => fake()->unique()->numerify('354####'),
            'cnpj' => fake()->unique()->numerify('##############'),
        ]);
        $municipality->users()->attach($manager, ['role' => User::ROLE_MANAGER]);
        $municipality->users()->attach($auditor, ['role' => User::ROLE_AUDITOR]);
        $municipality->users()->attach($otherAuditor, ['role' => User::ROLE_AUDITOR]);
        $amendment = ParliamentaryAmendment::factory()->for($municipality)->for($manager, 'creator')->create([
            'fiscal_year' => 2026,
            'government_sphere' => 'municipal',
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
        ]);
        $plan = $municipality->auditPlans()->create([
            'created_by' => $manager->id,
            'issued_by' => $manager->id,
            'fiscal_year' => 2026,
            'version' => 1,
            'status' => MunicipalAuditPlan::STATUS_ISSUED,
            'title' => 'Plano Anual de Auditoria Municipal',
            'objective' => 'Avaliar os riscos e a regularidade da execução das emendas municipais selecionadas.',
            'methodology' => 'Aplicar procedimentos de auditoria orientados por risco, materialidade e criticidade.',
            'risk_criteria' => 'Materialidade, ausência de evidência, risco de prazo e criticidade da entrega.',
            'normative_basis' => 'Manual TCESP e regulamentação municipal vigente.',
            'coordination_unit' => 'Unidade Central de Controle Interno',
            'planned_start_at' => '2026-01-01',
            'planned_end_at' => '2026-12-31',
            'snapshot' => ['reference' => 'PAA-2026-V1'],
            'snapshot_sha256' => hash('sha256', 'PAA-2026-V1'),
            'issued_at' => now(),
        ]);
        $item = $plan->items()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'assigned_user_id' => $auditor->id,
            'created_by' => $manager->id,
            'phase' => 'concomitant',
            'priority' => 'high',
            'frequency' => 'milestones',
            'status' => MunicipalAuditPlanItem::STATUS_PLANNED,
            'planned_at' => '2026-10-31',
            'scope_notes' => 'Examinar a regularidade da execução e as evidências vinculadas aos pagamentos municipais.',
        ]);

        return [$manager, $auditor, $otherAuditor, $municipality, $amendment, $item];
    }

    private function program(MunicipalAuditPlanItem $item, User $auditor, User $supervisor, array $overrides = []): MunicipalAuditProgram
    {
        return $item->program()->create(array_merge([
            'municipality_id' => $item->municipality_id,
            'lead_auditor_id' => $auditor->id,
            'supervisor_id' => $supervisor->id,
            'created_by' => $auditor->id,
            'status' => MunicipalAuditProgram::STATUS_DRAFT,
            'title' => 'Programa de Auditoria da Emenda Municipal',
            'objective' => 'Avaliar a regularidade dos atos e das transações vinculados à execução da emenda municipal.',
            'scope' => 'Examinar documentos, pagamentos e evidências da execução física no período selecionado.',
            'sampling_method' => 'judgmental',
            'population_description' => 'Pagamentos e documentos de aceite vinculados ao processo administrativo.',
            'materiality_criteria' => 'Selecionar os maiores valores e os itens com maior risco documental.',
            'start_at' => '2026-08-01',
            'due_at' => '2026-10-31',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function programPayload(User $auditor, User $supervisor): array
    {
        return [
            'lead_auditor_id' => $auditor->id,
            'supervisor_id' => $supervisor->id,
            'title' => 'Programa de Auditoria da Execução Municipal',
            'objective' => 'Avaliar a regularidade dos atos e das transações vinculados à execução da emenda municipal.',
            'scope' => 'Examinar documentos, pagamentos e evidências da execução física no período selecionado.',
            'sampling_method' => 'judgmental',
            'population_description' => 'Dez pagamentos e respectivos documentos de aceite vinculados ao processo.',
            'population_size' => 10,
            'sample_size' => 5,
            'materiality_criteria' => 'Selecionar os maiores valores e os itens com maior risco documental.',
            'start_at' => '2026-08-01',
            'due_at' => '2026-10-31',
        ];
    }

    /** @return array<string, mixed> */
    private function procedurePayload(): array
    {
        return [
            'title' => 'Conferir liquidação e aceite da entrega',
            'objective' => 'Verificar se os pagamentos possuem liquidação e aceite compatíveis.',
            'test_method' => 'Confrontar empenho, liquidação, nota fiscal, ateste e extrato bancário para cada item.',
            'sample_description' => 'Cinco pagamentos de maior valor realizados no exercício.',
            'expected_evidence' => 'Planilha de testes, documentos fiscais e extratos da conta específica.',
        ];
    }

    /** @return array<string, mixed> */
    private function findingPayload(MunicipalAuditProcedure $procedure): array
    {
        return [
            'municipal_audit_procedure_id' => $procedure->id,
            'severity' => 'high',
            'title' => 'Ausência de aceite em item da amostra',
            'criteria' => 'A liquidação deve estar fundamentada em comprovação da entrega e aceite do objeto.',
            'condition' => 'Um pagamento da amostra não apresentou documento de aceite da entrega correspondente.',
            'cause' => 'Fluxo de conferência documental não formalizado pela unidade executora.',
            'effect' => 'Risco de pagamento sem comprovação suficiente da execução física.',
            'recommendation' => 'Formalizar o aceite e revisar os pagamentos semelhantes antes da prestação de contas.',
            'recommended_due_at' => '2026-11-15',
        ];
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
