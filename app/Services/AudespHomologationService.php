<?php

namespace App\Services;

use App\Models\AudespAmendmentRegistration;
use App\Models\AudespHomologationItem;
use App\Models\Municipality;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;

class AudespHomologationService
{
    private const FIELD_LABELS = [
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

    /**
     * @return array{items: array<int, array<string, mixed>>, stats: array{total: int, matched: int, divergent: int, unmatched: int}}
     */
    public function inspect(string $contents, Municipality $municipality): array
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

        $xpath = new DOMXPath($xml);
        $nodes = $xpath->query('//*[local-name()="EmendasParlamentares"]');
        if ($nodes === false || $nodes->length === 0) {
            throw ValidationException::withMessages([
                'source_file' => 'Nenhum registro EmendasParlamentares foi encontrado no XML do Siafic.',
            ]);
        }

        $registrations = $municipality->audespAmendmentRegistrations()
            ->with('amendment:id,reference')
            ->get()
            ->keyBy(fn (AudespAmendmentRegistration $registration) => $this->identity(
                $registration->scope,
                $registration->amendment_number,
                $registration->amendment_year,
            ));
        $items = [];
        $stats = ['total' => 0, 'matched' => 0, 'divergent' => 0, 'unmatched' => 0];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $source = $this->sourceSnapshot($xpath, $node);
            $registration = $registrations->get($this->identity(
                $source['scope'],
                $source['amendment_number'],
                $source['amendment_year'],
            ));
            $local = $registration ? $this->localSnapshot($registration) : null;
            $differences = $local ? $this->differences($source, $local) : [];
            $status = $registration === null
                ? AudespHomologationItem::STATUS_UNMATCHED
                : ($differences === [] ? AudespHomologationItem::STATUS_MATCHED : AudespHomologationItem::STATUS_DIVERGENT);

            $stats['total']++;
            $stats[$status]++;
            $items[] = [
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

        return ['items' => $items, 'stats' => $stats];
    }

    /** @return array<string, mixed> */
    private function sourceSnapshot(DOMXPath $xpath, DOMElement $node): array
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
    private function localSnapshot(AudespAmendmentRegistration $registration): array
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

    /** @param array<string, mixed> $source @param array<string, mixed> $local @return array<int, array{field: string, label: string, source: mixed, local: mixed}> */
    private function differences(array $source, array $local): array
    {
        $differences = [];
        foreach (self::FIELD_LABELS as $field => $label) {
            $sourceValue = $this->comparable($source[$field] ?? null);
            $localValue = $this->comparable($local[$field] ?? null);
            if ($sourceValue !== $localValue) {
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

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function value(DOMXPath $xpath, DOMElement $node, string $name): string
    {
        $result = $xpath->query('./*[local-name()="'.$name.'"]', $node)?->item(0);

        return $result ? $this->normalize($result->textContent) : '';
    }

    /** @return array<int, string> */
    private function values(DOMXPath $xpath, DOMElement $node, string $name): array
    {
        $results = $xpath->query('./*[local-name()="'.$name.'"]', $node);
        $values = [];
        if ($results !== false) {
            foreach ($results as $result) {
                $values[] = $this->normalize($result->textContent);
            }
        }

        return array_values(array_unique(array_filter($values)));
    }
}
