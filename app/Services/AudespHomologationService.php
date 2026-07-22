<?php

namespace App\Services;

use App\Models\AudespAmendmentRegistration;
use App\Models\AudespHomologationBatch;
use App\Models\AudespHomologationItem;
use App\Models\FinancialCommitment;
use App\Models\Municipality;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AudespHomologationService
{
    private const REGISTRATION_FIELD_LABELS = [
        'scope' => 'Âmbito da emenda',
        'amendment_type' => 'Tipo da emenda',
        'legal_basis' => 'Fundamento legal',
        'proponent_name' => 'Parlamentar proponente',
        'amendment_number' => 'Número da emenda',
        'amendment_year' => 'Ano da emenda',
        'object' => 'Objeto',
        'purpose' => 'Finalidade',
        'government_function' => 'Função de governo',
        'government_subfunctions' => 'Subfunções',
        'destination' => 'Destinação',
        'bank_account_opened' => 'Abertura de conta bancária',
        'application_code' => 'Código de aplicação',
    ];

    private const FINANCIAL_FIELD_LABELS = [
        'pre_commitment_amount' => 'Pré-empenho / reserva orçamentária',
        'committed_amount' => 'Empenhado líquido na competência',
        'liquidated_amount' => 'Liquidado líquido na competência',
        'paid_amount' => 'Pago líquido na competência',
    ];

    /**
     * @return array{document_type: string, items: array<int, array<string, mixed>>, stats: array{total: int, matched: int, divergent: int, unmatched: int}}
     */
    public function inspect(
        string $contents,
        Municipality $municipality,
        ?int $fiscalYear = null,
        ?int $referenceMonth = null,
    ): array {
        [$xml, $xpath] = $this->parse($contents);
        $registrationNodes = $xpath->query('//*[local-name()="EmendasParlamentares"]');

        if ($registrationNodes !== false && $registrationNodes->length > 0) {
            return $this->inspectRegistrations($xpath, $municipality, $fiscalYear);
        }

        $monthlyRoot = $xpath->query('/*[local-name()="DetalheMovimentoMensal"]');
        if ($monthlyRoot !== false && $monthlyRoot->length > 0) {
            return $this->inspectMonthlyFinancial($xpath, $municipality, $fiscalYear, $referenceMonth);
        }

        unset($xml);
        throw ValidationException::withMessages([
            'source_file' => 'O XML não contém Cadastros Contábeis de Emendas nem o Detalhe do Movimento Mensal Audesp.',
        ]);
    }

    /** @return array{DOMDocument, DOMXPath} */
    private function parse(string $contents): array
    {
        if (stripos($contents, '<!DOCTYPE') !== false) {
            throw ValidationException::withMessages([
                'source_file' => 'O XML contém uma declaração DOCTYPE não permitida por segurança.',
            ]);
        }

        $previous = libxml_use_internal_errors(true);
        $xml = new DOMDocument;
        $loaded = $xml->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $detail = collect($errors)
                ->map(fn ($error) => trim((string) $error->message))
                ->filter()
                ->unique()
                ->take(2)
                ->join(' ');

            throw ValidationException::withMessages([
                'source_file' => 'O arquivo não é um XML válido.'.($detail ? ' '.$detail : ''),
            ]);
        }

        return [$xml, new DOMXPath($xml)];
    }

    /**
     * @return array{document_type: string, items: array<int, array<string, mixed>>, stats: array{total: int, matched: int, divergent: int, unmatched: int}}
     */
    private function inspectRegistrations(DOMXPath $xpath, Municipality $municipality, ?int $fiscalYear): array
    {
        $nodes = $xpath->query('//*[local-name()="EmendasParlamentares"]');
        $query = $municipality->audespAmendmentRegistrations()->with('amendment:id,reference');
        if ($fiscalYear !== null) {
            $query->where('amendment_year', $fiscalYear);
        }
        $registrations = $query->get()->keyBy(fn (AudespAmendmentRegistration $registration) => $this->identity(
            $registration->scope,
            $registration->amendment_number,
            $registration->amendment_year,
        ));
        $items = [];
        $stats = $this->emptyStats();

        foreach ($nodes ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $source = $this->registrationSourceSnapshot($xpath, $node);
            $registration = $registrations->get($this->identity(
                $source['scope'],
                $source['amendment_number'],
                $source['amendment_year'],
            ));
            $local = $registration ? $this->registrationLocalSnapshot($registration) : null;
            $differences = $local ? $this->differences($source, $local, self::REGISTRATION_FIELD_LABELS) : [];
            $status = $registration === null
                ? AudespHomologationItem::STATUS_UNMATCHED
                : ($differences === [] ? AudespHomologationItem::STATUS_MATCHED : AudespHomologationItem::STATUS_DIVERGENT);

            $this->countStatus($stats, $status);
            $items[] = $this->item($municipality, $registration, $status, $source, $local, $differences);
        }

        return [
            'document_type' => AudespHomologationBatch::TYPE_AMENDMENT_REGISTRY,
            'items' => $items,
            'stats' => $stats,
        ];
    }

    /**
     * @return array{document_type: string, items: array<int, array<string, mixed>>, stats: array{total: int, matched: int, divergent: int, unmatched: int}}
     */
    private function inspectMonthlyFinancial(
        DOMXPath $xpath,
        Municipality $municipality,
        ?int $fiscalYear,
        ?int $referenceMonth,
    ): array {
        if ($fiscalYear === null || $referenceMonth === null) {
            throw ValidationException::withMessages([
                'source_file' => 'Informe o exercício e a competência para conferir o movimento mensal.',
            ]);
        }

        $registrations = $municipality->audespAmendmentRegistrations()
            ->where('amendment_year', $fiscalYear)
            ->with([
                'amendment:id,reference',
                'amendment.legislativeProposal:id,parliamentary_amendment_id,budget_reservation_number,budget_reserved_amount,budget_reserved_at',
                'amendment.financialCommitments:id,parliamentary_amendment_id,commitment_number,committed_amount,committed_at,status',
                'amendment.financialLiquidations:id,parliamentary_amendment_id,liquidation_reference,amount,liquidated_at',
                'amendment.financialPayments:id,parliamentary_amendment_id,payment_reference,amount,paid_at',
            ])
            ->get();
        $registrationsByCode = $registrations->groupBy(fn (AudespAmendmentRegistration $registration) => $this->normalizeCode($registration->application_code));
        $sourceByCode = $this->monthlySourceSnapshots($xpath);

        foreach ($registrations as $registration) {
            $code = $this->normalizeCode($registration->application_code);
            if ($code === '' || isset($sourceByCode[$code])) {
                continue;
            }
            $local = $this->financialLocalSnapshot($registration, $fiscalYear, $referenceMonth);
            if ($this->hasFinancialActivity($local)) {
                $sourceByCode[$code] = $this->emptyFinancialSource($code);
            }
        }

        if ($sourceByCode === []) {
            throw ValidationException::withMessages([
                'source_file' => 'Nenhum movimento de emenda com Código de Aplicação foi localizado no XML mensal.',
            ]);
        }

        ksort($sourceByCode);
        $items = [];
        $stats = $this->emptyStats();
        foreach ($sourceByCode as $code => $source) {
            /** @var Collection<int, AudespAmendmentRegistration> $matches */
            $matches = $registrationsByCode->get($code, collect());
            $registration = $matches->count() === 1 ? $matches->first() : null;
            $local = $registration ? $this->financialLocalSnapshot($registration, $fiscalYear, $referenceMonth) : null;
            $differences = $local ? $this->financialDifferences($source, $local) : [];
            $status = $registration === null
                ? AudespHomologationItem::STATUS_UNMATCHED
                : ($differences === [] ? AudespHomologationItem::STATUS_MATCHED : AudespHomologationItem::STATUS_DIVERGENT);

            $source['scope'] = $registration?->scope ?? 'M';
            $source['amendment_number'] = $registration?->amendment_number ?? 'Cód. '.$code;
            $source['amendment_year'] = $registration?->amendment_year ?? $fiscalYear;
            $source['operation'] = 'monthly_reconciliation';
            if ($matches->count() > 1) {
                $source['link_issue'] = 'Mais de um cadastro local utiliza este Código de Aplicação.';
            }

            $this->countStatus($stats, $status);
            $items[] = $this->item($municipality, $registration, $status, $source, $local, $differences);
        }

        return [
            'document_type' => AudespHomologationBatch::TYPE_MONTHLY_FINANCIAL,
            'items' => $items,
            'stats' => $stats,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function monthlySourceSnapshots(DOMXPath $xpath): array
    {
        $snapshots = [];
        $commitmentCodes = [];
        $availableByCode = [];
        $availableRowsByCode = [];

        foreach ($this->nodes($xpath, 'DotacaoOrcamentaria') as $node) {
            $code = $this->normalizeCode($this->value($xpath, $node, 'CodigoAplicacao'));
            if ($code === '') {
                continue;
            }
            $account = $this->value($xpath, $node, 'ContaContabil');
            $amount = $this->movementAmount($xpath, $node);
            if ($account === '522910100' || $account === '522910200') {
                $snapshot = &$this->sourceSnapshot($snapshots, $code);
                $snapshot['pre_commitment_amount'] += $amount;
                $snapshot['source_rows'][] = $this->sourceRow($xpath, $node, 'dotacao', $account, $amount);
                unset($snapshot);
            } elseif ($account === '522910300') {
                $snapshot = &$this->sourceSnapshot($snapshots, $code);
                $snapshot['pre_commitment_amount'] -= $amount;
                $snapshot['source_rows'][] = $this->sourceRow($xpath, $node, 'dotacao', $account, -$amount);
                unset($snapshot);
            } elseif ($account === '622110000') {
                $availableByCode[$code] = ($availableByCode[$code] ?? 0) + $this->movementBalance($xpath, $node);
                $availableRowsByCode[$code][] = $this->sourceRow($xpath, $node, 'credito_disponivel', $account, $amount);
            }
        }

        foreach ($this->nodes($xpath, 'EmissaoEmpenho') as $node) {
            $code = $this->normalizeCode($this->value($xpath, $node, 'CodigoAplicacao'));
            if ($code === '') {
                continue;
            }
            $number = $this->value($xpath, $node, 'NumeroEmpenho');
            $commitmentCodes[$this->commitmentIdentity($xpath, $node, $number)] = $code;
            $commitmentCodes[$this->commitmentIdentity($xpath, null, $number)] = $code;
            $account = $this->value($xpath, $node, 'ContaContabil');
            if ($account !== '522920101') {
                continue;
            }
            $snapshot = &$this->sourceSnapshot($snapshots, $code);
            $amount = $this->movementAmount($xpath, $node);
            $snapshot['committed_amount'] += $amount;
            $snapshot['source_rows'][] = $this->sourceRow($xpath, $node, 'empenho', $account, $amount, $number);
            unset($snapshot);
        }

        $eventTypes = [
            'ReforcoEmpenho' => ['field' => 'committed_amount', 'sign' => 1, 'label' => 'reforco', 'accounts' => ['522920102']],
            'AnulacaoEmpenho' => ['field' => 'committed_amount', 'sign' => -1, 'label' => 'anulacao', 'accounts' => ['522920103', '522920104']],
            'LiquidacaoEmpenho' => ['field' => 'liquidated_amount', 'sign' => null, 'label' => 'liquidacao', 'accounts' => ['622920103', '622920105']],
            'PagamentoEmpenho' => ['field' => 'paid_amount', 'sign' => 1, 'label' => 'pagamento', 'accounts' => ['622920104']],
        ];
        foreach ($eventTypes as $element => $definition) {
            foreach ($this->nodes($xpath, $element) as $node) {
                $number = $this->value($xpath, $node, 'NumeroEmpenho');
                $code = $commitmentCodes[$this->commitmentIdentity($xpath, $node, $number)]
                    ?? $commitmentCodes[$this->commitmentIdentity($xpath, null, $number)]
                    ?? null;
                if ($code === null) {
                    continue;
                }
                $account = $this->value($xpath, $node, 'ContaContabil');
                if (! in_array($account, $definition['accounts'], true)) {
                    continue;
                }
                $sign = $definition['sign'] ?? $this->eventSign($element, $account);
                $amount = $this->movementAmount($xpath, $node);
                $snapshot = &$this->sourceSnapshot($snapshots, $code);
                $snapshot[$definition['field']] += $amount * $sign;
                $snapshot['source_rows'][] = $this->sourceRow($xpath, $node, $definition['label'], $account, $amount * $sign, $number);
                unset($snapshot);
            }
        }

        foreach ($availableByCode as $code => $available) {
            if (! isset($snapshots[$code])) {
                continue;
            }
            $snapshots[$code]['available_appropriation'] = $available;
            array_push($snapshots[$code]['source_rows'], ...$availableRowsByCode[$code]);
        }

        foreach ($snapshots as &$snapshot) {
            foreach (array_keys(self::FINANCIAL_FIELD_LABELS) as $field) {
                $snapshot[$field] = $this->money($snapshot[$field]);
            }
            $snapshot['available_appropriation'] = $this->money($snapshot['available_appropriation']);
        }

        return $snapshots;
    }

    /** @param array<string, array<string, mixed>> $snapshots @return array<string, mixed> */
    private function &sourceSnapshot(array &$snapshots, string $code): array
    {
        if (! isset($snapshots[$code])) {
            $snapshots[$code] = $this->emptyFinancialSource($code);
        }

        return $snapshots[$code];
    }

    /** @return array<string, mixed> */
    private function emptyFinancialSource(string $code): array
    {
        return [
            'application_code' => $code,
            'pre_commitment_amount' => 0.0,
            'available_appropriation' => 0.0,
            'committed_amount' => 0.0,
            'liquidated_amount' => 0.0,
            'paid_amount' => 0.0,
            'source_rows' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function financialLocalSnapshot(AudespAmendmentRegistration $registration, int $year, int $month): array
    {
        $amendment = $registration->amendment;
        $proposal = $amendment?->legislativeProposal;
        $reservationMatches = $proposal?->budget_reserved_at
            && $this->dateMatches($proposal->budget_reserved_at, $year, $month);
        $commitments = $amendment?->financialCommitments
            ?->filter(fn ($record) => $this->dateMatches($record->committed_at, $year, $month)) ?? collect();
        $liquidations = $amendment?->financialLiquidations
            ?->filter(fn ($record) => $this->dateMatches($record->liquidated_at, $year, $month)) ?? collect();
        $payments = $amendment?->financialPayments
            ?->filter(fn ($record) => $this->dateMatches($record->paid_at, $year, $month)) ?? collect();

        return [
            'application_code' => $registration->application_code,
            'pre_commitment_amount' => $this->money($reservationMatches ? $proposal->budget_reserved_amount : 0),
            'committed_amount' => $this->money($commitments->sum(fn ($record) => $record->status === FinancialCommitment::STATUS_CANCELLED ? 0 : (float) $record->committed_amount)),
            'liquidated_amount' => $this->money($liquidations->sum(fn ($record) => (float) $record->amount)),
            'paid_amount' => $this->money($payments->sum(fn ($record) => (float) $record->amount)),
            'budget_reservation_number' => $reservationMatches ? $proposal->budget_reservation_number : null,
            'commitments' => $commitments->pluck('commitment_number')->values()->all(),
            'liquidations' => $liquidations->pluck('liquidation_reference')->values()->all(),
            'payments' => $payments->pluck('payment_reference')->values()->all(),
            'period_basis' => $month <= 12 ? sprintf('%02d/%d', $month, $year) : 'Encerramento '.$year,
        ];
    }

    /** @return array<int, array{field: string, label: string, source: mixed, local: mixed}> */
    private function financialDifferences(array $source, array $local): array
    {
        $differences = [];
        foreach (self::FINANCIAL_FIELD_LABELS as $field => $label) {
            if (abs((float) ($source[$field] ?? 0) - (float) ($local[$field] ?? 0)) > 0.009) {
                $differences[] = [
                    'field' => $field,
                    'label' => $label,
                    'source' => $this->money($source[$field] ?? 0),
                    'local' => $this->money($local[$field] ?? 0),
                ];
            }
        }

        return $differences;
    }

    private function hasFinancialActivity(array $snapshot): bool
    {
        foreach (array_keys(self::FINANCIAL_FIELD_LABELS) as $field) {
            if (abs((float) ($snapshot[$field] ?? 0)) > 0.009) {
                return true;
            }
        }

        return false;
    }

    private function dateMatches(mixed $date, int $year, int $month): bool
    {
        if ($date === null || (int) $date->year !== $year) {
            return false;
        }

        return $month > 12 || (int) $date->month === $month;
    }

    /** @return array<string, mixed> */
    private function registrationSourceSnapshot(DOMXPath $xpath, DOMElement $node): array
    {
        return [
            'scope' => $this->value($xpath, $node, 'AmbitoEmenda'),
            'amendment_type' => (int) $this->value($xpath, $node, 'TipoEmenda'),
            'legal_basis' => $this->value($xpath, $node, 'FundamentoLegal'),
            'proponent_name' => $this->value($xpath, $node, 'ParlamentarProponente'),
            'amendment_number' => $this->value($xpath, $node, 'NumeroEmenda'),
            'amendment_year' => (int) $this->value($xpath, $node, 'AnoEmenda'),
            'object' => $this->value($xpath, $node, 'ObjetoEmenda'),
            'purpose' => $this->value($xpath, $node, 'FinalidadeEmenda'),
            'government_function' => $this->value($xpath, $node, 'Funcao'),
            'government_subfunctions' => $this->values($xpath, $node, 'SubFuncao'),
            'destination' => $this->value($xpath, $node, 'DestinacaoEmenda'),
            'bank_account_opened' => $this->value($xpath, $node, 'AberturaContaBancaria'),
            'application_code' => $this->value($xpath, $node, 'CodigoAplicacao'),
            'operation' => $this->value($xpath, $node, 'OperacaoCadastro'),
        ];
    }

    /** @return array<string, mixed> */
    private function registrationLocalSnapshot(AudespAmendmentRegistration $registration): array
    {
        return [
            'scope' => $registration->scope,
            'amendment_type' => $registration->amendment_type,
            'legal_basis' => $registration->legal_basis,
            'proponent_name' => $registration->proponent_name,
            'amendment_number' => $registration->amendment_number,
            'amendment_year' => $registration->amendment_year,
            'object' => $registration->object,
            'purpose' => $registration->purpose,
            'government_function' => $registration->government_function,
            'government_subfunctions' => array_values($registration->government_subfunctions ?? []),
            'destination' => $registration->destination,
            'bank_account_opened' => $registration->bank_account_opened ? 'S' : 'N',
            'application_code' => $registration->application_code,
        ];
    }

    /** @param array<string, string> $labels @return array<int, array{field: string, label: string, source: mixed, local: mixed}> */
    private function differences(array $source, array $local, array $labels): array
    {
        $differences = [];
        foreach ($labels as $field => $label) {
            if ($this->comparable($source[$field] ?? null) !== $this->comparable($local[$field] ?? null)) {
                $differences[] = [
                    'field' => $field,
                    'label' => $label,
                    'source' => $source[$field] ?? null,
                    'local' => $local[$field] ?? null,
                ];
            }
        }

        return $differences;
    }

    /** @return array<string, mixed> */
    private function item(
        Municipality $municipality,
        ?AudespAmendmentRegistration $registration,
        string $status,
        array $source,
        ?array $local,
        array $differences,
    ): array {
        return [
            'municipality_id' => $municipality->id,
            'parliamentary_amendment_id' => $registration?->parliamentary_amendment_id,
            'audesp_amendment_registration_id' => $registration?->id,
            'status' => $status,
            'source_scope' => $source['scope'] ?: null,
            'source_amendment_number' => $source['amendment_number'] ?: null,
            'source_amendment_year' => $source['amendment_year'] ?: null,
            'operation' => $source['operation'] ?: null,
            'source_snapshot' => $source,
            'local_snapshot' => $local,
            'differences' => $differences ?: null,
        ];
    }

    /** @return array{total: int, matched: int, divergent: int, unmatched: int} */
    private function emptyStats(): array
    {
        return ['total' => 0, 'matched' => 0, 'divergent' => 0, 'unmatched' => 0];
    }

    /** @param array{total: int, matched: int, divergent: int, unmatched: int} $stats */
    private function countStatus(array &$stats, string $status): void
    {
        $stats['total']++;
        $stats[$status]++;
    }

    /** @return array<int, DOMElement> */
    private function nodes(DOMXPath $xpath, string $name): array
    {
        $results = $xpath->query('//*[local-name()="'.$name.'"]');
        $nodes = [];
        foreach ($results ?: [] as $result) {
            if ($result instanceof DOMElement) {
                $nodes[] = $result;
            }
        }

        return $nodes;
    }

    private function movementAmount(DOMXPath $xpath, DOMElement $node): float
    {
        $credit = abs((float) $this->descendantValue($xpath, $node, 'MovimentoCredito'));
        $debit = abs((float) $this->descendantValue($xpath, $node, 'MovimentoDebito'));

        return max($credit, $debit);
    }

    private function movementBalance(DOMXPath $xpath, DOMElement $node): float
    {
        return abs((float) $this->descendantValue($xpath, $node, 'SaldoFinal'));
    }

    /** @return array<string, mixed> */
    private function sourceRow(
        DOMXPath $xpath,
        DOMElement $node,
        string $type,
        string $account,
        float $amount,
        ?string $commitmentNumber = null,
    ): array {
        return [
            'type' => $type,
            'account' => $account,
            'commitment_number' => $commitmentNumber,
            'amount' => $this->money($amount),
            'credit' => $this->money($this->descendantValue($xpath, $node, 'MovimentoCredito')),
            'debit' => $this->money($this->descendantValue($xpath, $node, 'MovimentoDebito')),
        ];
    }

    private function eventSign(string $element, string $account): int
    {
        return match (true) {
            $element === 'LiquidacaoEmpenho' && $account === '622920105' => -1,
            default => 1,
        };
    }

    private function commitmentIdentity(DOMXPath $xpath, ?DOMElement $node, string $number): string
    {
        $entity = $node ? $this->value($xpath, $node, 'EntidadeOrcamentaria') : '';

        return $this->normalize($entity).'|'.$this->normalize($number);
    }

    private function comparable(mixed $value): string
    {
        if (is_array($value)) {
            $value = array_map(fn ($item) => $this->normalize((string) $item), $value);
            sort($value);

            return implode('|', $value);
        }

        return $this->normalize((string) $value);
    }

    private function identity(mixed $scope, mixed $number, mixed $year): string
    {
        return mb_strtoupper($this->normalize((string) $scope)).'|'
            .mb_strtoupper($this->normalize((string) $number)).'|'.(int) $year;
    }

    private function normalizeCode(mixed $value): string
    {
        return preg_replace('/\s+/', '', trim((string) $value)) ?? '';
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function value(DOMXPath $xpath, DOMElement $node, string $name): string
    {
        $result = $xpath->query('./*[local-name()="'.$name.'"]', $node)?->item(0);

        return $result ? $this->normalize($result->textContent) : '';
    }

    private function descendantValue(DOMXPath $xpath, DOMElement $node, string $name): string
    {
        $result = $xpath->query('.//*[local-name()="'.$name.'"]', $node)?->item(0);

        return $result ? $this->normalize($result->textContent) : '';
    }

    /** @return array<int, string> */
    private function values(DOMXPath $xpath, DOMElement $node, string $name): array
    {
        $results = $xpath->query('./*[local-name()="'.$name.'"]', $node);
        $values = [];
        foreach ($results ?: [] as $result) {
            $values[] = $this->normalize($result->textContent);
        }

        return array_values(array_unique(array_filter($values)));
    }
}
