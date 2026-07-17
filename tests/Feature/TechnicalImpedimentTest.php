<?php

namespace Tests\Feature;

use App\Models\AmendmentRemapping;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\TechnicalDiligence;
use App\Models\TechnicalImpediment;
use App\Models\User;
use App\Services\IntegrityAlertService;
use App\Services\MunicipalWorkItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TechnicalImpedimentTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_register_and_classify_a_technical_impediment(): void
    {
        [$manager, $municipality, $amendment] = $this->context();

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.impediments.store', $amendment), $this->payloadWithToken(
                "technical-impediment-create-{$amendment->id}",
                $this->impedimentPayload($manager),
            ))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $impediment = $amendment->technicalImpediments()->firstOrFail();
        $this->assertSame(TechnicalImpediment::STATUS_IDENTIFIED, $impediment->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'technical_impediment_created']);

        $this->patch(route('emendas.impediments.update', [$amendment, $impediment]), $this->payloadWithToken(
            "technical-impediment-update-{$impediment->id}",
            [
                'nature' => TechnicalImpediment::NATURE_TEMPORARY,
                'status' => TechnicalImpediment::STATUS_RESOLVED,
                'assigned_user_id' => $manager->id,
                'identified_at' => today()->format('Y-m-d'),
                'resolution_due_at' => today()->addDays(10)->format('Y-m-d'),
                'resolution_notes' => 'Projeto corrigido e validado pela equipe de engenharia.',
            ],
        ))->assertSessionHasNoErrors();

        $this->assertSame(TechnicalImpediment::STATUS_RESOLVED, $impediment->fresh()->status);
        $this->assertNotNull($impediment->fresh()->resolved_at);
    }

    public function test_diligence_requires_a_complete_response_and_updates_tracking(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $impediment = $this->impediment($amendment, $manager);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.impediments.diligences.store', [$amendment, $impediment]), $this->payloadWithToken(
                "technical-diligence-create-{$impediment->id}",
                [
                    'title' => 'Complementar projeto básico',
                    'request_details' => 'Apresentar memorial descritivo assinado e planilha revisada.',
                    'assigned_user_id' => $manager->id,
                    'requested_at' => today()->format('Y-m-d'),
                    'due_at' => today()->addDays(7)->format('Y-m-d'),
                ],
            ))
            ->assertSessionHasNoErrors();

        $diligence = $impediment->diligences()->firstOrFail();
        $this->assertSame(TechnicalImpediment::STATUS_UNDER_DILIGENCE, $impediment->fresh()->status);

        $this->from(route('emendas.impediments', $amendment))
            ->patch(route('emendas.impediments.diligences.update', [$amendment, $impediment, $diligence]), $this->payloadWithToken(
                "technical-diligence-update-{$diligence->id}",
                ['status' => TechnicalDiligence::STATUS_RESPONDED],
            ))
            ->assertSessionHasErrors('response_protocol');

        $editor = User::factory()->create();
        $municipality->users()->attach($editor, ['role' => User::ROLE_EDITOR]);
        $this->actingAs($editor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('emendas.impediments.diligences.update', [$amendment, $impediment, $diligence]), $this->payloadWithToken(
                "technical-diligence-update-{$diligence->id}",
                [
                    'status' => TechnicalDiligence::STATUS_ACCEPTED,
                    'response_notes' => 'Resposta conferida pelo editor.',
                    'response_protocol' => 'PROTOCOLO-EDITOR',
                ],
            ))
            ->assertForbidden();

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('emendas.impediments.diligences.update', [$amendment, $impediment, $diligence]), $this->payloadWithToken(
                "technical-diligence-update-{$diligence->id}",
                [
                    'status' => TechnicalDiligence::STATUS_ACCEPTED,
                    'response_notes' => 'Memorial e planilha revisada apresentados.',
                    'response_protocol' => 'PROTOCOLO-2026-001',
                ],
            ))->assertSessionHasNoErrors();

        $this->assertSame(TechnicalDiligence::STATUS_ACCEPTED, $diligence->fresh()->status);
        $this->assertNotNull($diligence->fresh()->responded_at);
    }

    public function test_insurmountable_impediment_can_be_remapped_with_manager_decision(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $editor = User::factory()->create();
        $municipality->users()->attach($editor, ['role' => User::ROLE_EDITOR]);
        $impediment = $this->impediment($amendment, $manager, [
            'nature' => TechnicalImpediment::NATURE_INSURMOUNTABLE,
            'status' => TechnicalImpediment::STATUS_CONFIRMED,
            'resolution_notes' => 'A área não possui regularidade dominial e não pode receber a obra.',
            'resolved_at' => now(),
        ]);

        $this->actingAs($editor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('emendas.impediments.remappings.store', [$amendment, $impediment]), $this->payloadWithToken(
                "amendment-remapping-create-{$impediment->id}",
                [
                    'proposed_object' => 'Aquisição de equipamentos para unidades municipais de saúde.',
                    'justification' => 'Preserva a finalidade de melhoria da atenção básica.',
                    'amount' => 100000,
                ],
            ))
            ->assertSessionHasNoErrors();

        $remapping = $impediment->remappings()->firstOrFail();
        $this->assertSame($amendment->object, $remapping->original_object);

        $this->patch(route('emendas.impediments.remappings.update', [$amendment, $impediment, $remapping]), $this->payloadWithToken(
            "amendment-remapping-update-{$remapping->id}",
            [
                'proposed_object' => 'Aquisição de equipamentos para duas unidades municipais de saúde.',
                'justification' => 'Preserva a finalidade e amplia a cobertura municipal.',
                'amount' => 95000,
            ],
        ))->assertSessionHasNoErrors();
        $this->assertSame('95000.00', $remapping->fresh()->amount);

        $this->post(route('emendas.impediments.remappings.submit', [$amendment, $impediment, $remapping]), $this->payloadWithToken(
            "amendment-remapping-submit-{$remapping->id}",
        ))->assertSessionHasNoErrors();

        $this->patch(route('emendas.impediments.remappings.decide', [$amendment, $impediment, $remapping]), [])
            ->assertForbidden();

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('emendas.impediments.remappings.decide', [$amendment, $impediment, $remapping]), $this->payloadWithToken(
                "amendment-remapping-decide-{$remapping->id}",
                [
                    'status' => AmendmentRemapping::STATUS_APPROVED,
                    'decision_notes' => 'Alternativa tecnicamente viável e compatível com a finalidade pública.',
                    'decision_reference' => 'Processo ADM 42/2026',
                ],
            ))
            ->assertSessionHasNoErrors();

        $this->assertSame(AmendmentRemapping::STATUS_APPROVED, $remapping->fresh()->status);
        $this->assertSame(TechnicalImpediment::STATUS_REMAPPED, $impediment->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'amendment_remapping_decided']);
    }

    public function test_remapping_is_blocked_until_impediment_is_insurmountable(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $impediment = $this->impediment($amendment, $manager);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->from(route('emendas.impediments', $amendment))
            ->post(route('emendas.impediments.remappings.store', [$amendment, $impediment]), $this->payloadWithToken(
                "amendment-remapping-create-{$impediment->id}",
                [
                    'proposed_object' => 'Objeto alternativo',
                    'justification' => 'Justificativa técnica suficiente para teste.',
                    'amount' => 100000,
                ],
            ))
            ->assertSessionHasErrors('remapping');

        $this->assertDatabaseCount('amendment_remappings', 0);
    }

    public function test_viewer_can_consult_but_cannot_change_impediments_and_tenants_are_isolated(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $viewer = User::factory()->create();
        $municipality->users()->attach($viewer, ['role' => User::ROLE_VIEWER]);
        $this->impediment($amendment, $manager);
        [, , $otherAmendment] = $this->context();

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.impediments', $amendment))
            ->assertOk()
            ->assertSee('Impedimentos e diligências')
            ->assertDontSee('Registrar impedimento');

        $this->post(route('emendas.impediments.store', $amendment), [])->assertForbidden();
        $this->get(route('emendas.impediments', $otherAmendment))->assertNotFound();
    }

    public function test_work_center_tracks_impediment_diligence_and_remapping_decision(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $impediment = $this->impediment($amendment, $manager, ['resolution_due_at' => today()->subDay()]);
        $diligence = $impediment->diligences()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'created_by' => $manager->id,
            'assigned_user_id' => $manager->id,
            'status' => TechnicalDiligence::STATUS_OPEN,
            'title' => 'Enviar licença',
            'request_details' => 'Apresentar licença emitida pelo órgão ambiental.',
            'requested_at' => today()->subDays(5),
            'due_at' => today()->subDay(),
        ]);
        $remapping = $impediment->remappings()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'requested_by' => $manager->id,
            'status' => AmendmentRemapping::STATUS_SUBMITTED,
            'original_object' => $amendment->object,
            'proposed_object' => 'Objeto alternativo para atendimento municipal.',
            'justification' => 'Alternativa tecnicamente executável.',
            'amount' => 100000,
            'requested_at' => today(),
        ]);

        app(MunicipalWorkItemService::class)->synchronize($municipality);

        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:technical-impediment:{$impediment->id}",
            'priority' => 'critical',
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:technical-diligence:{$diligence->id}",
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:remapping-decision:{$remapping->id}",
        ]);
    }

    public function test_overdue_impediment_generates_integrity_alert(): void
    {
        [$manager, $municipality, $amendment] = $this->context();
        $impediment = $this->impediment($amendment, $manager, [
            'resolution_due_at' => today()->subDays(2),
        ]);

        app(IntegrityAlertService::class)->sync($municipality);

        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => "deadline:impediment:{$impediment->id}",
            'category' => 'deadline',
            'severity' => 'critical',
            'status' => 'open',
        ]);
    }

    /** @return array{User, Municipality, ParliamentaryAmendment} */
    private function context(): array
    {
        $manager = User::factory()->create();
        $municipality = Municipality::factory()->create([
            'state' => 'SP',
            'ibge_code' => fake()->unique()->numerify('35#####'),
        ]);
        $municipality->users()->attach($manager, ['role' => User::ROLE_MANAGER]);
        $amendment = ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($manager, 'creator')
            ->create([
                'government_sphere' => 'municipal',
                'transferegov_code' => null,
                'expected_amount' => 100000,
            ]);

        return [$manager, $municipality, $amendment];
    }

    private function impediment(ParliamentaryAmendment $amendment, User $manager, array $attributes = []): TechnicalImpediment
    {
        return $amendment->technicalImpediments()->create([
            'municipality_id' => $amendment->municipality_id,
            'created_by' => $manager->id,
            'assigned_user_id' => $manager->id,
            'category' => 'engineering',
            'nature' => TechnicalImpediment::NATURE_UNDER_ANALYSIS,
            'status' => TechnicalImpediment::STATUS_IDENTIFIED,
            'title' => 'Projeto básico incompleto',
            'description' => 'O projeto não apresenta memorial descritivo assinado.',
            'impact' => 'A análise técnica e a contratação permanecem suspensas.',
            'identified_at' => today(),
            'resolution_due_at' => today()->addDays(10),
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function impedimentPayload(User $manager): array
    {
        return [
            'category' => 'engineering',
            'nature' => TechnicalImpediment::NATURE_UNDER_ANALYSIS,
            'title' => 'Projeto básico incompleto',
            'description' => 'O projeto não apresenta memorial descritivo assinado.',
            'impact' => 'A análise técnica e a contratação permanecem suspensas.',
            'assigned_user_id' => $manager->id,
            'identified_at' => today()->format('Y-m-d'),
            'resolution_due_at' => today()->addDays(10)->format('Y-m-d'),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function payloadWithToken(string $scope, array $payload = []): array
    {
        $token = (string) Str::uuid();
        $this->withSession([
            'form_submission_tokens' => [$scope => [$token => now()->timestamp]],
        ]);

        return ['_submission_token' => $token, ...$payload];
    }
}
