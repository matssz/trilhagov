<?php

namespace Tests\Feature;

use App\Models\AmendmentDocument;
use App\Models\DocumentType;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class DocumentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_manager_can_configure_document_types(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$editor, $editorMunicipality] = $this->memberWithMunicipality(User::ROLE_EDITOR);
        DocumentType::createDefaultsFor($municipality);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('document-types.index'))
            ->assertOk()
            ->assertSee('Checklist documental')
            ->assertSee('Plano de trabalho');

        $this->actingAs($editor)
            ->withSession(['active_municipality_id' => $editorMunicipality->id])
            ->get(route('document-types.index'))
            ->assertForbidden();
    }

    public function test_manager_can_add_and_update_a_document_type_without_duplicate_submission(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $token = $this->sessionFor($municipality, 'document-type-create');

        $this->actingAs($manager)->post(route('document-types.store'), [
            '_submission_token' => $token,
            'name' => 'Parecer técnico',
            'description' => 'Análise emitida pelo setor responsável.',
            'sort_order' => 20,
            'is_required' => '1',
        ])->assertSessionHas('status');

        $type = DocumentType::firstOrFail();
        $this->assertTrue($type->is_required);
        $this->assertTrue($type->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_type_created',
            'auditable_type' => DocumentType::class,
            'auditable_id' => $type->id,
        ]);

        $this->post(route('document-types.store'), [
            '_submission_token' => $token,
            'name' => 'Documento repetido',
            'sort_order' => 30,
        ])->assertSessionHas('warning');
        $this->assertDatabaseCount('document_types', 1);

        $this->patch(route('document-types.update', $type), [
            'name' => 'Parecer técnico atualizado',
            'description' => '',
            'sort_order' => 25,
            'is_active' => '1',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('document_types', [
            'id' => $type->id,
            'name' => 'Parecer técnico atualizado',
            'is_required' => false,
            'is_active' => true,
            'sort_order' => 25,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'document_type_updated',
            'auditable_type' => DocumentType::class,
            'auditable_id' => $type->id,
        ]);
    }

    public function test_editor_can_upload_private_document_and_action_is_audited(): void
    {
        Storage::fake('local');
        [$editor, $municipality] = $this->memberWithMunicipality(User::ROLE_EDITOR);
        $amendment = $this->amendment($municipality, $editor);
        $type = $this->documentType($municipality);
        $token = $this->sessionFor($municipality, "amendment-document-upload-{$amendment->id}");

        $this->actingAs($editor)->post(route('emendas.documents.store', $amendment), [
            '_submission_token' => $token,
            'document_type_id' => $type->id,
            'document' => UploadedFile::fake()->create('plano-trabalho.pdf', 120, 'application/pdf'),
            'notes' => 'Versão aprovada internamente',
        ])->assertSessionHas('status');

        $document = AmendmentDocument::firstOrFail();
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertSame(1, $document->version);
        $this->assertSame($municipality->id, $document->municipality_id);
        $this->assertSame($editor->name, $document->uploader_name);
        $this->assertDatabaseHas('audit_logs', [
            'municipality_id' => $municipality->id,
            'user_id' => $editor->id,
            'action' => 'document_uploaded',
            'auditable_type' => ParliamentaryAmendment::class,
            'auditable_id' => $amendment->id,
        ]);
    }

    public function test_new_upload_creates_version_and_repeated_request_is_ignored(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager);
        $type = $this->documentType($municipality);

        $firstToken = $this->sessionFor($municipality, "amendment-document-upload-{$amendment->id}");
        $this->actingAs($manager)->post(route('emendas.documents.store', $amendment), [
            '_submission_token' => $firstToken,
            'document_type_id' => $type->id,
            'document' => UploadedFile::fake()->create('plano-v1.pdf', 80, 'application/pdf'),
        ]);

        $this->post(route('emendas.documents.store', $amendment), [
            '_submission_token' => $firstToken,
            'document_type_id' => $type->id,
            'document' => UploadedFile::fake()->create('nao-deve-entrar.pdf', 80, 'application/pdf'),
        ])->assertSessionHas('warning');

        $secondToken = $this->sessionFor($municipality, "amendment-document-upload-{$amendment->id}");
        $this->post(route('emendas.documents.store', $amendment), [
            '_submission_token' => $secondToken,
            'document_type_id' => $type->id,
            'document' => UploadedFile::fake()->create('plano-v2.pdf', 90, 'application/pdf'),
        ]);

        $this->assertDatabaseCount('amendment_documents', 2);
        $this->assertSame([1, 2], AmendmentDocument::query()->orderBy('version')->pluck('version')->all());
        $this->assertDatabaseMissing('amendment_documents', ['original_name' => 'nao-deve-entrar.pdf']);
    }

    public function test_viewer_can_download_own_municipality_document_but_cannot_upload(): void
    {
        Storage::fake('local');
        [$viewer, $municipality] = $this->memberWithMunicipality(User::ROLE_VIEWER);
        $amendment = $this->amendment($municipality, $viewer);
        $type = $this->documentType($municipality);
        $document = $this->storedDocument($municipality, $amendment, $type, $viewer);

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.documents.download', [$amendment, $document]))
            ->assertOk()
            ->assertDownload($document->original_name);

        $this->post(route('emendas.documents.store', $amendment), [])->assertForbidden();
    }

    public function test_document_from_another_municipality_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        [$user, $municipality] = $this->memberWithMunicipality(User::ROLE_VIEWER);
        [$otherUser, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $otherAmendment = $this->amendment($otherMunicipality, $otherUser);
        $otherType = $this->documentType($otherMunicipality);
        $otherDocument = $this->storedDocument($otherMunicipality, $otherAmendment, $otherType, $otherUser);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.documents.download', [$otherAmendment, $otherDocument]))
            ->assertNotFound();
    }

    public function test_upload_rejects_unsafe_file_and_inactive_type(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager);
        $type = $this->documentType($municipality);
        $token = $this->sessionFor($municipality, "amendment-document-upload-{$amendment->id}");

        $this->actingAs($manager)->post(route('emendas.documents.store', $amendment), [
            '_submission_token' => $token,
            'document_type_id' => $type->id,
            'document' => UploadedFile::fake()->create('programa.exe', 20, 'application/x-msdownload'),
        ])->assertSessionHasErrors('document');

        $this->post(route('emendas.documents.store', $amendment), [
            '_submission_token' => $token,
            'document_type_id' => $type->id,
            'document' => UploadedFile::fake()->create('arquivo-grande.pdf', 11000, 'application/pdf'),
        ])->assertSessionHasErrors('document');

        $type->update(['is_active' => false]);
        $this->post(route('emendas.documents.store', $amendment), [
            '_submission_token' => $token,
            'document_type_id' => $type->id,
            'document' => UploadedFile::fake()->create('arquivo.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrors('document_type_id');

        $this->assertDatabaseCount('amendment_documents', 0);
    }

    public function test_checklist_shows_pending_and_delivered_documents(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager);
        $pendingType = $this->documentType($municipality, 'Plano de trabalho', true);
        $deliveredType = $this->documentType($municipality, 'Extrato bancário');
        $this->storedDocument($municipality, $amendment, $deliveredType, $manager);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.show', $amendment))
            ->assertOk()
            ->assertSee('1 de 2 tipos com documento')
            ->assertSee($pendingType->name)
            ->assertSee('Pendente')
            ->assertSee($deliveredType->name)
            ->assertSee('Enviado');
    }

    public function test_uploaded_document_metadata_is_immutable(): void
    {
        Storage::fake('local');
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager);
        $document = $this->storedDocument(
            $municipality,
            $amendment,
            $this->documentType($municipality),
            $manager,
        );

        $this->expectException(LogicException::class);
        $document->update(['original_name' => 'arquivo-adulterado.pdf']);
    }

    /** @return array{User, Municipality} */
    private function memberWithMunicipality(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }

    private function amendment(Municipality $municipality, User $user): ParliamentaryAmendment
    {
        return ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($user, 'creator')
            ->create();
    }

    private function documentType(
        Municipality $municipality,
        string $name = 'Plano de trabalho',
        bool $required = false,
    ): DocumentType {
        return $municipality->documentTypes()->create([
            'name' => $name,
            'is_required' => $required,
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

    private function storedDocument(
        Municipality $municipality,
        ParliamentaryAmendment $amendment,
        DocumentType $type,
        User $uploader,
    ): AmendmentDocument {
        $path = "documents/{$municipality->id}/{$amendment->id}/".Str::uuid().'.pdf';
        Storage::disk('local')->put($path, 'conteudo de teste');

        return $amendment->documents()->create([
            'municipality_id' => $municipality->id,
            'document_type_id' => $type->id,
            'uploaded_by' => $uploader->id,
            'uploader_name' => $uploader->name,
            'original_name' => 'documento.pdf',
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => 17,
            'version' => 1,
        ]);
    }

    private function sessionFor(Municipality $municipality, string $scope): string
    {
        $token = (string) Str::uuid();
        $this->withSession([
            'active_municipality_id' => $municipality->id,
            'form_submission_tokens' => [
                $scope => [$token => now()->timestamp],
            ],
        ]);

        return $token;
    }
}
