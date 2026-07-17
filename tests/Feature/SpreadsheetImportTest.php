<?php

namespace Tests\Feature;

use App\Models\AmendmentImportBatch;
use App\Models\AmendmentImportRow;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_download_template_and_view_import_page(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);

        $this->actingAs($manager)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('spreadsheet-imports.index'))
            ->assertOk()
            ->assertSee('Importar planilha')
            ->assertSee('Baixar modelo CSV');

        $response = $this->get(route('spreadsheet-imports.template'));
        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('modelo-importacao-emendas.csv');
        $this->assertStringContainsString('Identificacao da emenda', (string) $response->getContent());
        $this->assertStringContainsString(';Exercicio;Esfera;', (string) $response->getContent());
    }

    public function test_preview_classifies_valid_duplicate_and_invalid_rows(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        ParliamentaryAmendment::factory()->for($municipality)->for($manager, 'creator')->create([
            'reference' => 'EM-DUPLICADA',
            'fiscal_year' => 2026,
            'government_sphere' => 'federal',
        ]);
        $token = $this->sessionToken($municipality, "spreadsheet-preview-{$municipality->id}");

        $response = $this->actingAs($manager)->post(route('spreadsheet-imports.preview'), [
            '_submission_token' => $token,
            'spreadsheet' => UploadedFile::fake()->createWithContent('controle.csv', $this->csv([
                $this->validRow(['Identificacao da emenda' => 'EM-NOVA']),
                $this->validRow(['Identificacao da emenda' => 'EM-DUPLICADA']),
                $this->validRow(['Identificacao da emenda' => 'EM-INVALIDA', 'Objeto' => '']),
            ])),
        ]);

        $batch = AmendmentImportBatch::firstOrFail();
        $response->assertRedirect(route('spreadsheet-imports.show', $batch));
        $this->assertSame(3, $batch->total_rows);
        $this->assertSame(1, $batch->valid_rows);
        $this->assertSame(1, $batch->duplicate_rows);
        $this->assertSame(1, $batch->invalid_rows);
        $this->assertDatabaseHas('amendment_import_rows', [
            'amendment_import_batch_id' => $batch->id,
            'row_number' => 2,
            'status' => AmendmentImportRow::STATUS_VALID,
        ]);
        $validRow = $batch->rows()->where('status', AmendmentImportRow::STATUS_VALID)->firstOrFail();
        $this->assertSame('500000.00', $validRow->normalized_data['expected_amount']);
        $this->assertSame('2026-03-15', $validRow->normalized_data['indicated_at']);
        $this->assertDatabaseHas('audit_logs', [
            'municipality_id' => $municipality->id,
            'action' => 'amendments_spreadsheet_previewed',
        ]);
    }

    public function test_confirmation_imports_only_valid_rows_and_cannot_repeat(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $previewToken = $this->sessionToken($municipality, "spreadsheet-preview-{$municipality->id}");
        $this->actingAs($manager)->post(route('spreadsheet-imports.preview'), [
            '_submission_token' => $previewToken,
            'spreadsheet' => UploadedFile::fake()->createWithContent('controle.csv', $this->csv([
                $this->validRow(['Identificacao da emenda' => 'EM-IMPORTADA']),
                $this->validRow(['Identificacao da emenda' => 'EM-CORRIGIR', 'Prazo de execucao' => 'data desconhecida']),
            ])),
        ]);
        $batch = AmendmentImportBatch::firstOrFail();
        $confirmToken = $this->sessionToken($municipality, "spreadsheet-confirm-{$batch->id}");
        $payload = ['_submission_token' => $confirmToken];

        $this->actingAs($manager)
            ->post(route('spreadsheet-imports.confirm', $batch), $payload)
            ->assertRedirect(route('spreadsheet-imports.show', $batch))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('parliamentary_amendments', [
            'municipality_id' => $municipality->id,
            'reference' => 'EM-IMPORTADA',
            'expected_amount' => 500000,
            'created_by' => $manager->id,
        ]);
        $this->assertDatabaseMissing('parliamentary_amendments', ['reference' => 'EM-CORRIGIR']);
        $this->assertSame(AmendmentImportBatch::STATUS_COMPLETED, $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->imported_rows);
        $this->assertDatabaseHas('audit_logs', [
            'municipality_id' => $municipality->id,
            'action' => 'amendments_spreadsheet_imported',
        ]);
        $this->assertDatabaseHas('municipal_work_items', [
            'municipality_id' => $municipality->id,
            'category' => 'responsibility',
        ]);

        $this->post(route('spreadsheet-imports.confirm', $batch), $payload)
            ->assertSessionHas('warning', 'A confirmação deste lote já foi processada.');
        $this->assertDatabaseCount('parliamentary_amendments', 1);
    }

    public function test_imports_are_isolated_and_viewers_cannot_use_the_module(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        [$otherManager, $otherMunicipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $viewer = User::factory()->create();
        $municipality->users()->attach($viewer, ['role' => User::ROLE_VIEWER]);
        $batch = $municipality->amendmentImportBatches()->create([
            'user_id' => $manager->id,
            'original_name' => 'privado.csv',
            'file_hash' => hash('sha256', 'privado'),
        ]);

        $this->actingAs($otherManager)
            ->withSession(['active_municipality_id' => $otherMunicipality->id])
            ->get(route('spreadsheet-imports.show', $batch))
            ->assertNotFound();

        $this->actingAs($viewer)
            ->withSession(['active_municipality_id' => $municipality->id])
            ->get(route('spreadsheet-imports.index'))
            ->assertForbidden();
        $this->post(route('spreadsheet-imports.preview'), [])->assertForbidden();
    }

    public function test_missing_required_columns_return_a_human_error(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $token = $this->sessionToken($municipality, "spreadsheet-preview-{$municipality->id}");

        $this->actingAs($manager)->post(route('spreadsheet-imports.preview'), [
            '_submission_token' => $token,
            'spreadsheet' => UploadedFile::fake()->createWithContent('incompleta.csv', "Referencia;Ano\r\nEM-001;2026"),
        ])->assertSessionHasErrors('spreadsheet');

        $this->assertDatabaseCount('amendment_import_batches', 0);
    }

    public function test_new_duplicate_between_preview_and_confirmation_is_not_overwritten(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $previewToken = $this->sessionToken($municipality, "spreadsheet-preview-{$municipality->id}");
        $this->actingAs($manager)->post(route('spreadsheet-imports.preview'), [
            '_submission_token' => $previewToken,
            'spreadsheet' => UploadedFile::fake()->createWithContent('controle.csv', $this->csv([
                $this->validRow(['Identificacao da emenda' => 'EM-CONCORRENTE']),
            ])),
        ]);
        $batch = AmendmentImportBatch::firstOrFail();
        ParliamentaryAmendment::factory()->for($municipality)->for($manager, 'creator')->create([
            'reference' => 'EM-CONCORRENTE',
            'fiscal_year' => 2026,
            'government_sphere' => 'federal',
            'object' => 'Cadastro realizado depois da pré-visualização',
        ]);
        $confirmToken = $this->sessionToken($municipality, "spreadsheet-confirm-{$batch->id}");

        $this->actingAs($manager)->post(route('spreadsheet-imports.confirm', $batch), [
            '_submission_token' => $confirmToken,
        ])->assertSessionHas('status');

        $this->assertSame(0, $batch->fresh()->imported_rows);
        $this->assertSame(1, $batch->fresh()->duplicate_rows);
        $this->assertDatabaseCount('parliamentary_amendments', 1);
        $this->assertDatabaseHas('parliamentary_amendments', [
            'reference' => 'EM-CONCORRENTE',
            'object' => 'Cadastro realizado depois da pré-visualização',
        ]);
    }

    public function test_comma_delimiter_and_windows_excel_encoding_are_recognized(): void
    {
        [$manager, $municipality] = $this->memberWithMunicipality(User::ROLE_MANAGER);
        $token = $this->sessionToken($municipality, "spreadsheet-preview-{$municipality->id}");
        $contents = $this->csv([
            $this->validRow([
                'Identificacao da emenda' => 'EM-ENCODING',
                'Objeto' => 'Ampliação da atenção básica de saúde',
                'Valor previsto' => '500000.00',
            ]),
        ], ',');

        $this->actingAs($manager)->post(route('spreadsheet-imports.preview'), [
            '_submission_token' => $token,
            'spreadsheet' => UploadedFile::fake()->createWithContent(
                'excel.csv',
                mb_convert_encoding($contents, 'Windows-1252', 'UTF-8'),
            ),
        ])->assertRedirect();

        $row = AmendmentImportRow::firstOrFail();
        $this->assertSame(AmendmentImportRow::STATUS_VALID, $row->status);
        $this->assertSame('Ampliação da atenção básica de saúde', $row->normalized_data['object']);
        $this->assertSame('500000.00', $row->normalized_data['expected_amount']);
    }

    /** @return array{User, Municipality} */
    private function memberWithMunicipality(string $role): array
    {
        $user = User::factory()->create();
        $municipality = Municipality::factory()->create();
        $municipality->users()->attach($user, ['role' => $role]);

        return [$user, $municipality];
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
            'Identificacao da emenda' => 'EM-2026-001',
            'Exercicio' => '2026',
            'Esfera' => 'Federal',
            'Tipo de autoria' => 'Individual',
            'Modalidade' => 'Transferencia especial',
            'Autor' => 'Deputada Maria Silva',
            'Partido' => 'PSD',
            'Objeto' => 'Reforma da unidade basica de saude',
            'Secretaria responsavel' => 'Secretaria Municipal de Saude',
            'Codigo Transferegov' => '123456',
            'Valor previsto' => 'R$ 500.000,00',
            'Valor recebido' => '',
            'Situacao' => 'Identificada',
            'Data da indicacao' => '15/03/2026',
            'Data do recebimento' => '',
            'Prazo de comunicacao' => '30/04/2026',
            'Comunicacao concluida em' => '',
            'Prazo de execucao' => '31/12/2026',
            'Execucao concluida em' => '',
            'Prazo de prestacao de contas' => '31/03/2027',
            'Prestacao de contas concluida em' => '',
            'Observacoes' => '',
        ], $overrides);
    }
}
