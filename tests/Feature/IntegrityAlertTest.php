<?php

namespace Tests\Feature;

use App\Models\IntegrityAlert;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Notifications\IntegrityAlertNotification;
use App\Services\IntegrityAlertProcessor;
use App\Services\IntegrityAlertService;
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
            'status' => ParliamentaryAmendment::STATUS_RESOURCE_RECEIVED,
            'received_amount' => null,
            'received_at' => null,
            'execution_deadline' => today()->addDays(5),
        ]);
        $municipality->documentTypes()->create([
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
        $this->assertDatabaseHas('integrity_alerts', ['alert_key' => 'document:1']);
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
