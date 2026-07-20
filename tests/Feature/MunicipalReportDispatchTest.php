<?php

namespace Tests\Feature;

use App\Models\MunicipalGovernanceReport;
use App\Models\Municipality;
use App\Models\MunicipalReportDispatch;
use App\Models\User;
use App\Services\MunicipalReportDispatchDeadlineProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class MunicipalReportDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_prepares_dispatch_only_for_issued_report_and_duplicate_is_blocked(): void
    {
        [$editor, $municipality, $report] = $this->context(User::ROLE_EDITOR);
        $token = $this->token($municipality, "report-dispatch-create-{$report->id}");
        $response = $this->actingAs($editor)->post(route('report-dispatches.store', $report), [
            '_submission_token' => $token,
            ...$this->dispatchPayload(['responsible_user_id' => $editor->id]),
        ]);

        $dispatch = MunicipalReportDispatch::firstOrFail();
        $response->assertRedirect(route('report-dispatches.show', $dispatch));
        $this->assertSame(MunicipalReportDispatch::STATUS_PREPARED, $dispatch->status);
        $this->assertDatabaseHas('municipal_report_dispatch_events', ['type' => 'prepared']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report_dispatch_created']);

        $duplicateToken = $this->token($municipality, "report-dispatch-create-{$report->id}");
        $this->post(route('report-dispatches.store', $report), [
            '_submission_token' => $duplicateToken,
            ...$this->dispatchPayload(),
        ])->assertSessionHasErrors('recipient_name');
        $this->assertDatabaseCount('municipal_report_dispatches', 1);

        $draft = $this->report($municipality, $editor, MunicipalGovernanceReport::STATUS_DRAFT, 8);
        $draftToken = $this->token($municipality, "report-dispatch-create-{$draft->id}");
        $this->post(route('report-dispatches.store', $draft), [
            '_submission_token' => $draftToken,
            ...$this->dispatchPayload(),
        ])->assertStatus(409);
    }

    public function test_protocol_and_receipt_keep_private_hashed_evidence(): void
    {
        Storage::fake('local');
        [$manager, $municipality, $report] = $this->context(User::ROLE_MANAGER);
        $dispatch = $this->dispatch($report, $municipality, $manager);
        $sendToken = $this->token($municipality, "report-dispatch-send-{$dispatch->id}");
        $this->actingAs($manager)->post(route('report-dispatches.send', $dispatch), [
            '_submission_token' => $sendToken,
            'official_document_number' => 'OFÍCIO 42/2026',
            'protocol_number' => 'CAM-2026-00981',
            'sent_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'evidence' => UploadedFile::fake()->create('protocolo.pdf', 40, 'application/pdf'),
        ])->assertSessionHas('status');

        $dispatch->refresh();
        $this->assertSame(MunicipalReportDispatch::STATUS_SENT, $dispatch->status);
        $event = $dispatch->events()->where('type', 'sent')->firstOrFail();
        $this->assertSame(64, strlen($event->evidence_sha256));
        Storage::assertExists($event->evidence_storage_path);
        $this->get(route('report-dispatches.evidence', [$dispatch, $event]))->assertOk();

        $returnToken = $this->token($municipality, "report-dispatch-return-{$dispatch->id}");
        $this->post(route('report-dispatches.return', $dispatch), [
            '_submission_token' => $returnToken,
            'result' => MunicipalReportDispatch::STATUS_ACKNOWLEDGED,
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'protocol_number' => 'CAM-2026-00981',
            'evidence' => UploadedFile::fake()->create('recebimento.pdf', 25, 'application/pdf'),
        ])->assertSessionHas('status');

        $this->assertSame(MunicipalReportDispatch::STATUS_ACKNOWLEDGED, $dispatch->fresh()->status);
        $this->assertNotNull($dispatch->fresh()->acknowledged_at);
        $this->get(route('report-dispatches.receipt', $dispatch))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'report_dispatch_receipt_downloaded']);
    }

    public function test_rejection_allows_linked_retry_and_events_are_immutable(): void
    {
        Storage::fake('local');
        [$manager, $municipality, $report] = $this->context(User::ROLE_MANAGER);
        $dispatch = $this->dispatch($report, $municipality, $manager, MunicipalReportDispatch::STATUS_SENT);
        $dispatch->update(['sent_at' => now()->subHour(), 'protocol_number' => 'CAM-001', 'official_document_number' => 'OF-01']);
        $returnToken = $this->token($municipality, "report-dispatch-return-{$dispatch->id}");
        $this->actingAs($manager)->post(route('report-dispatches.return', $dispatch), [
            '_submission_token' => $returnToken,
            'result' => MunicipalReportDispatch::STATUS_REJECTED,
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'message' => 'Documento devolvido para correção da identificação do exercício.',
            'evidence' => UploadedFile::fake()->create('devolucao.pdf', 20, 'application/pdf'),
        ])->assertSessionHas('status');

        $retryToken = $this->token($municipality, "report-dispatch-create-{$report->id}");
        $this->post(route('report-dispatches.store', $report), [
            '_submission_token' => $retryToken,
            ...$this->dispatchPayload(['retry_of_id' => $dispatch->id, 'due_at' => today()->addDays(3)->toDateString()]),
        ])->assertRedirect();
        $this->assertDatabaseHas('municipal_report_dispatches', [
            'municipal_governance_report_id' => $report->id,
            'retry_of_id' => $dispatch->id,
            'status' => MunicipalReportDispatch::STATUS_PREPARED,
        ]);

        $event = $dispatch->events()->where('type', 'rejected')->firstOrFail();
        $this->expectException(LogicException::class);
        $event->update(['message' => 'Tentativa de alteração.']);
    }

    public function test_manager_can_cancel_prepared_dispatch_but_editor_cannot(): void
    {
        [$manager, $municipality, $report] = $this->context(User::ROLE_MANAGER);
        $dispatch = $this->dispatch($report, $municipality, $manager);
        $editor = User::factory()->create();
        $municipality->users()->attach($editor->id, ['role' => User::ROLE_EDITOR]);

        $this->actingAs($editor)->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('report-dispatches.cancel', $dispatch))->assertForbidden();

        $cancelToken = $this->token($municipality, "report-dispatch-cancel-{$dispatch->id}");
        $this->actingAs($manager)->post(route('report-dispatches.cancel', $dispatch), [
            '_submission_token' => $cancelToken,
            'reason' => 'Destinatário selecionado incorretamente.',
        ])->assertSessionHas('status');
        $this->assertSame(MunicipalReportDispatch::STATUS_CANCELLED, $dispatch->fresh()->status);
    }

    public function test_deadline_processor_notifies_manager_once_per_cycle(): void
    {
        [$manager, $municipality, $report] = $this->context(User::ROLE_MANAGER);
        $dispatch = $this->dispatch($report, $municipality, $manager);
        $dispatch->update(['due_at' => today()]);

        $processor = app(MunicipalReportDispatchDeadlineProcessor::class);
        $first = $processor->process($municipality);
        $second = $processor->process($municipality);

        $this->assertSame(1, $first['dispatches']);
        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $this->assertDatabaseCount('municipal_report_dispatch_deliveries', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($dispatch->id, $manager->notifications()->firstOrFail()->data['dispatch_id']);
    }

    public function test_viewer_reads_own_dispatch_but_cannot_write_or_cross_tenants(): void
    {
        [$viewer, $municipality, $report] = $this->context(User::ROLE_VIEWER);
        $manager = User::factory()->create();
        $municipality->users()->attach($manager->id, ['role' => User::ROLE_MANAGER]);
        $dispatch = $this->dispatch($report, $municipality, $manager);
        [, $otherMunicipality, $otherReport] = $this->context(User::ROLE_MANAGER);
        $otherManager = $otherMunicipality->users()->firstOrFail();
        $otherDispatch = $this->dispatch($otherReport, $otherMunicipality, $otherManager);

        $this->actingAs($viewer)->withSession(['active_municipality_id' => $municipality->id]);
        $this->get(route('report-dispatches.index', $report))->assertOk();
        $this->get(route('report-dispatches.show', $dispatch))->assertOk();
        $this->post(route('report-dispatches.store', $report))->assertForbidden();
        $this->get(route('report-dispatches.show', $otherDispatch))->assertNotFound();
    }

    private function context(string $role): array
    {
        $user = User::factory()->create();
        $sequence = Municipality::count() + 1;
        $municipality = Municipality::factory()->create([
            'state' => 'SP',
            'ibge_code' => (string) (3540000 + $sequence),
            'cnpj' => sprintf('12345678%06d', $sequence),
        ]);
        $municipality->users()->attach($user->id, ['role' => $role]);
        $report = $this->report($municipality, $user);

        return [$user, $municipality, $report];
    }

    private function report(Municipality $municipality, User $user, string $status = MunicipalGovernanceReport::STATUS_ISSUED, int $month = 7): MunicipalGovernanceReport
    {
        return $municipality->governanceReports()->create([
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'issued_by' => $status === MunicipalGovernanceReport::STATUS_ISSUED ? $user->id : null,
            'reference' => (string) Str::uuid(),
            'fiscal_year' => 2026,
            'reference_month' => $month,
            'version' => 1,
            'status' => $status,
            'snapshot' => ['totals' => ['amendments' => 1]],
            'snapshot_sha256' => str_repeat('a', 64),
            'data_generated_at' => now(),
            'issued_at' => $status === MunicipalGovernanceReport::STATUS_ISSUED ? now() : null,
        ]);
    }

    private function dispatch(MunicipalGovernanceReport $report, Municipality $municipality, User $user, string $status = MunicipalReportDispatch::STATUS_PREPARED): MunicipalReportDispatch
    {
        $dispatch = $report->dispatches()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $user->id,
            'responsible_user_id' => $user->id,
            'reference' => (string) Str::uuid(),
            ...$this->dispatchPayload(['status' => $status]),
        ]);
        $dispatch->events()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $user->id,
            'type' => 'prepared',
            'occurred_at' => now(),
            'message' => 'Remessa preparada.',
        ]);

        return $dispatch;
    }

    private function dispatchPayload(array $overrides = []): array
    {
        return array_merge([
            'recipient_type' => 'chamber',
            'recipient_name' => 'Câmara Municipal de Teste',
            'recipient_unit' => 'Secretaria Legislativa',
            'delivery_method' => 'electronic_protocol',
            'legal_basis' => 'Fluxo municipal de remessa mensal',
            'due_at' => today()->addDays(5)->toDateString(),
            'status' => MunicipalReportDispatch::STATUS_PREPARED,
        ], $overrides);
    }

    private function token(Municipality $municipality, string $scope): string
    {
        $token = (string) Str::uuid();
        $tokens = session('form_submission_tokens', []);
        $tokens[$scope] = [$token => now()->timestamp];
        $this->withSession(['active_municipality_id' => $municipality->id, 'form_submission_tokens' => $tokens]);

        return $token;
    }
}
