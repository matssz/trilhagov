<?php

namespace Tests\Feature;

use App\Models\MunicipalInternalControlAction;
use App\Models\MunicipalInternalControlReview;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\IntegrityAlertService;
use App\Services\MunicipalInternalControlService;
use App\Services\MunicipalWorkItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class MunicipalInternalControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditor_issues_immutable_regular_review_with_snapshot_and_pdf(): void
    {
        [$manager, $auditor, $municipality, $amendment] = $this->context();

        $this->actingAs($auditor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('internal-control-reviews.store', $amendment), $this->payloadWithToken(
                "internal-control-review-create-{$amendment->id}",
                $this->reviewPayload(),
            ))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $review = MunicipalInternalControlReview::firstOrFail();
        $this->assertSame('PCI-2026-'.str_pad((string) $amendment->id, 5, '0', STR_PAD_LEFT).'-001', $review->reference);
        $this->assertSame(MunicipalInternalControlReview::CONCLUSION_REGULAR, $review->conclusion);
        $this->assertSame(MunicipalInternalControlService::FRAMEWORK_VERSION, $review->snapshot['framework_version']);
        $this->assertSame(64, strlen($review->snapshot_sha256));
        $this->assertDatabaseCount('municipal_internal_control_actions', 0);

        $this->get(route('internal-control-reviews.pdf', $review))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->expectException(LogicException::class);
        $review->update(['summary' => 'Tentativa de alteração posterior.']);
        $this->assertNotSame($manager->id, $auditor->id);
    }

    public function test_diligence_creates_action_with_private_evidence_and_complete_sanitation_flow(): void
    {
        Storage::fake('local');
        [$manager, $auditor, $municipality, $amendment, $editor] = $this->context(withEditor: true);
        $criteria = $this->criteria('compliant');
        $criteria['procurement'] = ['status' => 'non_compliant', 'notes' => 'Pesquisa de preços sem memória de cálculo suficiente.'];
        $payload = $this->reviewPayload([
            'conclusion' => MunicipalInternalControlReview::CONCLUSION_DILIGENCE,
            'criteria' => $criteria,
            'findings' => 'A instrução da contratação está incompleta.',
            'recommendations' => 'Complementar a pesquisa de preços e juntar a memória de cálculo ao processo.',
            'responsible_user_id' => $editor->id,
            'corrective_due_at' => today()->addDays(10)->format('Y-m-d'),
            'evidence' => UploadedFile::fake()->create('parecer-assinado.pdf', 120, 'application/pdf'),
        ]);

        $this->actingAs($auditor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('internal-control-reviews.store', $amendment), $this->payloadWithToken(
                "internal-control-review-create-{$amendment->id}",
                $payload,
            ))->assertSessionHasNoErrors();

        $review = MunicipalInternalControlReview::firstOrFail();
        $action = MunicipalInternalControlAction::firstOrFail();
        Storage::disk('local')->assertExists($review->evidence_path);
        $this->assertSame(MunicipalInternalControlAction::STATUS_OPEN, $action->status);
        $this->assertSame($editor->id, $action->responsible_user_id);

        $this->actingAs($editor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('internal-control-actions.respond', $action), $this->payloadWithToken(
                "internal-control-action-response-{$action->id}",
                [
                    'response_summary' => 'A pesquisa de preços foi refeita e a memória de cálculo foi anexada ao processo.',
                    'evidence' => UploadedFile::fake()->create('resposta.pdf', 90, 'application/pdf'),
                ],
            ))->assertSessionHasNoErrors();

        $action->refresh();
        $this->assertSame(MunicipalInternalControlAction::STATUS_RESPONDED, $action->status);
        $responseEvent = $action->events()->where('event_type', 'response')->firstOrFail();
        Storage::disk('local')->assertExists($responseEvent->evidence_path);

        $this->actingAs($auditor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('internal-control-actions.decide', $action), $this->payloadWithToken(
                "internal-control-action-decision-{$action->id}",
                [
                    'decision' => 'resolved',
                    'resolution_notes' => 'A documentação complementar resolve integralmente o apontamento.',
                ],
            ))->assertSessionHasNoErrors();

        $this->assertSame(MunicipalInternalControlAction::STATUS_RESOLVED, $action->fresh()->status);
        $this->assertDatabaseCount('municipal_internal_control_action_events', 3);
        $this->get(route('internal-control-actions.evidence', $responseEvent))->assertOk();
        $this->get(route('internal-control-reviews.evidence', $review))->assertOk();
        $this->assertNotSame($manager->id, $editor->id);
    }

    public function test_conclusion_must_match_standardized_criteria(): void
    {
        [, $auditor, $municipality, $amendment] = $this->context();
        $criteria = $this->criteria('compliant');
        $criteria['conflicts'] = ['status' => 'non_compliant', 'notes' => 'Declaração de conflito ausente.'];

        $this->actingAs($auditor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->from(route('emendas.internal-control', $amendment))
            ->post(route('internal-control-reviews.store', $amendment), $this->payloadWithToken(
                "internal-control-review-create-{$amendment->id}",
                $this->reviewPayload(['criteria' => $criteria]),
            ))
            ->assertSessionHasErrors('conclusion');

        $this->assertDatabaseCount('municipal_internal_control_reviews', 0);
    }

    public function test_segregation_prevents_self_assignment_and_self_validation(): void
    {
        [$manager, $auditor, $municipality, $amendment] = $this->context();
        $criteria = $this->criteria('compliant');
        $criteria['budget'] = ['status' => 'attention', 'notes' => 'Aguardando conciliação final com a contabilidade.'];

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('internal-control-reviews.store', $amendment), $this->payloadWithToken(
                "internal-control-review-create-{$amendment->id}",
                $this->reviewPayload([
                    'conclusion' => MunicipalInternalControlReview::CONCLUSION_RECOMMENDATIONS,
                    'criteria' => $criteria,
                    'findings' => 'Conciliação final ainda não juntada.',
                    'recommendations' => 'Juntar conciliação assinada pela contabilidade.',
                    'responsible_user_id' => $manager->id,
                    'corrective_due_at' => today()->addDays(5)->format('Y-m-d'),
                ]),
            ))->assertSessionHasErrors('responsible_user_id');

        $this->assertDatabaseCount('municipal_internal_control_reviews', 0);

        $this->post(route('internal-control-reviews.store', $amendment), $this->payloadWithToken(
            "internal-control-review-create-{$amendment->id}",
            $this->reviewPayload([
                'conclusion' => MunicipalInternalControlReview::CONCLUSION_RECOMMENDATIONS,
                'criteria' => $criteria,
                'findings' => 'Conciliação final ainda não juntada.',
                'recommendations' => 'Juntar conciliação assinada pela contabilidade.',
                'responsible_user_id' => $auditor->id,
                'corrective_due_at' => today()->addDays(5)->format('Y-m-d'),
            ]),
        ))->assertSessionHasErrors('responsible_user_id');
    }

    public function test_viewer_reads_own_municipality_but_cannot_issue_or_cross_tenants(): void
    {
        [, , $municipality, $amendment] = $this->context();
        $viewer = User::factory()->create();
        $municipality->users()->attach($viewer, ['role' => User::ROLE_VIEWER]);
        [, , $otherMunicipality, $otherAmendment] = $this->context();

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.internal-control', $amendment))
            ->assertOk()
            ->assertSee('Parecer do Controle Interno')
            ->assertDontSee('Emitir parecer imutável');

        $this->post(route('internal-control-reviews.store', $amendment), [])->assertForbidden();
        $this->get(route('emendas.internal-control', $otherAmendment))->assertNotFound();
        $this->assertNotSame($municipality->id, $otherMunicipality->id);
    }

    public function test_open_control_action_feeds_work_center_and_integrity_alerts(): void
    {
        [, $auditor, $municipality, $amendment, $editor] = $this->context(withEditor: true);
        $criteria = $this->criteria('compliant');
        $criteria['transparency'] = ['status' => 'attention', 'notes' => 'Documento ainda não publicado no portal.'];

        $this->actingAs($auditor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('internal-control-reviews.store', $amendment), $this->payloadWithToken(
                "internal-control-review-create-{$amendment->id}",
                $this->reviewPayload([
                    'conclusion' => MunicipalInternalControlReview::CONCLUSION_RECOMMENDATIONS,
                    'criteria' => $criteria,
                    'findings' => 'Publicação incompleta.',
                    'recommendations' => 'Publicar o documento correlato no portal municipal.',
                    'responsible_user_id' => $editor->id,
                    'corrective_due_at' => today()->format('Y-m-d'),
                ]),
            ))->assertSessionHasNoErrors();

        $action = MunicipalInternalControlAction::firstOrFail();
        app(MunicipalWorkItemService::class)->synchronize($municipality);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:internal-control-action:{$action->id}",
            'category' => 'control',
            'responsible_user_id' => $editor->id,
        ]);

        app(IntegrityAlertService::class)->sync($municipality);
        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => "deadline:internal-control:{$action->id}",
            'assigned_user_id' => $editor->id,
            'status' => 'open',
        ]);
    }

    public function test_repeated_submission_does_not_duplicate_review(): void
    {
        [, $auditor, $municipality, $amendment] = $this->context();
        $token = $this->token("internal-control-review-create-{$amendment->id}");
        $payload = ['_submission_token' => $token, ...$this->reviewPayload()];

        $this->actingAs($auditor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('internal-control-reviews.store', $amendment), $payload)
            ->assertSessionHasNoErrors();
        $this->post(route('internal-control-reviews.store', $amendment), $payload)
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('municipal_internal_control_reviews', 1);
    }

    /** @return array<int, mixed> */
    private function context(bool $withEditor = false): array
    {
        $manager = User::factory()->create();
        $auditor = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP', 'ibge_code' => fake()->unique()->numerify('354####')]);
        $municipality->users()->attach($manager, ['role' => User::ROLE_MANAGER]);
        $municipality->users()->attach($auditor, ['role' => User::ROLE_AUDITOR]);
        $amendment = ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($manager, 'creator')
            ->create([
                'fiscal_year' => 2026,
                'government_sphere' => 'municipal',
                'transfer_type' => 'direct_execution',
                'transferegov_code' => null,
                'expected_amount' => 250000,
                'responsible_user_id' => $manager->id,
                'administrative_process' => 'PA-2026-0142',
            ]);

        if (! $withEditor) {
            return [$manager, $auditor, $municipality, $amendment];
        }

        $editor = User::factory()->create();
        $municipality->users()->attach($editor, ['role' => User::ROLE_EDITOR]);

        return [$manager, $auditor, $municipality, $amendment, $editor];
    }

    /** @return array<string, mixed> */
    private function reviewPayload(array $overrides = []): array
    {
        return array_merge([
            'phase' => MunicipalInternalControlReview::PHASE_CONCOMITANT,
            'conclusion' => MunicipalInternalControlReview::CONCLUSION_REGULAR,
            'criteria' => $this->criteria('compliant'),
            'summary' => 'A verificação padronizada não identificou impropriedades na data desta análise.',
            'annual_audit_plan_reference' => 'PAA 2026, item 4.2',
            'legal_basis' => 'Comunicado GP 15/2026 e Manual TCESP, item 7.3.',
        ], $overrides);
    }

    /** @return array<string, array{status: string, notes: null}> */
    private function criteria(string $status): array
    {
        return collect(app(MunicipalInternalControlService::class)->criteria())
            ->mapWithKeys(fn ($definition, $code) => [$code => ['status' => $status, 'notes' => null]])
            ->all();
    }

    /** @return array<string, mixed> */
    private function payloadWithToken(string $scope, array $payload): array
    {
        return ['_submission_token' => $this->token($scope), ...$payload];
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
