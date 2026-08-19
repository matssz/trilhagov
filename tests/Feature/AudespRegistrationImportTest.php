<?php

namespace Tests\Feature;

use App\Models\AudespRegistrationImportBatch;
use App\Models\AudespRegistrationImportRow;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class AudespRegistrationImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_download_template_and_preview_a_valid_row(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, 'EM-2026-001');

        $template = $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('audesp-registration-imports.template'));
        $template->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('modelo-cadastros-audesp.csv');
        $this->assertStringContainsString('Referência da emenda', (string) $template->getContent());

        $token = $this->sessionToken($municipality, "audesp-registration-import-preview-{$municipality->id}");
        $response = $this->actingAs($manager)->post(route('audesp-registration-imports.preview'), [
            '_submission_token' => $token,
            'spreadsheet' => UploadedFile::fake()->createWithContent('cadastros.csv', $this->csv([
                $this->validRow(['Referência da emenda (TrilhaGov)' => $amendment->reference]),
            ])),
        ]);

        $batch = AudespRegistrationImportBatch::firstOrFail();
        $response->assertRedirect(route('audesp-registration-imports.show', $batch));
        $this->assertSame(1, $batch->valid_rows);
        $row = $batch->rows()->firstOrFail();
        $this->assertSame(AudespRegistrationImportRow::STATUS_VALID, $row->status);
        $this->assertSame($amendment->id, $row->parliamentary_amendment_id);
        $this->assertSame(1, $row->normalized_data['amendment_type']);
        $this->assertSame(['301'], $row->normalized_data['government_subfunctions']);
        $this->assertSame('8010001', $row->normalized_data['application_code']);
        $this->assertFalse($row->normalized_data['bank_account_opened']);
    }

    public function test_preview_flags_duplicate_when_amendment_already_has_registration(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, 'EM-2026-002');
        $amendment->audespRegistration()->create($this->registrationAttributes($municipality, $manager, 'EM-2026-EXISTENTE'));
        $token = $this->sessionToken($municipality, "audesp-registration-import-preview-{$municipality->id}");

        $this->actingAs($manager)->post(route('audesp-registration-imports.preview'), [
            '_submission_token' => $token,
            'spreadsheet' => UploadedFile::fake()->createWithContent('cadastros.csv', $this->csv([
                $this->validRow(['Referência da emenda (TrilhaGov)' => $amendment->reference]),
            ])),
        ])->assertRedirect();

        $batch = AudespRegistrationImportBatch::firstOrFail();
        $this->assertSame(1, $batch->duplicate_rows);
        $row = $batch->rows()->firstOrFail();
        $this->assertSame(AudespRegistrationImportRow::STATUS_DUPLICATE, $row->status);
        $this->assertStringContainsString('já possui cadastro Audesp', $row->errors[0]);
    }

    public function test_preview_flags_duplicate_when_number_and_year_repeat_across_different_amendments(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $first = $this->amendment($municipality, $manager, 'EM-2026-003');
        $second = $this->amendment($municipality, $manager, 'EM-2026-004');
        $token = $this->sessionToken($municipality, "audesp-registration-import-preview-{$municipality->id}");

        $this->actingAs($manager)->post(route('audesp-registration-imports.preview'), [
            '_submission_token' => $token,
            'spreadsheet' => UploadedFile::fake()->createWithContent('cadastros.csv', $this->csv([
                $this->validRow(['Referência da emenda (TrilhaGov)' => $first->reference, 'Número da emenda' => '001']),
                $this->validRow(['Referência da emenda (TrilhaGov)' => $second->reference, 'Número da emenda' => '001']),
            ])),
        ])->assertRedirect();

        $batch = AudespRegistrationImportBatch::firstOrFail();
        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(1, $batch->duplicate_rows);
    }

    public function test_preview_flags_invalid_when_amendment_reference_is_not_found(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $token = $this->sessionToken($municipality, "audesp-registration-import-preview-{$municipality->id}");

        $this->actingAs($manager)->post(route('audesp-registration-imports.preview'), [
            '_submission_token' => $token,
            'spreadsheet' => UploadedFile::fake()->createWithContent('cadastros.csv', $this->csv([
                $this->validRow(['Referência da emenda (TrilhaGov)' => 'EM-INEXISTENTE']),
            ])),
        ])->assertRedirect();

        $batch = AudespRegistrationImportBatch::firstOrFail();
        $this->assertSame(1, $batch->invalid_rows);
        $row = $batch->rows()->firstOrFail();
        $this->assertSame(AudespRegistrationImportRow::STATUS_INVALID, $row->status);
        $this->assertStringContainsString('Nenhuma emenda com esta referência', $row->errors[0]);
    }

    public function test_confirmation_creates_registrations_and_cannot_repeat(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $amendment = $this->amendment($municipality, $manager, 'EM-2026-005');
        $previewToken = $this->sessionToken($municipality, "audesp-registration-import-preview-{$municipality->id}");
        $this->actingAs($manager)->post(route('audesp-registration-imports.preview'), [
            '_submission_token' => $previewToken,
            'spreadsheet' => UploadedFile::fake()->createWithContent('cadastros.csv', $this->csv([
                $this->validRow(['Referência da emenda (TrilhaGov)' => $amendment->reference]),
            ])),
        ]);
        $batch = AudespRegistrationImportBatch::firstOrFail();
        $confirmToken = $this->sessionToken($municipality, "audesp-registration-import-confirm-{$batch->id}");
        $payload = ['_submission_token' => $confirmToken];

        $this->actingAs($manager)
            ->post(route('audesp-registration-imports.confirm', $batch), $payload)
            ->assertRedirect(route('audesp-registration-imports.show', $batch))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('audesp_amendment_registrations', [
            'parliamentary_amendment_id' => $amendment->id,
            'application_code' => '8010001',
            'amendment_number' => '001',
            'amendment_year' => 2026,
        ]);
        $this->assertSame(AudespRegistrationImportBatch::STATUS_COMPLETED, $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->imported_rows);
        $this->assertDatabaseHas('audit_logs', [
            'municipality_id' => $municipality->id,
            'action' => 'audesp_registrations_imported',
        ]);

        $this->post(route('audesp-registration-imports.confirm', $batch), $payload)
            ->assertSessionHas('warning', 'A confirmação deste lote já foi processada.');
        $this->assertDatabaseCount('audesp_amendment_registrations', 1);
    }

    public function test_import_is_scoped_by_tenant_and_forbidden_for_viewers(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$otherManager, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $viewer = User::factory()->create();
        $municipality->users()->attach($viewer, ['role' => User::ROLE_VIEWER]);
        $batch = $municipality->audespRegistrationImportBatches()->create([
            'user_id' => $manager->id,
            'original_name' => 'privado.csv',
            'file_hash' => hash('sha256', 'privado'),
        ]);

        $this->actingAs($otherManager)
            ->withSession(['active_municipality_id' => $otherMunicipality->id])
            ->get(route('audesp-registration-imports.show', $batch))
            ->assertNotFound();

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->post(route('audesp-registration-imports.preview'), [])
            ->assertForbidden();
    }

    /** @return array{User, Municipality} */
    private function memberWithMunicipality(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create([
            'state' => 'SP',
            'ibge_code' => fake()->unique()->numerify('35#####'),
        ]);
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
    }

    private function amendment(Municipality $municipality, User $user, string $reference): ParliamentaryAmendment
    {
        return ParliamentaryAmendment::factory()->for($municipality)->for($user, 'creator')->create([
            'reference' => $reference,
            'government_sphere' => 'municipal',
            'responsible_user_id' => $user->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function registrationAttributes(Municipality $municipality, User $user, string $number): array
    {
        return [
            'municipality_id' => $municipality->id,
            'created_by' => $user->id,
            'scope' => 'M',
            'amendment_type' => 2,
            'legal_basis' => 'Lei',
            'proponent_name' => 'Vereador João Municipal',
            'amendment_number' => $number,
            'amendment_year' => 2026,
            'object' => 'Modernização da unidade básica de saúde municipal.',
            'purpose' => 'Ampliar atendimento da atenção básica municipal.',
            'government_function' => '10',
            'government_subfunctions' => ['301', '302'],
            'destination' => 'C',
            'bank_account_opened' => false,
            'application_code' => '8001',
            'prepared_at' => now(),
        ];
    }

    private function sessionToken(Municipality $municipality, string $scope): string
    {
        $token = (string) Str::uuid();
        $this->withSession([
            'active_municipality_id' => $municipality->id,
            'form_submission_tokens' => [$scope => [$token => now()->timestamp]],
        ]);

        return $token;
    }

    /** @param array<int, array<string, string>> $rows */
    private function csv(array $rows, string $delimiter = ';'): string
    {
        $headers = array_keys($this->validRow());
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, $delimiter);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $header) => $row[$header] ?? '', $headers), $delimiter);
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents ?: '';
    }

    /** @return array<string, string> */
    private function validRow(array $overrides = []): array
    {
        return array_merge([
            'Referência da emenda (TrilhaGov)' => 'EM-2026-001',
            'Tipo de emenda (1 a 4)' => '1',
            'Fundamento legal' => 'Lei',
            'Parlamentar proponente' => 'Vereadora Maria Silva',
            'Número da emenda' => '001',
            'Ano da emenda' => '2026',
            'Objeto' => 'Reforma da unidade básica de saúde',
            'Finalidade' => 'Ampliar a capacidade de atendimento da UBS Central',
            'Função de governo (código)' => '10',
            'Subfunções (códigos separados por vírgula)' => '301',
            'Destinação (C ou I)' => 'I',
            'Abertura de conta bancária (Sim/Não)' => 'Não',
            'Código de aplicação' => '8010001',
        ], $overrides);
    }
}
