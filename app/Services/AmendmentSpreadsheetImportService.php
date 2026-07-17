<?php

namespace App\Services;

use App\Models\AmendmentImportBatch;
use App\Models\AmendmentImportRow;
use App\Models\Municipality;
use App\Models\ParliamentaryAmendment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AmendmentSpreadsheetImportService
{
    public const MAX_ROWS = 500;

    /** @var array<string, string> */
    private const TEMPLATE_HEADERS = [
        'reference' => 'Identificacao da emenda',
        'fiscal_year' => 'Exercicio',
        'government_sphere' => 'Esfera',
        'authorship_type' => 'Tipo de autoria',
        'transfer_type' => 'Modalidade',
        'author_name' => 'Autor',
        'author_party' => 'Partido',
        'object' => 'Objeto',
        'responsible_department' => 'Secretaria responsavel',
        'transferegov_code' => 'Codigo Transferegov',
        'expected_amount' => 'Valor previsto',
        'received_amount' => 'Valor recebido',
        'status' => 'Situacao',
        'indicated_at' => 'Data da indicacao',
        'received_at' => 'Data do recebimento',
        'communication_deadline' => 'Prazo de comunicacao',
        'communication_completed_at' => 'Comunicacao concluida em',
        'execution_deadline' => 'Prazo de execucao',
        'execution_completed_at' => 'Execucao concluida em',
        'accountability_deadline' => 'Prazo de prestacao de contas',
        'accountability_completed_at' => 'Prestacao de contas concluida em',
        'notes' => 'Observacoes',
    ];

    /** @var array<int, string> */
    private const REQUIRED_COLUMNS = [
        'reference',
        'fiscal_year',
        'government_sphere',
        'authorship_type',
        'transfer_type',
        'author_name',
        'object',
        'responsible_department',
        'expected_amount',
        'status',
        'indicated_at',
        'communication_deadline',
        'execution_deadline',
        'accountability_deadline',
    ];

    public function __construct(
        private readonly AuditTrail $auditTrail,
        private readonly IntegrityAlertService $integrityAlertService,
        private readonly MunicipalWorkItemService $workItemService,
    ) {}

    public function createPreview(Municipality $municipality, User $user, UploadedFile $file): AmendmentImportBatch
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || trim($contents) === '') {
            throw ValidationException::withMessages([
                'spreadsheet' => 'O arquivo está vazio ou não pôde ser lido.',
            ]);
        }

        $contents = $this->toUtf8($contents);
        $parsedRows = $this->parse($contents);
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

        $existingKeys = $municipality->amendments()
            ->get(['reference', 'fiscal_year', 'government_sphere'])
            ->mapWithKeys(fn (ParliamentaryAmendment $amendment) => [
                $this->identityKey($amendment->reference, $amendment->fiscal_year, $amendment->government_sphere) => true,
            ])
            ->all();
        $fileKeys = [];
        $preparedRows = [];

        foreach ($parsedRows as $parsedRow) {
            $normalized = $this->normalize($parsedRow['data']);
            $errors = $this->validationErrors($normalized);
            $status = AmendmentImportRow::STATUS_INVALID;

            if ($errors === []) {
                $key = $this->identityKey(
                    (string) $normalized['reference'],
                    (int) $normalized['fiscal_year'],
                    (string) $normalized['government_sphere'],
                );

                if (isset($existingKeys[$key])) {
                    $status = AmendmentImportRow::STATUS_DUPLICATE;
                    $errors[] = 'Esta emenda já existe no município e não será sobrescrita.';
                } elseif (isset($fileKeys[$key])) {
                    $status = AmendmentImportRow::STATUS_DUPLICATE;
                    $errors[] = 'Esta emenda está repetida dentro do próprio arquivo.';
                } else {
                    $status = AmendmentImportRow::STATUS_VALID;
                    $fileKeys[$key] = true;
                }
            }

            $preparedRows[] = [
                'municipality_id' => $municipality->id,
                'row_number' => $parsedRow['row_number'],
                'status' => $status,
                'raw_data' => $parsedRow['data'],
                'normalized_data' => $normalized,
                'errors' => $errors === [] ? null : $errors,
            ];
        }

        return DB::transaction(function () use ($municipality, $user, $file, $contents, $preparedRows): AmendmentImportBatch {
            $batch = $municipality->amendmentImportBatches()->create([
                'user_id' => $user->id,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'file_hash' => hash('sha256', $contents),
                'status' => AmendmentImportBatch::STATUS_PREVIEWED,
                'total_rows' => count($preparedRows),
                'valid_rows' => collect($preparedRows)->where('status', AmendmentImportRow::STATUS_VALID)->count(),
                'duplicate_rows' => collect($preparedRows)->where('status', AmendmentImportRow::STATUS_DUPLICATE)->count(),
                'invalid_rows' => collect($preparedRows)->where('status', AmendmentImportRow::STATUS_INVALID)->count(),
            ]);

            foreach ($preparedRows as $preparedRow) {
                $batch->rows()->create($preparedRow);
            }

            return $batch;
        });
    }

    /** @return array{imported: int, duplicates: int, invalid: int} */
    public function confirm(AmendmentImportBatch $batch, Request $request): array
    {
        $municipality = $batch->municipality()->firstOrFail();

        $stats = DB::transaction(function () use ($batch, $request, $municipality): array {
            $lockedBatch = AmendmentImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($lockedBatch->status === AmendmentImportBatch::STATUS_COMPLETED) {
                return [
                    'imported' => $lockedBatch->imported_rows,
                    'duplicates' => $lockedBatch->duplicate_rows,
                    'invalid' => $lockedBatch->invalid_rows,
                ];
            }

            $rows = $lockedBatch->rows()
                ->where('status', AmendmentImportRow::STATUS_VALID)
                ->orderBy('row_number')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                $data = $row->normalized_data;
                $alreadyExists = $municipality->amendments()
                    ->where('reference', $data['reference'])
                    ->where('fiscal_year', $data['fiscal_year'])
                    ->where('government_sphere', $data['government_sphere'])
                    ->exists();

                if ($alreadyExists) {
                    $row->update([
                        'status' => AmendmentImportRow::STATUS_DUPLICATE,
                        'errors' => ['A emenda foi cadastrada depois da pré-visualização e não foi sobrescrita.'],
                    ]);

                    continue;
                }

                try {
                    $amendment = $municipality->amendments()->create([
                        ...$data,
                        'created_by' => $request->user()->id,
                    ]);
                } catch (QueryException $exception) {
                    if ((string) $exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    $row->update([
                        'status' => AmendmentImportRow::STATUS_DUPLICATE,
                        'errors' => ['A emenda foi cadastrada durante a confirmação e não foi sobrescrita.'],
                    ]);

                    continue;
                }
                $this->auditTrail->recordCreation($request, $amendment);
                $row->update([
                    'status' => AmendmentImportRow::STATUS_IMPORTED,
                    'parliamentary_amendment_id' => $amendment->id,
                ]);
            }

            $counts = $lockedBatch->rows()
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');
            $lockedBatch->update([
                'status' => AmendmentImportBatch::STATUS_COMPLETED,
                'valid_rows' => (int) ($counts[AmendmentImportRow::STATUS_VALID] ?? 0),
                'duplicate_rows' => (int) ($counts[AmendmentImportRow::STATUS_DUPLICATE] ?? 0),
                'invalid_rows' => (int) ($counts[AmendmentImportRow::STATUS_INVALID] ?? 0),
                'imported_rows' => (int) ($counts[AmendmentImportRow::STATUS_IMPORTED] ?? 0),
                'completed_at' => now(),
            ]);

            $this->auditTrail->recordMunicipalityOperation($request, $municipality, 'amendments_spreadsheet_imported', [
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
            $municipality->refresh();
            $this->integrityAlertService->sync($municipality);
            $this->workItemService->synchronize($municipality);
        }

        return $stats;
    }

    public function templateContents(): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_values(self::TEMPLATE_HEADERS), ';');
        fputcsv($stream, [
            'EM-2026-001', '2026', 'Federal', 'Individual', 'Transferencia especial',
            'Deputada Maria Silva', 'PSD', 'Reforma da unidade basica de saude',
            'Secretaria Municipal de Saude', '123456', 'R$ 500.000,00', '',
            'Identificada', '15/03/2026', '', '30/04/2026', '', '31/12/2026', '',
            '31/03/2027', '', 'Exemplo: substitua esta linha pelos dados do municipio.',
        ], ';');
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents ?: '';
    }

    /** @return array<int, array{row_number: int, data: array<string, string|null>}> */
    private function parse(string $contents): array
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
                $columnMap[$index] = $field;
            }
        }

        $missing = array_diff(self::REQUIRED_COLUMNS, $columnMap);
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
            if ($this->isBlankRow($values)) {
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

    /** @param array<int, string|null> $values */
    private function isBlankRow(array $values): bool
    {
        return collect($values)->every(fn ($value) => trim((string) $value) === '');
    }

    private function fieldForHeader(string $header): ?string
    {
        $canonical = $this->canonical($header);
        $aliases = [
            'reference' => ['identificacao_da_emenda', 'identificacao', 'referencia', 'codigo_da_emenda', 'emenda'],
            'fiscal_year' => ['exercicio', 'ano', 'ano_da_emenda'],
            'government_sphere' => ['esfera', 'esfera_governamental', 'origem'],
            'authorship_type' => ['tipo_de_autoria', 'autoria_tipo'],
            'transfer_type' => ['modalidade', 'tipo_de_transferencia'],
            'author_name' => ['autor', 'autoria', 'parlamentar'],
            'author_party' => ['partido', 'sigla_partidaria'],
            'object' => ['objeto', 'finalidade'],
            'responsible_department' => ['secretaria_responsavel', 'orgao_responsavel', 'setor_responsavel'],
            'transferegov_code' => ['codigo_transferegov', 'transferegov', 'codigo_do_plano'],
            'expected_amount' => ['valor_previsto', 'valor_da_emenda', 'valor_indicado'],
            'received_amount' => ['valor_recebido', 'valor_transferido'],
            'status' => ['situacao', 'status'],
            'indicated_at' => ['data_da_indicacao', 'data_indicacao'],
            'received_at' => ['data_do_recebimento', 'data_recebimento'],
            'communication_deadline' => ['prazo_de_comunicacao', 'prazo_comunicacao'],
            'communication_completed_at' => ['comunicacao_concluida_em', 'data_conclusao_comunicacao'],
            'execution_deadline' => ['prazo_de_execucao', 'prazo_execucao'],
            'execution_completed_at' => ['execucao_concluida_em', 'data_conclusao_execucao'],
            'accountability_deadline' => ['prazo_de_prestacao_de_contas', 'prazo_prestacao_de_contas'],
            'accountability_completed_at' => ['prestacao_de_contas_concluida_em', 'data_conclusao_prestacao_de_contas'],
            'notes' => ['observacoes', 'observacao', 'notas'],
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
        $nullableFields = [
            'author_party', 'transferegov_code', 'received_amount', 'received_at',
            'communication_completed_at', 'execution_completed_at',
            'accountability_completed_at', 'notes',
        ];
        $normalized = [];
        foreach (array_keys(self::TEMPLATE_HEADERS) as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $normalized[$field] = in_array($field, $nullableFields, true) && $value === '' ? null : $value;
        }

        $normalized['fiscal_year'] = ctype_digit((string) $normalized['fiscal_year'])
            ? (int) $normalized['fiscal_year']
            : $normalized['fiscal_year'];
        $normalized['government_sphere'] = $this->enumValue((string) $normalized['government_sphere'], [
            'federal' => ['federal', 'uniao'],
            'state' => ['estadual', 'estado'],
        ]);
        $normalized['authorship_type'] = $this->enumValue((string) $normalized['authorship_type'], [
            'individual' => ['individual'],
            'caucus' => ['bancada'],
            'commission' => ['comissao'],
            'other' => ['outra', 'outro'],
        ]);
        $normalized['transfer_type'] = $this->enumValue((string) $normalized['transfer_type'], [
            'special' => ['transferencia_especial', 'especial'],
            'defined_purpose' => ['finalidade_definida'],
            'agreement' => ['convenio', 'contrato_de_repasse', 'convenio_ou_contrato_de_repasse'],
            'fund_to_fund' => ['fundo_a_fundo'],
            'other' => ['outra', 'outra_modalidade', 'outro'],
        ]);
        $normalized['status'] = $this->enumValue((string) $normalized['status'], [
            ParliamentaryAmendment::STATUS_IDENTIFIED => ['identificada', 'identificado'],
            ParliamentaryAmendment::STATUS_PLAN_PENDING => ['plano_de_trabalho_pendente'],
            ParliamentaryAmendment::STATUS_UNDER_ANALYSIS => ['em_analise', 'analise'],
            ParliamentaryAmendment::STATUS_APPROVED => ['aprovada', 'aprovado'],
            ParliamentaryAmendment::STATUS_RESOURCE_RECEIVED => ['recurso_recebido'],
            ParliamentaryAmendment::STATUS_EXECUTING => ['em_execucao', 'executando'],
            ParliamentaryAmendment::STATUS_ACCOUNTABILITY_PENDING => ['prestacao_de_contas_pendente'],
            ParliamentaryAmendment::STATUS_COMPLETED => ['concluida', 'concluido'],
            ParliamentaryAmendment::STATUS_BLOCKED => ['com_impedimento', 'impedida', 'bloqueada'],
        ]);

        foreach (['expected_amount', 'received_amount'] as $field) {
            $normalized[$field] = $this->amount($normalized[$field]);
        }
        foreach ([
            'indicated_at', 'received_at', 'communication_deadline', 'communication_completed_at',
            'execution_deadline', 'execution_completed_at', 'accountability_deadline',
            'accountability_completed_at',
        ] as $field) {
            $normalized[$field] = $this->date($normalized[$field]);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $data @return array<int, string> */
    private function validationErrors(array $data): array
    {
        $validator = Validator::make($data, [
            'reference' => ['required', 'string', 'max:100'],
            'fiscal_year' => ['required', 'integer', 'between:2000,'.(now()->year + 1)],
            'government_sphere' => ['required', Rule::in(array_keys(ParliamentaryAmendment::governmentSpheres()))],
            'authorship_type' => ['required', Rule::in(array_keys(ParliamentaryAmendment::authorshipTypes()))],
            'transfer_type' => ['required', Rule::in(array_keys(ParliamentaryAmendment::transferTypes()))],
            'author_name' => ['required', 'string', 'max:255'],
            'author_party' => ['nullable', 'required_if:authorship_type,individual', 'string', 'max:20'],
            'object' => ['required', 'string', 'max:5000'],
            'responsible_department' => ['required', 'string', 'max:255'],
            'transferegov_code' => ['nullable', 'required_if:government_sphere,federal', 'string', 'max:100'],
            'expected_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'received_amount' => ['nullable', 'required_if:status,resource_received,executing,accountability_pending,completed', 'numeric', 'min:0', 'max:9999999999999.99', 'lte:expected_amount'],
            'status' => ['required', Rule::in(array_keys(ParliamentaryAmendment::statuses()))],
            'indicated_at' => ['required', 'date', 'before_or_equal:today'],
            'received_at' => ['nullable', 'required_if:status,resource_received,executing,accountability_pending,completed', 'date', 'after_or_equal:indicated_at', 'before_or_equal:today'],
            'communication_deadline' => ['required', 'date', 'after_or_equal:indicated_at'],
            'communication_completed_at' => ['nullable', 'required_if:status,completed', 'date', 'after_or_equal:indicated_at', 'before_or_equal:today'],
            'execution_deadline' => ['required', 'date', 'after_or_equal:communication_deadline'],
            'execution_completed_at' => ['nullable', 'required_if:status,completed', 'date', 'after_or_equal:indicated_at', 'before_or_equal:today'],
            'accountability_deadline' => ['required', 'date', 'after_or_equal:execution_deadline'],
            'accountability_completed_at' => ['nullable', 'required_if:status,completed', 'date', 'after_or_equal:indicated_at', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ], [
            'author_party.required_if' => 'Informe o partido quando a autoria for individual.',
            'transferegov_code.required_if' => 'Informe o código Transferegov para emendas federais.',
            'received_amount.required_if' => 'Informe o valor recebido para a situação selecionada.',
            'received_amount.lte' => 'O valor recebido não pode ser maior que o valor previsto.',
            'received_at.required_if' => 'Informe a data do recebimento para a situação selecionada.',
            'communication_completed_at.required_if' => 'Informe quando a comunicação foi concluída.',
            'execution_completed_at.required_if' => 'Informe quando a execução foi concluída.',
            'accountability_completed_at.required_if' => 'Informe quando a prestação de contas foi concluída.',
        ], [
            'reference' => 'identificação da emenda',
            'fiscal_year' => 'exercício',
            'government_sphere' => 'esfera',
            'authorship_type' => 'tipo de autoria',
            'transfer_type' => 'modalidade',
            'author_name' => 'autor',
            'object' => 'objeto',
            'responsible_department' => 'secretaria responsável',
            'expected_amount' => 'valor previsto',
            'status' => 'situação',
            'indicated_at' => 'data da indicação',
            'communication_deadline' => 'prazo de comunicação',
            'execution_deadline' => 'prazo de execução',
            'accountability_deadline' => 'prazo de prestação de contas',
        ]);

        return array_values(array_unique($validator->errors()->all()));
    }

    /** @param array<string, array<int, string>> $map */
    private function enumValue(string $value, array $map): string
    {
        $canonical = $this->canonical($value);
        foreach ($map as $storedValue => $aliases) {
            if ($canonical === $storedValue || in_array($canonical, $aliases, true)) {
                return $storedValue;
            }
        }

        return $canonical;
    }

    private function amount(mixed $value): mixed
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $amount = preg_replace('/[^0-9,.-]/', '', (string) $value) ?? '';
        $lastComma = strrpos($amount, ',');
        $lastDot = strrpos($amount, '.');
        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $amount = str_replace($thousandsSeparator, '', $amount);
            $amount = str_replace($decimalSeparator, '.', $amount);
        } elseif ($lastComma !== false) {
            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '.', $amount);
        } elseif (substr_count($amount, '.') > 1) {
            $parts = explode('.', $amount);
            $decimal = array_pop($parts);
            $amount = implode('', $parts).'.'.$decimal;
        }

        return is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : $value;
    }

    private function date(mixed $value): mixed
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $date = trim((string) $value);
        if (ctype_digit($date) && (int) $date >= 20000 && (int) $date <= 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $date)->format('Y-m-d');
        }

        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d'] as $format) {
            try {
                $parsed = Carbon::createFromFormat('!'.$format, $date);
                if ($parsed !== false && $parsed->format($format) === $date) {
                    return $parsed->format('Y-m-d');
                }
            } catch (\Throwable) {
                // The validator will provide the user-facing error below.
            }
        }

        return $value;
    }

    private function identityKey(string $reference, int $year, string $sphere): string
    {
        return $sphere.'|'.$year.'|'.mb_strtolower(trim($reference));
    }

    private function canonical(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii($value))), '_');
    }
}
