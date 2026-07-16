<?php

namespace Tests\Feature;

use App\Models\AccountabilityDiligence;
use App\Models\AccountabilityProcess;
use App\Models\AccountabilityRequirement;
use App\Models\AmendmentDocument;
use App\Models\ExecutionStage;
use App\Models\FinancialCommitment;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Notifications\IntegrityAlertNotification;
use App\Services\IntegrityAlertProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class AccountabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_start_accountability_once_with_default_checklist(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, [
            'responsible_user_id' => $manager->id,
            'accountability_deadline' => '2026-12-20',
        ]);
        $token = $this->sessionFor($municipality, "accountability-create-{$amendment->id}");
        $payload = ['_submission_token' => $token];

        $this->actingAs($manager)
            ->post(route('emendas.accountability.store', $amendment), $payload)
            ->assertSessionHas('status');
        $this->post(route('emendas.accountability.store', $amendment), $payload)
            ->assertSessionHas('warning');

        $process = AccountabilityProcess::firstOrFail();
        $this->assertDatabaseCount('accountability_processes', 1);
        $this->assertDatabaseCount('accountability_requirements', 5);
        $this->assertSame($manager->id, $process->responsible_user_id);
        $this->assertSame('2026-12-20', $process->due_at->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'accountability_created']);
    }

    public function test_viewer_can_consult_but_cannot_change_accountability(): void
    {
        [$viewer, $municipality] = $this->memberWithMunicipality(User::ROLE_VIEWER);
        $amendment = $this->amendment($municipality, $viewer);

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.accountability', $amendment))
            ->assertOk()
            ->assertDontSee('Iniciar processo');
        $this->post(route('emendas.accountability.store', $amendment), [])->assertForbidden();
    }

    public function test_checklist_can_link_own_document_and_requires_reason_for_not_applicable(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager);
        $process = $this->process($municipality, $amendment, $manager);
        $requirement = $this->requirement($process, $manager);
        $document = $this->document($municipality, $amendment, $manager);
        $token = $this->sessionFor($municipality, "accountability-requirement-update-{$requirement->id}");

        $this->actingAs($manager)->patch(route('emendas.accountability.requirements.update', [$amendment, $requirement]), [
            '_submission_token' => $token,
            'status' => AccountabilityRequirement::STATUS_COMPLETED,
            'amendment_document_id' => $document->id,
            'notes' => 'Documento conferido.',
        ])->assertSessionHas('status');

        $this->assertSame($document->id, $requirement->fresh()->amendment_document_id);
        $this->assertNotNull($requirement->fresh()->completed_at);

        [$otherUser, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $otherAmendment = $this->amendment($otherMunicipality, $otherUser);
        $otherDocument = $this->document($otherMunicipality, $otherAmendment, $otherUser);
        $crossMunicipalityToken = $this->sessionFor($municipality, "accountability-requirement-update-{$requirement->id}");
        $this->actingAs($manager)->patch(route('emendas.accountability.requirements.update', [$amendment, $requirement]), [
            '_submission_token' => $crossMunicipalityToken,
            'status' => AccountabilityRequirement::STATUS_COMPLETED,
            'amendment_document_id' => $otherDocument->id,
        ])->assertSessionHasErrors('amendment_document_id');
        $this->assertSame($document->id, $requirement->fresh()->amendment_document_id);

        $second = $this->requirement($process, $manager, 'Item condicional');
        $secondToken = $this->sessionFor($municipality, "accountability-requirement-update-{$second->id}");
        $this->patch(route('emendas.accountability.requirements.update', [$amendment, $second]), [
            '_submission_token' => $secondToken,
            'status' => AccountabilityRequirement::STATUS_NOT_APPLICABLE,
            'notes' => '',
        ])->assertSessionHasErrors('notes');
    }

    public function test_submission_is_blocked_while_execution_and_reconciliation_are_pending(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, ['received_amount' => 100000]);
        $process = $this->process($municipality, $amendment, $manager);
        $this->requirement($process, $manager);
        $token = $this->sessionFor($municipality, "accountability-update-{$process->id}");

        $this->actingAs($manager)->patch(route('emendas.accountability.update', $amendment), [
            ...$this->processPayload($token),
            'status' => AccountabilityProcess::STATUS_SUBMITTED,
            'submitted_at' => '2026-07-16',
            'protocol_number' => 'PROTOCOLO-001',
        ])->assertSessionHasErrors('status');

        $this->assertSame(AccountabilityProcess::STATUS_PREPARING, $process->fresh()->status);
    }

    public function test_ready_process_can_be_submitted_and_approved(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, [
            'received_amount' => 100000,
            'responsible_user_id' => $manager->id,
        ]);
        $this->completeExecution($municipality, $amendment, $manager, 100000);
        $process = $this->process($municipality, $amendment, $manager);
        $this->completeRequiredChecklist($process, $manager);
        $submitToken = $this->sessionFor($municipality, "accountability-update-{$process->id}");

        $this->actingAs($manager)->patch(route('emendas.accountability.update', $amendment), [
            ...$this->processPayload($submitToken),
            'status' => AccountabilityProcess::STATUS_SUBMITTED,
            'submitted_at' => '2026-07-16',
            'protocol_number' => 'PROTOCOLO-001',
        ])->assertSessionHas('status');

        $this->assertSame(AccountabilityProcess::STATUS_SUBMITTED, $process->fresh()->status);
        $this->assertSame(ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING, $amendment->fresh()->status);

        $approveToken = $this->sessionFor($municipality, "accountability-update-{$process->id}");
        $this->patch(route('emendas.accountability.update', $amendment), [
            ...$this->processPayload($approveToken),
            'status' => AccountabilityProcess::STATUS_APPROVED,
            'submitted_at' => '2026-07-16',
            'protocol_number' => 'PROTOCOLO-001',
            'approved_at' => '2026-07-20',
        ])->assertSessionHas('status');

        $this->assertSame(ParliamentaryAmendment::STATUS_COMPLETED, $amendment->fresh()->status);
        $this->assertSame('2026-07-20', $amendment->fresh()->accountability_completed_at->toDateString());
    }

    public function test_diligence_deadline_alert_reaches_assigned_person_and_response_is_audited(): void
    {
        Notification::fake();
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $editor = User::factory()->create();
        $municipality->users()->attach($editor, ['role' => User::ROLE_EDITOR]);
        $amendment = $this->amendment($municipality, $manager, ['responsible_user_id' => $manager->id]);
        $process = $this->process($municipality, $amendment, $manager);
        $diligence = $process->diligences()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'assigned_user_id' => $editor->id,
            'created_by' => $manager->id,
            'title' => 'Complementar extrato bancário',
            'description' => 'Enviar o período faltante.',
            'received_at' => today()->subDays(10),
            'due_at' => today()->subDay(),
            'status' => AccountabilityDiligence::STATUS_OPEN,
        ]);

        app(IntegrityAlertProcessor::class)->process($municipality);

        $this->assertDatabaseHas('integrity_alerts', [
            'alert_key' => "deadline:diligence:{$diligence->id}",
            'assigned_user_id' => $editor->id,
        ]);
        Notification::assertSentTo($editor, IntegrityAlertNotification::class);

        $token = $this->sessionFor($municipality, "accountability-diligence-update-{$diligence->id}");
        $this->actingAs($manager)->patch(route('emendas.accountability.diligences.update', [$amendment, $diligence]), [
            '_submission_token' => $token,
            'status' => AccountabilityDiligence::STATUS_RESPONDED,
            'response_notes' => 'Extrato complementar anexado.',
            'response_protocol' => 'RESPOSTA-009',
        ])->assertSessionHas('status');

        $this->assertNotNull($diligence->fresh()->responded_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accountability_diligence_updated']);
    }

    public function test_pdf_and_zip_dossier_are_private_and_downloadable(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager);
        $this->process($municipality, $amendment, $manager);
        $document = $this->document($municipality, $amendment, $manager);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.accountability.dossier.pdf', $amendment))
            ->assertOk()
            ->assertDownload('dossie-prestacao-'.Str::slug($amendment->reference).'.pdf');

        $zipResponse = $this->get(route('emendas.accountability.dossier.package', $amendment));
        $zipResponse->assertOk()->assertDownload('dossie-prestacao-'.Str::slug($amendment->reference).'.zip');
        $path = $zipResponse->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertNotFalse($zip->locateName('MANIFESTO.txt'));
        $this->assertTrue(collect(range(0, $zip->numFiles - 1))->contains(
            fn (int $index) => str_contains((string) $zip->getNameIndex($index), $document->original_name),
        ));
        $zip->close();

        [$otherUser, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $this->actingAs($otherUser)
            ->withSession(['active_municipality_id' => $otherMunicipality->id])
            ->get(route('emendas.accountability.dossier.pdf', $amendment))
            ->assertNotFound();
    }

    private function completeExecution(Municipality $municipality, ParliamentaryAmendment $amendment, User $user, float $amount): void
    {
        $stage = $amendment->executionStages()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $user->id,
            'title' => 'Entrega concluída',
            'status' => ExecutionStage::STATUS_COMPLETED,
            'progress_percentage' => 100,
            'completed_at' => today(),
            'sort_order' => 10,
        ]);
        $commitment = $amendment->financialCommitments()->create([
            'municipality_id' => $municipality->id,
            'execution_stage_id' => $stage->id,
            'created_by' => $user->id,
            'commitment_number' => 'NE-READY',
            'supplier_name' => 'Fornecedor Municipal',
            'procurement_process' => 'PROC-READY',
            'object_description' => 'Execução do objeto.',
            'committed_amount' => $amount,
            'committed_at' => today(),
            'status' => FinancialCommitment::STATUS_ACTIVE,
        ]);
        $commitment->payments()->create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'created_by' => $user->id,
            'payment_reference' => 'OB-READY',
            'amount' => $amount,
            'paid_at' => today(),
        ]);
        $this->document($municipality, $amendment, $user, $stage);
    }

    private function completeRequiredChecklist(AccountabilityProcess $process, User $user): void
    {
        foreach (range(1, 2) as $index) {
            $requirement = $this->requirement($process, $user, "Obrigatório {$index}");
            $requirement->update([
                'status' => AccountabilityRequirement::STATUS_COMPLETED,
                'completed_by' => $user->id,
                'completed_at' => now(),
            ]);
        }
    }

    private function process(Municipality $municipality, ParliamentaryAmendment $amendment, User $user): AccountabilityProcess
    {
        return $amendment->accountabilityProcess()->create([
            'municipality_id' => $municipality->id,
            'responsible_user_id' => $user->id,
            'created_by' => $user->id,
            'status' => AccountabilityProcess::STATUS_PREPARING,
            'due_at' => '2026-12-20',
            'returned_amount' => 0,
        ]);
    }

    private function requirement(AccountabilityProcess $process, User $user, string $title = 'Relatório final'): AccountabilityRequirement
    {
        return $process->requirements()->create([
            'municipality_id' => $process->municipality_id,
            'parliamentary_amendment_id' => $process->parliamentary_amendment_id,
            'created_by' => $user->id,
            'category' => 'document',
            'title' => $title,
            'is_required' => true,
            'status' => AccountabilityRequirement::STATUS_PENDING,
            'sort_order' => 10,
        ]);
    }

    private function document(Municipality $municipality, ParliamentaryAmendment $amendment, User $user, ?ExecutionStage $stage = null): AmendmentDocument
    {
        $type = $municipality->documentTypes()->firstOrCreate([
            'name' => 'Relatório de execução',
        ], [
            'is_required' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $path = "documents/{$municipality->id}/{$amendment->id}/relatorio.pdf";
        Storage::disk('local')->put($path, 'conteudo do documento');

        return $amendment->documents()->create([
            'municipality_id' => $municipality->id,
            'document_type_id' => $type->id,
            'execution_stage_id' => $stage?->id,
            'uploaded_by' => $user->id,
            'uploader_name' => $user->name,
            'original_name' => 'relatorio-execucao.pdf',
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => 20,
            'version' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function processPayload(string $token): array
    {
        return [
            '_submission_token' => $token,
            'status' => AccountabilityProcess::STATUS_PREPARING,
            'due_at' => '2026-12-20',
            'returned_amount' => 0,
        ];
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
