<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\MunicipalOfficialDocument;
use App\Models\ParliamentaryAmendment;
use App\Models\TechnicalImpediment;
use App\Models\User;
use App\Services\MunicipalOfficialDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class MunicipalOfficialDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_installs_versioned_municipal_templates(): void
    {
        [$manager, $municipality] = $this->context(User::ROLE_MANAGER);
        $token = $this->token($municipality, 'official-template-install');

        $this->actingAs($manager)->post(route('official-document-templates.install'), [
            '_submission_token' => $token,
        ])->assertSessionHas('status');

        $this->assertDatabaseCount('municipal_document_templates', 6);
        $this->assertSame(6, $municipality->documentTemplates()->where('is_active', true)->count());
        $this->get(route('official-documents.index'))
            ->assertOk()
            ->assertSee('Ofício de impedimento')
            ->assertSee('Termo de encaminhamento');
    }

    public function test_editor_generates_draft_from_impediment_without_retyping_context(): void
    {
        [$editor, $municipality] = $this->context(User::ROLE_EDITOR);
        $template = app(MunicipalOfficialDocumentService::class)
            ->installDefaults($municipality, $editor)
            ->firstWhere('document_type', 'impediment_letter');
        $amendment = ParliamentaryAmendment::factory()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $editor->id,
            'reference' => 'EM-MUN-0042',
            'object' => 'Reforma da unidade básica de saúde do Bairro Norte',
            'administrative_process' => 'PA-88/2026',
        ]);
        $impediment = $amendment->technicalImpediments()->create([
            'municipality_id' => $municipality->id,
            'created_by' => $editor->id,
            'category' => 'engineering',
            'nature' => TechnicalImpediment::NATURE_TEMPORARY,
            'status' => TechnicalImpediment::STATUS_IDENTIFIED,
            'title' => 'Projeto básico incompleto',
            'description' => 'O memorial descritivo não apresenta as especificações da cobertura.',
            'impact' => 'Impede a conclusão do orçamento estimado.',
            'identified_at' => today(),
            'resolution_due_at' => today()->addDays(10),
        ]);
        $token = $this->token($municipality, 'official-document-create');

        $response = $this->actingAs($editor)->post(route('official-documents.store'), [
            '_submission_token' => $token,
            'municipal_document_template_id' => $template->id,
            'parliamentary_amendment_id' => $amendment->id,
            'technical_impediment_id' => $impediment->id,
            'fiscal_year' => 2026,
            'recipient_name' => 'João da Silva',
            'recipient_role' => 'Vereador autor',
            'recipient_entity' => 'Câmara Municipal',
            'recipient_email' => 'gabinete@camara.sp.gov.br',
            'response_due_at' => today()->addDays(10)->toDateString(),
            'legal_basis' => 'LDO municipal e fluxo formal de saneamento.',
        ]);

        $document = MunicipalOfficialDocument::firstOrFail();
        $response->assertRedirect(route('official-documents.show', $document));
        $this->assertSame(MunicipalOfficialDocument::STATUS_DRAFT, $document->status);
        $this->assertStringContainsString('Projeto básico incompleto', $document->body);
        $this->assertStringContainsString('EM-MUN-0042', $document->subject);
        $this->assertSame($impediment->id, $document->technical_impediment_id);
        $this->assertDatabaseHas('municipal_official_document_events', ['type' => 'drafted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'official_document_drafted']);
    }

    public function test_manager_issues_immutable_numbered_document_and_protocols_receipt(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->context(User::ROLE_MANAGER);
        $document = $this->draft($manager, $municipality);
        $issueToken = $this->token($municipality, "official-document-issue-{$document->id}");

        $this->actingAs($manager)->post(route('official-documents.issue', $document), [
            '_submission_token' => $issueToken,
            'confirm_content' => '1',
        ])->assertSessionHas('status');

        $document->refresh();
        $this->assertSame('NOT-00001/2026', $document->official_number);
        $this->assertSame(64, strlen($document->snapshot_sha256));
        $this->assertSame(MunicipalOfficialDocument::STATUS_ISSUED, $document->status);
        $this->expectException(LogicException::class);
        $document->update(['subject' => 'Tentativa de alterar documento emitido']);
    }

    public function test_protocol_and_return_preserve_hashed_evidence_and_allow_revision(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->context(User::ROLE_MANAGER);
        $document = $this->draft($manager, $municipality);
        $issueToken = $this->token($municipality, "official-document-issue-{$document->id}");
        $this->actingAs($manager)->post(route('official-documents.issue', $document), [
            '_submission_token' => $issueToken, 'confirm_content' => '1',
        ]);
        $document->refresh();

        $sendToken = $this->token($municipality, "official-document-send-{$document->id}");
        $this->post(route('official-documents.send', $document), [
            '_submission_token' => $sendToken,
            'delivery_method' => 'electronic_protocol',
            'protocol_number' => 'CAM-2026-00081',
            'sent_at' => now()->format('Y-m-d H:i:s'),
            'evidence' => UploadedFile::fake()->create('protocolo.pdf', 35, 'application/pdf'),
        ])->assertSessionHas('status');

        $document->refresh();
        $event = $document->events()->where('type', 'sent')->firstOrFail();
        $this->assertSame(64, strlen($event->evidence_sha256));
        Storage::assertExists($event->evidence_storage_path);
        $this->get(route('official-documents.evidence', [$document, $event]))->assertOk();

        $returnToken = $this->token($municipality, "official-document-return-{$document->id}");
        $this->post(route('official-documents.return', $document), [
            '_submission_token' => $returnToken,
            'result' => MunicipalOfficialDocument::STATUS_REJECTED,
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'message' => 'Destinatário solicitou correção do cargo indicado.',
            'evidence' => UploadedFile::fake()->create('devolucao.pdf', 20, 'application/pdf'),
        ])->assertSessionHas('status');

        $document->refresh();
        $revisionToken = $this->token($municipality, "official-document-revise-{$document->id}");
        $response = $this->post(route('official-documents.revise', $document), ['_submission_token' => $revisionToken]);
        $revision = MunicipalOfficialDocument::where('supersedes_id', $document->id)->firstOrFail();
        $response->assertRedirect(route('official-documents.show', $revision));
        $this->assertSame(MunicipalOfficialDocument::STATUS_DRAFT, $revision->status);
        $this->assertSame(2, $revision->version);
        $this->assertSame(MunicipalOfficialDocument::STATUS_REJECTED, $document->fresh()->status);
        $this->get(route('official-documents.pdf', $document))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_viewer_reads_only_active_municipality_and_cannot_write(): void
    {
        [$viewer, $municipality] = $this->context(User::ROLE_VIEWER);
        $manager = User::factory()->create();
        $municipality->users()->attach($manager->id, ['role' => User::ROLE_MANAGER]);
        $document = $this->draft($manager, $municipality);
        [, $otherMunicipality] = $this->context(User::ROLE_MANAGER);
        $otherManager = $otherMunicipality->users()->firstOrFail();
        $otherDocument = $this->draft($otherManager, $otherMunicipality);

        $this->actingAs($viewer)->withSession(['active_municipality_id' => $municipality->id]);
        $this->get(route('official-documents.index'))->assertOk();
        $this->get(route('official-documents.show', $document))->assertOk();
        $this->get(route('official-documents.show', $otherDocument))->assertNotFound();
        $this->post(route('official-documents.store'))->assertForbidden();
    }

    private function context(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user->id, ['role' => $role]);
        $this->actingAs($user)->withSession(['active_municipality_id' => $municipality->id]);

        return [$user, $municipality];
    }

    private function draft(User $user, Municipality $municipality): MunicipalOfficialDocument
    {
        $template = app(MunicipalOfficialDocumentService::class)
            ->installDefaults($municipality, $user)
            ->firstWhere('document_type', 'notification');

        return $municipality->officialDocuments()->create([
            'municipal_document_template_id' => $template->id,
            'created_by' => $user->id,
            'reference' => (string) Str::uuid(),
            'fiscal_year' => 2026,
            'version' => 1,
            'document_type' => 'notification',
            'status' => MunicipalOfficialDocument::STATUS_DRAFT,
            'recipient_name' => 'Presidente da Câmara',
            'recipient_role' => 'Presidente',
            'recipient_entity' => 'Câmara Municipal',
            'subject' => 'Notificação sobre a Emenda EM-01',
            'body' => 'Conteúdo formal da notificação municipal com contexto, fundamento e prazo para resposta.',
            'response_due_at' => today()->addDays(10),
        ]);
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
