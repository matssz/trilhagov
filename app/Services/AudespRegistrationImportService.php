<?php

namespace App\Services;

use App\Models\AudespAmendmentRegistration;
use App\Models\AudespRegistrationImportBatch;
use App\Models\AudespRegistrationImportRow;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AudespRegistrationImportService
{
    public const MAX_ROWS = 500;

    /** @var array<string, string> */
    private const TEMPLATE_HEADERS = [
        'amendment_reference' => 'Referência da emenda (TrilhaGov)',
        'amendment_type' => 'Tipo de emenda (1 a 4)',
        'legal_basis' => 'Fundamento legal',
        'proponent_name' => 'Parlamentar proponente',
        'amendment_number' => 'Número da emenda',
        'amendment_year' => 'Ano da emenda',
        'object' => 'Objeto',
        'purpose' => 'Finalidade',
        'government_function' => 'Função de governo (código)',
        'government_subfunctions' => 'Subfunções (códigos separados por vírgula)',
        'destination' => 'Destinação (C ou I)',
        'bank_account_opened' => 'Abertura de conta bancária (Sim/Não)',
        'application_code' => 'Código de aplicação',
    ];

    /** @var array<int, string> */
    private const REQUIRED_COLUMNS = [
        'amendment_reference',
        'amendment_type',
        'legal_basis',
        'proponent_name',
        'amendment_number',
        'amendment_year',
        'object',
        'purpose',
        'government_function',
        'government_subfunctions',
        'destination',
        'bank_account_opened',
        'application_code',
    ];

    public function __construct(
        private readonly AuditTrail $auditTrail,
        private readonly IntegrityAlertService $integrityAlertService,
    ) {}

    public function createPreview(Municipality $municipality, User $user, UploadedFile $file): AudespRegistrationImportBatch
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || trim($contents) === '') {
            throw ValidationException::withMessages([
                'spreadsheet' => 'O arquivo está vazio ou não pôde ser lido.',
            ]);
        }

        $parsedRows = $this->parseCsv($this->toUtf8($contents));
        if ($parsedRows === []) {
            throw ValidationException::withMessages([
                'spreadsheet' => 'A planilha não possui linhas de dados para conferir.',
            ]);
        }

        if (count($parsedRows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'spreadsheet' => 'Envie no máximo '.self::MAX_ROWS.' linhas por lote.',
            ]);
        }

        $amendmentsByReference = $municipality->amendments()
            ->with('audespRegistration')
            ->get()
            ->keyBy(fn (ParliamentaryAmendment $amendment) => mb_strtolower(trim($amendment->reference)));
        $existingIdentities = $municipality->audespAmendmentRegistrations()
            ->get(['scope', 'amendment_number', 'amendment_year'])
            ->map(fn (AudespAmendmentRegistration $registration) => $this->identityKey(
                $registration->scope,
                $registration->amendment_number,
                (int) $registration->amendment_year,
            ))
            ->all();

        $fileIdentities = [];
        $fileAmendmentReferences = [];
        $preparedRows = [];

        foreach ($parsedRows as $parsedRow) {
            $normalized = $this->normalize($parsedRow['data']);
            $errors = $this->validationErrors($normalized);
            $amendment = null;
            $status = AudespRegistrationImportRow::STATUS_INVALID;

            if ($errors === []) {
                $referenceKey = mb_strtolower(trim((string) $normalized['amendment_reference']));
                $amendment = $amendmentsByReference->get($referenceKey);

                if ($amendment === null) {
                    $errors[] = 'Nenhuma emenda com esta referência foi encontrada neste município.';
                } elseif ($amendment->audespRegistration !== null) {
                    $status = AudespRegistrationImportRow::STATUS_DUPLICATE;
                    $errors[] = 'Esta emenda já possui cadastro Audesp e não será sobrescrita.';
                } elseif (isset($fileAmendmentReferences[$referenceKey])) {
                    $status = AudespRegistrationImportRow::STATUS_DUPLICATE;
                    $errors[] = 'Esta emenda está repetida dentro do próprio arquivo.';
                } else {
                    $identityKey = $this->identityKey('M', (string) $normalized['amendment_number'], (int) $normalized['amendment_year']);

                    if (in_array($identityKey, $existingIdentities, true)) {
                        $status = AudespRegistrationImportRow::STATUS_DUPLICATE;
                        $errors[] = 'Já existe um cadastro Audesp com este número e exercício no município.';
                    } elseif (isset($fileIdentities[$identityKey])) {
                        $status = AudespRegistrationImportRow::STATUS_DUPLICATE;
                        $errors[] = 'Número e exercício da emenda repetidos dentro do próprio arquivo.';
                    } else {
                        $status = AudespRegistrationImportRow::STATUS_VALID;
                        $fileAmendmentReferences[$referenceKey] = true;
                        $fileIdentities[$identityKey] = true;
                    }
                }
            }

            $preparedRows[] = [
                'municipality_id' => $municipality->id,
                'parliamentary_amendment_id' => $amendment?->id,
                'row_number' => $parsedRow['row_number'],
                'status' => $status,
                'raw_data' => $parsedRow['data'],
                'normalized_data' => $normalized,
                'errors' => $errors === [] ? null : $errors,
            ];
        }

        return DB::transaction(function () use ($municipality, $user, $file, $contents, $preparedRows): AudespRegistrationImportBatch {
            $batch = $municipality->audespRegistrationImportBatches()->create([
                'user_id' => $user->id,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'file_hash' => hash('sha256', $contents),
                'status' => AudespRegistrationImportBatch::STATUS_PREVIEWED,
                'total_rows' => count($preparedRows),
                'valid_rows' => collect($preparedRows)->where('status', AudespRegistrationImportRow::STATUS_VALID)->count(),
                'duplicate_rows' => collect($preparedRows)->where('status', AudespRegistrationImportRow::STATUS_DUPLICATE)->count(),
                'invalid_rows' => collect($preparedRows)->where('status', AudespRegistrationImportRow::STATUS_INVALID)->count(),
            ]);

            foreach ($preparedRows as $preparedRow) {
                $batch->rows()->create($preparedRow);
            }

            return $batch;
        });
    }

    /** @return array{imported: int, duplicates: int, invalid: int} */
    public function confirm(AudespRegistrationImportBatch $batch, Request $request): array
    {
        $municipality = $batch->municipality()->firstOrFail();

        $stats = DB::transaction(function () use ($batch, $request, $municipality): array {
            $lockedBatch = AudespRegistrationImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($lockedBatch->status === AudespRegistrationImportBatch::STATUS_COMPLETED) {
                return [
                    'imported' => $lockedBatch->imported_rows,
                    'duplicates' => $lockedBatch->duplicate_rows,
                    'invalid' => $lockedBatch->invalid_rows,
                ];
            }

            $rows = $lockedBatch->rows()
                ->where('status', AudespRegistrationImportRow::STATUS_VALID)
                ->orderBy('row_number')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $data = $row->normalized_data;
                $amendment = $row->parliamentary_amendment_id
                    ? $municipality->amendments()->find($row->parliamentary_amendment_id)
                    : null;

                if ($amendment === null) {
                    $row->update([
                        'status' => AudespRegistrationImportRow::STATUS_INVALID,
                        'errors' => ['A emenda vinculada não foi encontrada durante a confirmação.'],
                    ]);

                    continue;
                }

                if ($amendment->audespRegistration()->exists()) {
                    $row->update([
                        'status' => AudespRegistrationImportRow::STATUS_DUPLICATE,
                        'errors' => ['Esta emenda recebeu um cadastro Audesp depois da pré-visualização e não foi sobrescrita.'],
                    ]);

                    continue;
                }

                try {
                    $registration = $amendment->audespRegistration()->create([
                        'municipality_id' => $municipality->id,
                        'created_by' => $request->user()->id,
                        'scope' => 'M',
                        'amendment_type' => $data['amendment_type'],
                        'legal_basis' => $data['legal_basis'],
                        'proponent_name' => trim((string) $data['proponent_name']),
                        'amendment_number' => trim((string) $data['amendment_number']),
                        'amendment_year' => $data['amendment_year'],
                        'object' => trim((string) $data['object']),
                        'purpose' => trim((string) $data['purpose']),
                        'government_function' => $data['government_function'],
                        'government_subfunctions' => $data['government_subfunctions'],
                        'destination' => $data['destination'],
                        'bank_account_opened' => (bool) $data['bank_account_opened'],
                        'application_code' => $data['application_code'],
                    ]);
                } catch (QueryException $exception) {
                    if ((string) $exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    $row->update([
                        'status' => AudespRegistrationImportRow::STATUS_DUPLICATE,
                        'errors' => ['Número e exercício foram usados por outro cadastro durante a confirmação.'],
                    ]);

                    continue;
                }

                $this->auditTrail->recordOperation($request, $amendment, 'audesp_registration_created', [
                    'audesp_schema' => AudespAmendmentRegistration::SCHEMA_VERSION,
                    'audesp_number' => $registration->amendment_number,
                    'audesp_year' => $registration->amendment_year,
                    'audesp_application_code' => $registration->application_code,
                    'source' => 'bulk_import',
                ]);
                $row->update([
                    'status' => AudespRegistrationImportRow::STATUS_IMPORTED,
                    'audesp_amendment_registration_id' => $registration->id,
                ]);
            }

            $counts = $lockedBatch->rows()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');
            $lockedBatch->update([
                'status' => AudespRegistrationImportBatch::STATUS_COMPLETED,
                'valid_rows' => (int) ($counts[AudespRegistrationImportRow::STATUS_VALID] ?? 0),
                'duplicate_rows' => (int) ($counts[AudespRegistrationImportRow::STATUS_DUPLICATE] ?? 0),
                'invalid_rows' => (int) ($counts[AudespRegistrationImportRow::STATUS_INVALID] ?? 0),
                'imported_rows' => (int) ($counts[AudespRegistrationImportRow::STATUS_IMPORTED] ?? 0),
                'completed_at' => now(),
            ]);

            $this->auditTrail->recordMunicipalityOperation($request, $municipality, 'audesp_registrations_imported', [
                'import_batch' => $lockedBatch->id,
                'source_file' => $lockedBatch->original_name,
                'imported_rows' => $lockedBatch->imported_rows,
                'duplicate_rows' => $lockedBatch->duplicate_rows,
                'invalid_rows' => $lockedBatch->invalid_rows,
            ]);

            return [
                'imported' => $lockedBatch->imported_rows,
                'duplicates' => $lockedBatch->duplicate_rows,
                'invalid' => $lockedBatch->invalid_rows,
            ];
        });

        if ($stats['imported'] > 0) {
            $this->integrityAlertService->sync($municipality->fresh());
        }

        return $stats;
    }

    public function templateContents(): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_values(self::TEMPLATE_HEADERS), ';');
        fputcsv($stream, $this->templateExampleRow(), ';');
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents ?: '';
    }

    /** @return array<int, string> */
    private function templateExampleRow(): array
    {
        return [
            'EM-2026-001', '1', 'Lei', 'Vereadora Maria Silva', '001', '2026',
            'Reforma da unidade básica de saúde', 'Ampliar a capacidade de atendimento da UBS Central',
            '10', '301', 'I', 'Não', '8010001',
        ];
    }

    /** @return array<int, array{row_number: int, data: array<string, string|null>}> */
    private function parseCsv(string $contents): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $firstLine = strtok($contents, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        $headers = fgetcsv($stream, 0, $delimiter);
        if (! is_array($headers)) {
            fclose($stream);
            throw ValidationException::withMessages(['spreadsheet' => 'Não foi possível identificar o cabeçalho da planilha.']);
        }

        $columnMap = [];
        foreach ($headers as $index => $header) {
            $field = $this->fieldForHeader((string) $header);
            if ($field !== null && ! in_array($field, $columnMap, true)) {
                $columnMap[(int) $index] = $field;
            }
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $columnMap));
        if ($missing !== []) {
            fclose($stream);
            $labels = array_map(fn (string $field) => self::TEMPLATE_HEADERS[$field], $missing);
            throw ValidationException::withMessages([
                'spreadsheet' => 'Colunas obrigatórias ausentes: '.implode(', ', $labels).'. Baixe o modelo para conferir o formato.',
            ]);
        }

        $rows = [];
        $rowNumber = 1;
        while (($values = fgetcsv($stream, 0, $delimiter)) !== false) {
            $rowNumber++;
            if (collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            $data = [];
            foreach ($columnMap as $index => $field) {
                $value = $values[$index] ?? null;
                $data[$field] = is_string($value) ? trim($value) : null;
            }
            $rows[] = ['row_number' => $rowNumber, 'data' => $data];
        }
        fclose($stream);

        return $rows;
    }

    private function fieldForHeader(string $header): ?string
    {
        $canonical = $this->canonical($header);
        $aliases = [
            'amendment_reference' => ['referencia_da_emenda_trilhagov', 'referencia_da_emenda', 'referencia', 'emenda'],
            'amendment_type' => ['tipo_de_emenda_1_a_4', 'tipo_de_emenda', 'tipo'],
            'legal_basis' => ['fundamento_legal'],
            'proponent_name' => ['parlamentar_proponente', 'proponente'],
            'amendment_number' => ['numero_da_emenda', 'numero'],
            'amendment_year' => ['ano_da_emenda', 'ano'],
            'object' => ['objeto'],
            'purpose' => ['finalidade'],
            'government_function' => ['funcao_de_governo_codigo', 'funcao_de_governo', 'funcao'],
            'government_subfunctions' => ['subfuncoes_codigos_separados_por_virgula', 'subfuncoes', 'subfuncao'],
            'destination' => ['destinacao_c_ou_i', 'destinacao'],
            'bank_account_opened' => ['abertura_de_conta_bancaria_sim_nao', 'abertura_de_conta_bancaria', 'conta_bancaria_aberta'],
            'application_code' => ['codigo_de_aplicacao'],
        ];

        foreach ($aliases as $field => $fieldAliases) {
            if (in_array($canonical, $fieldAliases, true)) {
                return $field;
            }
        }

        return null;
    }

    /** @param array<string, string|null> $data @return array<string, mixed> */
    private function normalize(array $data): array
    {
        $normalized = [];
        foreach (array_keys(self::TEMPLATE_HEADERS) as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $normalized[$field] = $value === '' ? null : $value;
        }

        $normalized['amendment_type'] = $this->amendmentTypeValue($normalized['amendment_type']);
        $normalized['legal_basis'] = $this->enumValue((string) $normalized['legal_basis'], array_combine(
            array_keys(AudespAmendmentRegistration::legalBases()),
            array_keys(AudespAmendmentRegistration::legalBases()),
        ));
        $normalized['destination'] = $this->enumValue((string) $normalized['destination'], [
            'C' => ['c', 'custeio'],
            'I' => ['i', 'investimento'],
        ]);
        $normalized['bank_account_opened'] = $this->booleanValue($normalized['bank_account_opened']);
        $normalized['amendment_year'] = ctype_digit((string) $normalized['amendment_year'])
            ? (int) $normalized['amendment_year']
            : $normalized['amendment_year'];
        $normalized['government_function'] = $normalized['government_function'] !== null
            ? str_pad(preg_replace('/\D/', '', (string) $normalized['government_function']) ?: '', 2, '0', STR_PAD_LEFT)
            : null;
        $normalized['government_subfunctions'] = $normalized['government_subfunctions'] !== null
            ? collect(explode(',', (string) $normalized['government_subfunctions']))
                ->map(fn ($code) => trim($code))
                ->filter()
                ->unique()
                ->values()
                ->all()
            : [];
        $normalized['application_code'] = $normalized['application_code'] !== null
            ? preg_replace('/\s+/', '', (string) $normalized['application_code'])
            : null;

        return $normalized;
    }

    /** @param array<string, mixed> $data @return array<int, string> */
    private function validationErrors(array $data): array
    {
        $validator = Validator::make($data, [
            'amendment_reference' => ['required', 'string', 'max:100'],
            'amendment_type' => ['required', 'integer', Rule::in(array_keys(AudespAmendmentRegistration::amendmentTypes()))],
            'legal_basis' => ['required', Rule::in(array_keys(AudespAmendmentRegistration::legalBases()))],
            'proponent_name' => ['required', 'string', 'min:10', 'max:100'],
            'amendment_number' => ['required', 'string', 'min:3', 'max:30'],
            'amendment_year' => ['required', 'integer', 'between:2000,2099'],
            'object' => ['required', 'string', 'min:10', 'max:1000'],
            'purpose' => ['required', 'string', 'min:10', 'max:1000'],
            'government_function' => ['required', Rule::in(array_keys(AudespTraceabilityService::governmentFunctions()))],
            'government_subfunctions' => ['required', 'array', 'min:1'],
            'government_subfunctions.*' => [Rule::in(AudespTraceabilityService::governmentSubfunctionCodes())],
            'destination' => ['required', Rule::in(array_keys(AudespAmendmentRegistration::destinations()))],
            'bank_account_opened' => ['required', 'boolean'],
            'application_code' => ['required', 'string', 'regex:/^(800|801|802|803|804|900|901|902|903)[0-9]{1,4}$/'],
        ], [
            'government_subfunctions.*.in' => 'Código de subfunção não previsto na tabela auxiliar Audesp.',
            'application_code.regex' => 'Use o código combinado do XSD: prefixo 800 a 804 ou 900 a 903, seguido de 1 a 4 dígitos.',
        ], [
            'amendment_reference' => 'referência da emenda',
            'amendment_type' => 'tipo de emenda',
            'legal_basis' => 'fundamento legal',
            'proponent_name' => 'parlamentar proponente',
            'amendment_number' => 'número da emenda',
            'amendment_year' => 'ano da emenda',
            'object' => 'objeto',
            'purpose' => 'finalidade',
            'government_function' => 'função de governo',
            'government_subfunctions' => 'subfunções',
            'destination' => 'destinação',
            'bank_account_opened' => 'abertura de conta bancária',
            'application_code' => 'código de aplicação',
        ]);

        return array_values(array_unique($validator->errors()->all()));
    }

    private function amendmentTypeValue(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $canonical = $this->canonical($value);
        $aliases = [
            1 => ['individual', 'individual_pix'],
            2 => ['individual_finalidade_definida'],
            3 => ['bancada', 'bancada_ou_bloco', 'bloco'],
            4 => ['relator'],
        ];
        foreach ($aliases as $type => $typeAliases) {
            if (in_array($canonical, $typeAliases, true)) {
                return $type;
            }
        }

        return $value;
    }

    private function booleanValue(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $canonical = $this->canonical($value);
        if (in_array($canonical, ['sim', 's', '1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($canonical, ['nao', 'n', '0', 'false', 'no'], true)) {
            return false;
        }

        return $value;
    }

    /** @param array<string, array<int, string>> $map */
    private function enumValue(string $value, array $map): string
    {
        $canonical = $this->canonical($value);
        foreach ($map as $storedValue => $aliases) {
            if ($canonical === $this->canonical((string) $storedValue) || in_array($canonical, $aliases, true)) {
                return (string) $storedValue;
            }
        }

        return $canonical;
    }

    private function identityKey(string $scope, string $number, int $year): string
    {
        return mb_strtoupper($scope).'|'.mb_strtoupper(trim($number)).'|'.$year;
    }

    private function canonical(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($value))), '_');
    }

    private function toUtf8(string $contents): string
    {
        $contents = str_replace("\0", '', $contents);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        $encoding = mb_detect_encoding($contents, ['Windows-1252', 'ISO-8859-1'], true) ?: 'Windows-1252';

        return mb_convert_encoding($contents, 'UTF-8', $encoding);
    }
}
