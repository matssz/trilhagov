<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Municipality;
use App\Models\MunicipalWorkItem;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\MunicipalWorkItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_generates_prioritized_actions_without_duplicates(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $documentType = $municipality->documentTypes()->create([
            'created_by' => $manager->id,
            'name' => 'Plano de trabalho',
            'description' => 'Planejamento aprovado para execução.',
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $amendment = $this->amendment($municipality, $manager, [
            'status' => ParliamentaryAmendment::STATUS_EXECUTING,
            'responsible_user_id' => null,
            'received_amount' => 100000,
            'received_at' => today()->subMonth(),
            'communication_deadline' => today()->subDay(),
            'communication_completed_at' => null,
            'execution_deadline' => today()->addMonths(4),
            'accountability_deadline' => today()->addDays(45),
        ]);
        $token = $this->sessionFor($municipality, "work-items-sync-{$municipality->id}");

        $this->actingAs($manager)->post(route('work-center.sync'), [
            '_submission_token' => $token,
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:responsible",
            'priority' => MunicipalWorkItem::PRIORITY_CRITICAL,
            'status' => MunicipalWorkItem::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:document:{$documentType->id}",
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:execution-plan",
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:first-commitment",
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => "amendment:{$amendment->id}:start-accountability",
        ]);
        $count = MunicipalWorkItem::count();

        app(MunicipalWorkItemService::class)->synchronize($municipality);
        $this->assertSame($count, MunicipalWorkItem::count());
        $this->assertSame($count, $municipality->workItems()->withCount('events')->get()->sum('events_count'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_items_synchronized']);
    }

    public function test_actions_complete_from_source_and_reopen_when_problem_returns(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $documentType = $municipality->documentTypes()->create([
            'created_by' => $manager->id,
            'name' => 'Comprovante obrigatório',
            'is_required' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $amendment = $this->amendment($municipality, $manager, [
            'responsible_user_id' => $manager->id,
            'communication_deadline' => today()->addMonth(),
            'communication_completed_at' => null,
            'execution_deadline' => today()->addMonths(6),
            'accountability_deadline' => today()->addMonths(9),
        ]);
        $service = app(MunicipalWorkItemService::class);
        $service->synchronize($municipality);
        $communicationKey = "amendment:{$amendment->id}:communication";
        $documentKey = "amendment:{$amendment->id}:document:{$documentType->id}";

        $amendment->update(['communication_completed_at' => today()]);
        $amendment->documents()->create($this->documentPayload($municipality, $manager, $documentType));
        $stats = $service->synchronize($municipality);

        $this->assertSame(2, $stats['completed']);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => $communicationKey,
            'status' => MunicipalWorkItem::STATUS_COMPLETED,
            'completion_reason' => 'Resolvida após atualização dos dados de origem.',
        ]);
        $this->assertDatabaseHas('municipal_work_item_events', [
            'municipal_work_item_id' => MunicipalWorkItem::where('source_key', $communicationKey)->value('id'),
            'event_type' => 'auto_completed',
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => $documentKey,
            'status' => MunicipalWorkItem::STATUS_COMPLETED,
        ]);

        $amendment->update(['communication_completed_at' => null]);
        $stats = $service->synchronize($municipality);
        $this->assertSame(1, $stats['reopened']);
        $this->assertDatabaseHas('municipal_work_items', [
            'source_key' => $communicationKey,
            'status' => MunicipalWorkItem::STATUS_PENDING,
            'completed_at' => null,
        ]);
        $this->assertDatabaseHas('municipal_work_item_events', [
            'municipal_work_item_id' => MunicipalWorkItem::where('source_key', $communicationKey)->value('id'),
            'event_type' => 'reopened',
        ]);
    }

    public function test_team_updates_assignment_and_progress_with_permissions_and_idempotency(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $editor = User::factory()->create();
        $viewer = User::factory()->create();
        $municipality->users()->attach($editor, ['role' => User::ROLE_EDITOR]);
        $municipality->users()->attach($viewer, ['role' => User::ROLE_VIEWER]);
        $amendment = $this->amendment($municipality, $manager, [
            'responsible_user_id' => null,
            'communication_completed_at' => today(),
        ]);
        app(MunicipalWorkItemService::class)->synchronize($municipality);
        $item = $municipality->workItems()->where('source_key', "amendment:{$amendment->id}:responsible")->firstOrFail();
        $token = $this->sessionFor($municipality, "work-item-update-{$item->id}");
        $payload = [
            '_submission_token' => $token,
            'status' => MunicipalWorkItem::STATUS_IN_PROGRESS,
            'responsible_user_id' => $editor->id,
            'notes' => 'Responsável alinhando os dados com a Secretaria de Finanças.',
        ];

        $this->actingAs($manager)->patch(route('work-center.items.update', $item), $payload)
            ->assertSessionHas('status');
        $this->assertDatabaseHas('municipal_work_items', [
            'id' => $item->id,
            'status' => MunicipalWorkItem::STATUS_IN_PROGRESS,
            'responsible_user_id' => $editor->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'work_item_updated']);
        $this->assertDatabaseHas('municipal_work_item_events', [
            'municipal_work_item_id' => $item->id,
            'event_type' => 'updated',
            'user_id' => $manager->id,
        ]);
        $this->patch(route('work-center.items.update', $item), $payload)
            ->assertSessionHas('warning', 'Esta atualização da ação já foi processada.');

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('work-center.index'))
            ->assertOk()
            ->assertSee('Central de Trabalho')
            ->assertDontSee('Atualizar plano');
        $this->patch(route('work-center.items.update', $item), [])->assertForbidden();
    }

    public function test_readonly_profiles_see_consultation_mode_and_wait_for_manager_empty_state(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $viewer = User::factory()->create();
        $auditor = User::factory()->create();
        $municipality->users()->attach($viewer, ['role' => User::ROLE_VIEWER]);
        $municipality->users()->attach($auditor, ['role' => User::ROLE_AUDITOR]);

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('work-center.index'))
            ->assertOk()
            ->assertSee('Somente leitura')
            ->assertSee('Aguardando atualização do gestor municipal.')
            ->assertDontSee('Atualize o plano para organizar as próximas ações do município.');

        $this->actingAs($auditor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('work-center.index'))
            ->assertOk()
            ->assertSee('Somente leitura')
            ->assertSee('Aguardando atualização do gestor municipal.');

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('work-center.index'))
            ->assertOk()
            ->assertDontSee('Somente leitura')
            ->assertSee('Atualize o plano para organizar as próximas ações do município.');
    }

    public function test_work_items_are_isolated_by_active_municipality(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, ['responsible_user_id' => null]);
        app(MunicipalWorkItemService::class)->synchronize($municipality);
        $item = $municipality->workItems()->where('source_key', "amendment:{$amendment->id}:responsible")->firstOrFail();

        [$otherManager, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $token = $this->sessionFor($otherMunicipality, "work-item-update-{$item->id}");
        $this->actingAs($otherManager)->patch(route('work-center.items.update', $item), [
            '_submission_token' => $token,
            'status' => MunicipalWorkItem::STATUS_IN_PROGRESS,
        ])->assertNotFound();
    }

    /** @return array{User, Municipality} */
    private function memberWithMunicipality(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }

    private function amendment(Municipality $municipality, User $user, array $attributes = []): ParliamentaryAmendment
    {
        return ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($user, 'creator')
            ->create($attributes);
    }

    /** @return array<string, mixed> */
    private function documentPayload(Municipality $municipality, User $user, DocumentType $documentType): array
    {
        return [
            'municipality_id' => $municipality->id,
            'document_type_id' => $documentType->id,
            'uploaded_by' => $user->id,
            'uploader_name' => $user->name,
            'original_name' => 'comprovante.pdf',
            'storage_path' => 'test/comprovante.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'version' => 1,
        ];
    }

    private function sessionFor(Municipality $municipality, string $scope): string
    {
        $token = (string) Str::uuid();
        $this->withSession([
            'active_municipality_id' => $municipality->id,
            'form_submission_tokens' => [$scope => [$token => now()->timestamp]],
        ]);

        return $token;
    }
}
