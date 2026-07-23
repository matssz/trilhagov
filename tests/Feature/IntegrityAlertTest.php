<?php

namespace Tests\Feature;

use App\Models\AmendmentComplianceReview;
use App\Models\IntegrityAlert;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Notifications\IntegrityAlertNotification;
use App\Services\IntegrityAlertProcessor;
use App\Services\IntegrityAlertService;
use App\Services\TcespComplianceFramework;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IntegrityAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_detects_deadlines_missing_documents_and_inconsistent_data(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, [
            'responsible_user_id' => $manager->id,
            'status' => ParliamentaryAmendment::STATUS_RESOURCE_RECEIVED,
            'received_amount' => null,
            'received_at' => null,
            'execution_deadline' => today()->addDays(5),
        ]);
        $documentType = $municipality->documentTypes()->create([
            'name' => 'Plano de trabalho',
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $stats = app(IntegrityAlertService::class)->sync($municipality);

        $this->assertSame(3, $stats['open']);
        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => 'deadline:execution',
            'severity' => IntegrityAlert::SEVERITY_CRITICAL,
            'status' => IntegrityAlert::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('integrity_alerts', ['alert_key' => "document:{$documentType->id}"]);
        $this->assertDatabaseHas('integrity_alerts', ['alert_key' => 'consistency:receipt']);
    }

    public function test_corrected_pending_item_is_resolved_automatically(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, [
            'execution_deadline' => today()->addDay(),
        ]);
        $service = app(IntegrityAlertService::class);
        $service->sync($municipality);

        $amendment->update(['execution_completed_at' => today()]);
        $stats = $service->sync($municipality->fresh());

        $this->assertSame(1, $stats['resolved']);
        $this->assertDatabaseHas('integrity_alerts', [
            'alert_key' => 'deadline:execution',
            'status' => IntegrityAlert::STATUS_RESOLVED,
        ]);
    }

    public function test_repeated_processing_does_not_duplicate_notifications(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $this->amendment($municipality, $manager, [
            'responsible_user_id' => $manager->id,
            'execution_deadline' => today()->addDay(),
        ]);
        $processor = app(IntegrityAlertProcessor::class);

        $first = $processor->process($municipality);
        $second = $processor->process($municipality->fresh());

        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('alert_deliveries', 1);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Prazo próximo');
    }

    public function test_enabled_channels_are_respected_per_user(): void
    {
        Notification::fake();
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $municipality->users()->updateExistingPivot($manager->id, [
            'notify_in_app' => true,
            'notify_email' => true,
        ]);
        $this->amendment($municipality, $manager, [
            'responsible_user_id' => $manager->id,
            'execution_deadline' => today(),
        ]);

        app(IntegrityAlertProcessor::class)->process($municipality);

        Notification::assertSentToTimes($manager, IntegrityAlertNotification::class, 2);
        $this->assertDatabaseHas('alert_deliveries', ['channel' => 'database']);
        $this->assertDatabaseHas('alert_deliveries', ['channel' => 'mail']);
    }

    public function test_alert_center_is_isolated_by_active_municipality(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$otherManager, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $this->amendment($municipality, $manager, [
            'reference' => 'EM-VISIVEL',
            'execution_deadline' => today(),
        ]);
        $this->amendment($otherMunicipality, $otherManager, [
            'reference' => 'EM-OUTRO-MUNICIPIO',
            'execution_deadline' => today(),
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('EM-VISIVEL')
            ->assertDontSee('EM-OUTRO-MUNICIPIO');
    }

    public function test_automatic_read_sync_is_limited_to_one_run_per_interval(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $this->amendment($municipality, $manager, [
            'reference' => 'EM-SYNC-CONTROLADO',
            'execution_deadline' => today(),
        ]);
        $service = app(IntegrityAlertService::class);

        $firstRun = $service->syncIfDue($municipality);
        $secondRun = $service->syncIfDue($municipality->fresh());

        $this->assertIsArray($firstRun);
        $this->assertNull($secondRun);
        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $municipality->amendments()->firstOrFail()->id,
            'alert_key' => 'deadline:execution',
        ]);
    }

    public function test_user_can_update_personal_notification_preferences(): void
    {
        [$viewer, $municipality] = $this->memberWithMunicipality(User::ROLE_VIEWER);

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('notifications.preferences.update'), [
                'notify_email' => '1',
                'notify_deadlines' => '1',
            ])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('municipality_user', [
            'municipality_id' => $municipality->id,
            'user_id' => $viewer->id,
            'notify_in_app' => false,
            'notify_email' => true,
            'notify_deadlines' => true,
            'notify_integrity' => false,
        ]);
    }

    public function test_missing_responsible_person_creates_moderate_risk_and_is_resolved_after_assignment(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, [
            'execution_deadline' => today()->addMonths(6),
        ]);
        $service = app(IntegrityAlertService::class);

        $service->sync($municipality);

        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => 'assignment:missing',
            'status' => IntegrityAlert::STATUS_OPEN,
        ]);
        $this->assertSame(ParliamentaryAmendment::RISK_MODERATE, $amendment->fresh()->risk_level);
        $this->assertSame(20, $amendment->fresh()->risk_score);

        $amendment->update(['responsible_user_id' => $manager->id]);
        $service->sync($municipality->fresh());

        $this->assertDatabaseHas('integrity_alerts', [
            'alert_key' => 'assignment:missing',
            'status' => IntegrityAlert::STATUS_RESOLVED,
        ]);
        $this->assertSame(ParliamentaryAmendment::RISK_LOW, $amendment->fresh()->risk_level);
        $this->assertSame(0, $amendment->fresh()->risk_score);
    }

    public function test_tcesp_critical_matrix_items_create_and_resolve_integrity_alerts(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $municipality->update(['state' => 'SP', 'ibge_code' => '3522307']);
        $amendment = $this->amendment($municipality, $manager, [
            'government_sphere' => 'municipal',
            'transfer_type' => 'direct_execution',
            'responsible_user_id' => $manager->id,
        ]);
        $service = app(IntegrityAlertService::class);

        $service->sync($municipality->fresh());

        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => 'tcesp-compliance:NORM-01',
            'severity' => IntegrityAlert::SEVERITY_WARNING,
            'status' => IntegrityAlert::STATUS_OPEN,
            'assigned_user_id' => $manager->id,
        ]);

        $amendment->complianceReviews()->create([
            'municipality_id' => $municipality->id,
            'framework_version' => TcespComplianceFramework::VERSION,
            'rule_code' => 'NORM-01',
            'status' => AmendmentComplianceReview::STATUS_COMPLIANT,
            'evidence_notes' => 'Lei Orgânica, LDO e regulamentação municipal conferidas.',
            'reviewed_by' => $manager->id,
            'reviewed_at' => now(),
        ]);

        $service->sync($municipality->fresh());

        $this->assertDatabaseHas('integrity_alerts', [
            'parliamentary_amendment_id' => $amendment->id,
            'alert_key' => 'tcesp-compliance:NORM-01',
            'status' => IntegrityAlert::STATUS_RESOLVED,
        ]);
    }

    public function test_level_two_escalation_notifies_responsible_managers_and_editors(): void
    {
        Notification::fake();
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $responsible = User::factory()->create();
        $otherEditor = User::factory()->create();
        $municipality->users()->attach($responsible, ['role' => User::ROLE_EDITOR]);
        $municipality->users()->attach($otherEditor, ['role' => User::ROLE_EDITOR]);
        $amendment = $this->amendment($municipality, $manager, [
            'responsible_user_id' => $responsible->id,
            'communication_deadline' => today()->subDays(8),
            'execution_deadline' => today()->addMonths(3),
        ]);

        app(IntegrityAlertProcessor::class)->process($municipality);

        $alert = IntegrityAlert::query()->where('alert_key', 'deadline:communication')->firstOrFail();
        $this->assertSame(2, $alert->escalation_level);
        $this->assertSame(ParliamentaryAmendment::RISK_HIGH, $amendment->fresh()->risk_level);
        $this->assertSame(45, $amendment->fresh()->risk_score);
        Notification::assertSentToTimes($responsible, IntegrityAlertNotification::class, 1);
        Notification::assertSentToTimes($manager, IntegrityAlertNotification::class, 1);
        Notification::assertSentToTimes($otherEditor, IntegrityAlertNotification::class, 1);
        $this->assertDatabaseCount('alert_deliveries', 3);

        $mail = (new IntegrityAlertNotification(
            $alert->load(['amendment', 'municipality']),
            ['mail'],
        ))->toMail($manager);
        $this->assertSame('TrilhaGov: Prazo em escalonamento máximo', $mail->subject);
    }

    public function test_manager_can_configure_escalation_matrix(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('alerts.settings.update'), [
                'deadline_warning_days' => 45,
                'deadline_critical_days' => 10,
                'overdue_repeat_days' => 5,
                'escalation_level_one_days' => 2,
                'escalation_level_two_days' => 12,
                'notify_managers_on_warning' => '1',
            ])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('municipality_alert_settings', [
            'municipality_id' => $municipality->id,
            'escalation_level_one_days' => 2,
            'escalation_level_two_days' => 12,
            'notify_managers_on_warning' => true,
            'notify_editors_on_level_two' => false,
        ]);
    }

    /** @return array{User, Municipality} */
    private function memberWithMunicipality(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }

    /** @param array<string, mixed> $attributes */
    private function amendment(Municipality $municipality, User $user, array $attributes): ParliamentaryAmendment
    {
        return ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($user, 'creator')
            ->create($attributes);
    }
}
