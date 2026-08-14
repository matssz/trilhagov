<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\SupportOccurrence;
use App\Models\User;
use App\Services\OccurrenceCenterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OccurrenceCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_view_occurrence_center(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        SupportOccurrence::query()->create([
            'municipality_id' => $municipality->id,
            'fingerprint' => hash('sha256', 'sample'),
            'source' => 'exception',
            'level' => 'error',
            'status' => SupportOccurrence::STATUS_OPEN,
            'title' => 'RuntimeException',
            'message' => 'Falha simulada no fluxo de teste.',
            'occurrence_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('occurrences.index'))
            ->assertOk()
            ->assertSee('Central de Ocorrências')
            ->assertSee('Falha simulada no fluxo de teste.')
            ->assertSee('Ações sensíveis recentes');
    }

    public function test_non_manager_cannot_view_occurrence_center(): void
    {
        [$editor, $municipality] = $this->member(User::ROLE_EDITOR);

        $this->actingAs($editor)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('occurrences.index'))
            ->assertForbidden();
    }

    public function test_manager_can_update_occurrence_treatment(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);
        $occurrence = SupportOccurrence::query()->create([
            'municipality_id' => $municipality->id,
            'fingerprint' => hash('sha256', 'treatment'),
            'source' => 'log',
            'level' => 'error',
            'status' => SupportOccurrence::STATUS_OPEN,
            'title' => 'Erro no log',
            'message' => 'Falha operacional registrada.',
            'occurrence_count' => 2,
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('occurrences.update', $occurrence), [
                'status' => SupportOccurrence::STATUS_RESOLVED,
                'resolution_notes' => 'Corrigido no deploy atual.',
            ])
            ->assertSessionHas('status');

        $occurrence->refresh();
        $this->assertSame(SupportOccurrence::STATUS_RESOLVED, $occurrence->status);
        $this->assertSame($manager->id, $occurrence->resolved_by);
        $this->assertSame('Corrigido no deploy atual.', $occurrence->resolution_notes);
        $this->assertNotNull($occurrence->resolved_at);
    }

    public function test_service_records_exception_without_breaking_request(): void
    {
        [$manager, $municipality] = $this->member(User::ROLE_MANAGER);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id]);

        request()->attributes->set('active_municipality', $municipality);
        app(OccurrenceCenterService::class)->recordException(new RuntimeException('Erro capturado pelo teste.'), request());

        $this->assertDatabaseHas('support_occurrences', [
            'municipality_id' => $municipality->id,
            'source' => 'exception',
            'title' => 'RuntimeException',
        ]);
    }

    /** @return array{User, Municipality} */
    private function member(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }
}
