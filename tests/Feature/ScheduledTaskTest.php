<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduledTaskTest extends TestCase
{
    public function test_scheduler_endpoint_rejects_missing_or_invalid_token(): void
    {
        config(['services.scheduler.token' => 'segredo-valido']);

        $this->postJson('/api/internal/scheduler')->assertForbidden();
        $this->withToken('segredo-incorreto')
            ->postJson('/api/internal/scheduler')
            ->assertForbidden();
    }

    public function test_scheduler_endpoint_runs_tasks_with_valid_token(): void
    {
        config(['services.scheduler.token' => 'segredo-valido']);
        Artisan::shouldReceive('call')
            ->once()
            ->with('schedule:run', ['--no-interaction' => true])
            ->andReturn(0);

        $this->withToken('segredo-valido')
            ->postJson('/api/internal/scheduler')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('exit_code', 0);
    }

    public function test_scheduler_endpoint_stays_closed_without_server_secret(): void
    {
        config(['services.scheduler.token' => null]);

        $this->withToken('qualquer-token')
            ->postJson('/api/internal/scheduler')
            ->assertForbidden();
    }
}
