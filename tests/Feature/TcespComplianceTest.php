<?php

namespace Tests\Feature;

use App\Models\AmendmentComplianceReview;
use App\Models\AmendmentDocument;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use App\Services\TcespComplianceFramework;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TcespComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_open_versioned_matrix_for_sp_municipal_amendment(): void
    {
        [$user, $municipality, $amendment] = $this->context();

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.compliance', $amendment))
            ->assertOk()
            ->assertSee('Matriz de conformidade')
            ->assertSee('Manual TCESP')
            ->assertSee('NORM-01')
            ->assertSee('TRA-03')
            ->assertSee('Instrumento de apoio à conferência');
    }

    public function test_matrix_is_not_applied_to_federal_or_non_sp_amendments(): void
    {
        [$user, $municipality, $amendment] = $this->context([
            'government_sphere' => 'federal',
        ]);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.compliance', $amendment))
            ->assertNotFound();

        $amendment->update(['government_sphere' => 'municipal']);
        $municipality->update(['state' => 'MG']);

        $this->get(route('emendas.compliance', $amendment))->assertNotFound();

        $municipality->update(['state' => 'SP', 'ibge_code' => '3550308']);

        $this->get(route('emendas.compliance', $amendment))->assertNotFound();
    }

    public function test_matrix_shows_guided_remediation_for_open_essential_items(): void
    {
        [$user, $municipality, $amendment] = $this->context();

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.compliance', $amendment))
            ->assertOk()
            ->assertSee('Saneamento guiado')
            ->assertSee('Prontidao do pacote TCESP')
            ->assertSee('Revisao recomendada')
            ->assertSee('Essenciais abertos')
            ->assertSee('Resolver agora')
            ->assertSee('Dossie TCESP')
            ->assertSee('Pacote TCESP')
            ->assertSee('Evidencias que costumam resolver este item')
            ->assertSee('Lei Organica atualizada')
            ->assertSee('essencial(is) em aberto');
    }

    public function test_package_readiness_warns_when_essential_item_has_no_document(): void
    {
        [$user, $municipality, $amendment] = $this->context();
        $amendment->complianceReviews()->create([
            'municipality_id' => $municipality->id,
            'framework_version' => TcespComplianceFramework::VERSION,
            'rule_code' => 'NORM-01',
            'status' => AmendmentComplianceReview::STATUS_COMPLIANT,
            'evidence_notes' => 'Lei Organica conferida no processo fisico.',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.compliance', $amendment))
            ->assertOk()
            ->assertSee('Revisao recomendada')
            ->assertSee('registre ou vincule um documento de suporte.')
            ->assertSee('So com justificativa');
    }

    public function test_package_readiness_is_clear_when_all_essential_items_are_documented_or_not_applicable(): void
    {
        Storage::fake('local');
        [$user, $municipality, $amendment] = $this->context();
        $documentType = $municipality->documentTypes()->create([
            'created_by' => $user->id,
            'name' => 'Evidencia TCESP',
            'is_active' => true,
        ]);
        Storage::put('documents/tcesp/evidencia.pdf', 'conteudo');
        $document = $amendment->documents()->create([
            'municipality_id' => $municipality->id,
            'document_type_id' => $documentType->id,
            'uploaded_by' => $user->id,
            'uploader_name' => $user->name,
            'original_name' => 'evidencia.pdf',
            'storage_path' => 'documents/tcesp/evidencia.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'version' => 1,
        ]);

        foreach ((new TcespComplianceFramework)->rules() as $rule) {
            $amendment->complianceReviews()->create([
                'municipality_id' => $municipality->id,
                'framework_version' => TcespComplianceFramework::VERSION,
                'rule_code' => $rule['code'],
                'status' => $rule['critical']
                    ? AmendmentComplianceReview::STATUS_COMPLIANT
                    : AmendmentComplianceReview::STATUS_NOT_APPLICABLE,
                'evidence_notes' => $rule['critical'] ? 'Evidencia vinculada ao pacote.' : 'Nao se aplica ao caso.',
                'amendment_document_id' => $rule['critical'] ? $document->id : null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.compliance', $amendment))
            ->assertOk()
            ->assertSee('Pronto para conferencia')
            ->assertSee('Pacote sem pendencia essencial aparente')
            ->assertSee('Baixe o pacote TCESP');
    }

    public function test_user_can_download_tcesp_dossier_for_municipal_amendment(): void
    {
        [$user, $municipality, $amendment] = $this->context();
        $amendment->complianceReviews()->create([
            'municipality_id' => $municipality->id,
            'framework_version' => TcespComplianceFramework::VERSION,
            'rule_code' => 'NORM-01',
            'status' => AmendmentComplianceReview::STATUS_COMPLIANT,
            'evidence_notes' => 'Lei Organica e LDO conferidas.',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.compliance.dossier.pdf', $amendment));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('dossie-tcesp-', (string) $response->headers->get('content-disposition'));
    }

    public function test_user_can_download_tcesp_package_with_pdf_and_documents(): void
    {
        Storage::fake('local');
        [$user, $municipality, $amendment] = $this->context();
        $documentType = $municipality->documentTypes()->create([
            'created_by' => $user->id,
            'name' => 'Parecer tecnico',
            'is_active' => true,
        ]);
        Storage::put('documents/tcesp/parecer.pdf', 'conteudo do parecer');
        $amendment->documents()->create([
            'municipality_id' => $municipality->id,
            'document_type_id' => $documentType->id,
            'uploaded_by' => $user->id,
            'uploader_name' => $user->name,
            'original_name' => 'parecer.pdf',
            'storage_path' => 'documents/tcesp/parecer.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 18,
            'version' => 1,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.compliance.dossier.package', $amendment));

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('content-type'));
        $this->assertStringContainsString('dossie-tcesp-', (string) $response->headers->get('content-disposition'));
    }

    public function test_editor_can_record_compliance_with_evidence_and_audit(): void
    {
        [$user, $municipality, $amendment] = $this->context(role: User::ROLE_EDITOR);
        $token = $this->reviewToken($amendment, 'ORC-01');

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('emendas.compliance.update', [$amendment, 'ORC-01']), [
                '_submission_token' => $token,
                '_rule_code' => 'ORC-01',
                'status' => AmendmentComplianceReview::STATUS_COMPLIANT,
                'evidence_notes' => 'Objeto delimitado no parecer técnico juntado ao processo 14/2026.',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Revisão de conformidade salva.');

        $this->assertDatabaseHas('amendment_compliance_reviews', [
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $amendment->id,
            'framework_version' => TcespComplianceFramework::VERSION,
            'rule_code' => 'ORC-01',
            'status' => AmendmentComplianceReview::STATUS_COMPLIANT,
            'reviewed_by' => $user->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'municipality_id' => $municipality->id,
            'auditable_type' => ParliamentaryAmendment::class,
            'auditable_id' => $amendment->id,
            'action' => 'compliance_review_updated',
        ]);
    }

    public function test_compliant_item_requires_note_or_document(): void
    {
        [$user, $municipality, $amendment] = $this->context();
        $token = $this->reviewToken($amendment, 'VIA-01');

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->from(route('emendas.compliance', $amendment))
            ->patch(route('emendas.compliance.update', [$amendment, 'VIA-01']), [
                '_submission_token' => $token,
                '_rule_code' => 'VIA-01',
                'status' => AmendmentComplianceReview::STATUS_COMPLIANT,
            ])
            ->assertSessionHasErrors([
                'evidence_notes' => 'Para marcar como atendido, descreva a evidência ou vincule um documento.',
            ]);

        $this->assertDatabaseMissing('amendment_compliance_reviews', [
            'parliamentary_amendment_id' => $amendment->id,
            'rule_code' => 'VIA-01',
        ]);
    }

    public function test_non_compliant_and_not_applicable_items_require_justification(): void
    {
        [$user, $municipality, $amendment] = $this->context();

        foreach ([AmendmentComplianceReview::STATUS_NON_COMPLIANT, AmendmentComplianceReview::STATUS_NOT_APPLICABLE] as $index => $status) {
            $code = $index === 0 ? 'IMP-02' : 'BEN-02';
            $token = $this->reviewToken($amendment, $code);

            $this->actingAs($user)
                ->withSession(['active_municipality_id' => $municipality->id])
                ->from(route('emendas.compliance', $amendment))
                ->patch(route('emendas.compliance.update', [$amendment, $code]), [
                    '_submission_token' => $token,
                    '_rule_code' => $code,
                    'status' => $status,
                ])
                ->assertSessionHasErrors([
                    'evidence_notes' => 'Descreva a constatação ou a justificativa para esta situação.',
                ]);
        }
    }

    public function test_evidence_document_must_belong_to_same_amendment(): void
    {
        [$user, $municipality, $amendment] = $this->context();
        $otherAmendment = ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($user, 'creator')
            ->create(['government_sphere' => 'municipal']);
        $type = $municipality->documentTypes()->create([
            'created_by' => $user->id,
            'name' => 'Parecer técnico',
            'is_active' => true,
        ]);
        $otherDocument = AmendmentDocument::create([
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $otherAmendment->id,
            'document_type_id' => $type->id,
            'uploaded_by' => $user->id,
            'uploader_name' => $user->name,
            'original_name' => 'parecer.pdf',
            'storage_path' => 'tests/'.Str::uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'version' => 1,
        ]);
        $token = $this->reviewToken($amendment, 'IMP-01');

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->from(route('emendas.compliance', $amendment))
            ->patch(route('emendas.compliance.update', [$amendment, 'IMP-01']), [
                '_submission_token' => $token,
                '_rule_code' => 'IMP-01',
                'status' => AmendmentComplianceReview::STATUS_COMPLIANT,
                'amendment_document_id' => $otherDocument->id,
            ])
            ->assertSessionHasErrors([
                'amendment_document_id' => 'Selecione um documento pertencente a esta emenda.',
            ]);
    }

    public function test_viewer_can_consult_but_cannot_change_matrix(): void
    {
        [$user, $municipality, $amendment] = $this->context(role: User::ROLE_VIEWER);

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.compliance', $amendment))
            ->assertOk()
            ->assertDontSee('Salvar revisão');

        $this->patch(route('emendas.compliance.update', [$amendment, 'NORM-01']), [
            'status' => AmendmentComplianceReview::STATUS_COMPLIANT,
        ])->assertForbidden();
    }

    public function test_repeated_review_request_is_processed_only_once(): void
    {
        [$user, $municipality, $amendment] = $this->context();
        $token = $this->reviewToken($amendment, 'TRA-01');
        $payload = [
            '_submission_token' => $token,
            '_rule_code' => 'TRA-01',
            'status' => AmendmentComplianceReview::STATUS_COMPLIANT,
            'evidence_notes' => 'Publicação conferida no portal municipal.',
        ];

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->patch(route('emendas.compliance.update', [$amendment, 'TRA-01']), $payload);

        $this->patch(route('emendas.compliance.update', [$amendment, 'TRA-01']), [
            ...$payload,
            'evidence_notes' => 'Tentativa repetida.',
        ])->assertSessionHas('warning', 'Esta revisão já foi processada.');

        $this->assertSame('Publicação conferida no portal municipal.', $amendment->complianceReviews()->firstOrFail()->evidence_notes);
        $this->assertDatabaseCount('amendment_compliance_reviews', 1);
        $this->assertSame(1, $amendment->auditLogs()->where('action', 'compliance_review_updated')->count());
    }

    public function test_user_cannot_access_matrix_from_another_municipality(): void
    {
        [$user, $municipality] = $this->context();
        [$otherUser, , $otherAmendment] = $this->context();

        $this->actingAs($user)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('emendas.compliance', $otherAmendment))
            ->assertNotFound();

        $this->assertNotSame($user->id, $otherUser->id);
    }

    /** @return array{User, Municipality, ParliamentaryAmendment} */
    private function context(array $amendmentAttributes = [], string $role = User::ROLE_MANAGER): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create(['state' => 'SP']);
        $municipality->users()->attach($user, ['role' => $role]);
        $amendment = ParliamentaryAmendment::factory()
            ->for($municipality)
            ->for($user, 'creator')
            ->create(array_merge([
                'government_sphere' => 'municipal',
                'transfer_type' => 'direct_execution',
                'transferegov_code' => null,
            ], $amendmentAttributes));

        return [$user, $municipality, $amendment];
    }

    private function reviewToken(ParliamentaryAmendment $amendment, string $code): string
    {
        $token = (string) Str::uuid();
        $this->withSession([
            'form_submission_tokens' => [
                "compliance-review-{$amendment->id}-{$code}" => [$token => now()->timestamp],
            ],
        ]);

        return $token;
    }
}
