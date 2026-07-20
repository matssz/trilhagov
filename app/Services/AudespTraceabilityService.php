<?php

namespace App\Services;

use App\Models\AudespAmendmentRegistration;
use App\Models\ParliamentaryAmendment;
use DOMDocument;

class AudespTraceabilityService
{
    public const XSD_URL = 'https://www.tce.sp.gov.br/audesp/documentacao/emendas-parlamentares-cadastros-contabeis-schema-xsd-2026';

    public const DEADLINE_SOURCE_URL = 'https://www.tce.sp.gov.br/legislacao/comunicado/emendas-parlamentares-balancete-contabil-abril-2026';

    private const APPLICATION_CODE_PATTERN = '/^(800|801|802|803|804|900|901|902|903)[0-9]{1,4}$/';

    private const SUBFUNCTION_CODES = '031,032,061,062,091,092,121,122,123,124,125,126,127,128,129,130,131,151,152,153,181,182,183,211,212,241,242,243,244,245,246,271,272,273,274,301,302,303,304,305,306,331,332,333,334,361,362,363,364,365,366,367,368,391,392,421,422,423,451,452,453,481,482,511,512,541,542,543,544,545,571,572,573,601,602,603,604,605,606,607,608,609,631,632,661,662,663,664,665,691,692,693,694,695,721,722,751,752,753,754,781,782,783,784,785,811,812,813,841,842,843,844,845,846,847,997,999';

    /** @return array<string, string> */
    public static function governmentFunctions(): array
    {
        return [
            '01' => 'Legislativa',
            '02' => 'Judiciária',
            '03' => 'Essencial à Justiça',
            '04' => 'Administração',
            '05' => 'Defesa Nacional',
            '06' => 'Segurança Pública',
            '07' => 'Relações Exteriores',
            '08' => 'Assistência Social',
            '09' => 'Previdência Social',
            '10' => 'Saúde',
            '11' => 'Trabalho',
            '12' => 'Educação',
            '13' => 'Cultura',
            '14' => 'Direitos da Cidadania',
            '15' => 'Urbanismo',
            '16' => 'Habitação',
            '17' => 'Saneamento',
            '18' => 'Gestão Ambiental',
            '19' => 'Ciência e Tecnologia',
            '20' => 'Agricultura',
            '21' => 'Organização Agrária',
            '22' => 'Indústria',
            '23' => 'Comércio e Serviços',
            '24' => 'Comunicações',
            '25' => 'Energia',
            '26' => 'Transporte',
            '27' => 'Desporto e Lazer',
            '28' => 'Encargos Especiais',
        ];
    }

    /** @return array<int, string> */
    public static function governmentSubfunctionCodes(): array
    {
        return explode(',', self::SUBFUNCTION_CODES);
    }

    /**
     * @return array{ready: bool, score: int, blockers: array<int, string>, warnings: array<int, string>, checks: array<int, array{label: string, passed: bool}>}
     */
    public function evaluate(ParliamentaryAmendment $amendment): array
    {
        $registration = $amendment->audespRegistration;
        $blockers = [];
        $warnings = [];
        $checks = [];

        $this->check($checks, $blockers, $amendment->supportsTcespCompliance(), 'Emenda municipal paulista no alcance do TCESP', 'O cadastro Audesp deste módulo é destinado às emendas municipais paulistas, exceto a Capital.');
        $this->check($checks, $blockers, $registration !== null, 'Cadastro contábil preenchido', 'Preencha o cadastro contábil da emenda.');

        if ($registration !== null) {
            $subfunctions = $registration->government_subfunctions ?? [];
            $validSubfunctions = $subfunctions !== []
                && collect($subfunctions)->every(fn ($code) => in_array((string) $code, self::governmentSubfunctionCodes(), true));

            $this->check($checks, $blockers, $registration->scope === 'M', 'Âmbito municipal', 'O XSD deve receber o âmbito M para esta emenda.');
            $this->check($checks, $blockers, in_array($registration->amendment_type, array_keys(AudespAmendmentRegistration::amendmentTypes()), true), 'Tipo de emenda válido', 'Selecione um tipo admitido no XSD 2026_A.');
            $this->check($checks, $blockers, in_array($registration->legal_basis, array_keys(AudespAmendmentRegistration::legalBases()), true), 'Fundamento legal válido', 'Selecione Lei, Decreto, Resolução ou Portaria.');
            $this->check($checks, $blockers, mb_strlen($registration->proponent_name) >= 10 && mb_strlen($registration->proponent_name) <= 100, 'Proponente entre 10 e 100 caracteres', 'Revise o nome do parlamentar proponente.');
            $this->check($checks, $blockers, mb_strlen($registration->amendment_number) >= 3 && mb_strlen($registration->amendment_number) <= 30, 'Número entre 3 e 30 caracteres', 'Revise o número oficial da emenda.');
            $this->check($checks, $blockers, $registration->amendment_year >= 2000 && $registration->amendment_year <= 2099, 'Exercício válido', 'Revise o exercício da emenda.');
            $this->check($checks, $blockers, mb_strlen($registration->object) >= 10 && mb_strlen($registration->object) <= 1000, 'Objeto entre 10 e 1.000 caracteres', 'O objeto precisa respeitar o limite do XSD.');
            $this->check($checks, $blockers, mb_strlen($registration->purpose) >= 10 && mb_strlen($registration->purpose) <= 1000, 'Finalidade entre 10 e 1.000 caracteres', 'A finalidade precisa respeitar o limite do XSD.');
            $this->check($checks, $blockers, array_key_exists($registration->government_function, self::governmentFunctions()), 'Função de governo oficial', 'Selecione uma função prevista na tabela auxiliar Audesp.');
            $this->check($checks, $blockers, $validSubfunctions, 'Subfunções previstas na tabela auxiliar', 'Informe ao menos uma subfunção oficial, separando códigos por vírgula.');
            $this->check($checks, $blockers, in_array($registration->destination, ['C', 'I'], true), 'Destinação válida', 'Selecione Custeio ou Investimento.');
            $this->check($checks, $blockers, preg_match(self::APPLICATION_CODE_PATTERN, $registration->application_code) === 1, 'Código de aplicação no padrão 2026_A', 'Use o código combinado: prefixo 800 a 804 ou 900 a 903, seguido de 1 a 4 dígitos.');

            $accountTraceable = $registration->bank_account_opened
                ? filled($amendment->bank_account_number)
                : $amendment->bank_tracking_type === 'municipal_direct_codes'
                    && in_array($amendment->funding_source_code, ['08', '98'], true)
                    && filled($amendment->application_code_fixed)
                    && filled($amendment->application_code_variable);
            $this->check($checks, $blockers, $accountTraceable, 'Rastreabilidade bancária ou contábil', $registration->bank_account_opened
                ? 'Informe a conta bancária específica na emenda.'
                : 'Na execução direta, informe Fonte 08/98 e os códigos de aplicação fixo e variável na emenda.');

            if ($registration->amendment_year <= 2025) {
                $reclassified = $registration->prior_balance_reclassified
                    && filled($registration->reclassification_reference)
                    && $registration->reclassified_at !== null;
                $this->check($checks, $blockers, $reclassified, 'Saldo anterior reclassificado', 'Para emenda de 2025 ou anterior, registre a movimentação contábil de reclassificação e sua data.');
            }

            if ($registration->amendment_type === 5) {
                $warnings[] = 'O XSD admite o tipo 5, mas o comentário técnico publicado descreve apenas os tipos 1 a 4. Confirme o enquadramento com a contabilidade antes do envio.';
            }
        }

        $unlinkedPayments = $amendment->financialPayments->whereNull('financial_liquidation_id')->count();
        if ($unlinkedPayments > 0) {
            $blockers[] = "Há {$unlinkedPayments} pagamento(s) sem liquidação vinculada.";
        }

        $totalChecks = max(1, count($checks));
        $passedChecks = collect($checks)->where('passed', true)->count();

        return [
            'ready' => $blockers === [],
            'score' => (int) round(($passedChecks / $totalChecks) * 100),
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => $warnings,
            'checks' => $checks,
        ];
    }

    public function buildInternalPreview(ParliamentaryAmendment $amendment): string
    {
        $registration = $amendment->audespRegistration;
        abort_unless($registration !== null, 422);

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->createElement('PreviaInternaTrilhaGov');
        $root->setAttribute('naoTransmitir', 'true');
        $root->setAttribute('schemaReferencia', AudespAmendmentRegistration::SCHEMA_VERSION);
        $xml->appendChild($root);
        $root->appendChild($xml->createComment('Prévia interna. Não é um arquivo oficial de remessa nem realiza transmissão ao TCESP.'));
        $record = $xml->createElement('EmendasParlamentares');
        $root->appendChild($record);

        $this->element($xml, $record, 'AmbitoEmenda', $registration->scope);
        $this->element($xml, $record, 'TipoEmenda', (string) $registration->amendment_type);
        $this->element($xml, $record, 'FundamentoLegal', $registration->legal_basis);
        $this->element($xml, $record, 'ParlamentarProponente', $registration->proponent_name);
        $this->element($xml, $record, 'NumeroEmenda', $registration->amendment_number);
        $this->element($xml, $record, 'AnoEmenda', (string) $registration->amendment_year);
        $this->element($xml, $record, 'ObjetoEmenda', $registration->object);
        $this->element($xml, $record, 'FinalidadeEmenda', $registration->purpose);
        $this->element($xml, $record, 'Funcao', $registration->government_function);
        foreach ($registration->government_subfunctions as $subfunction) {
            $this->element($xml, $record, 'SubFuncao', (string) $subfunction);
        }
        $this->element($xml, $record, 'DestinacaoEmenda', $registration->destination);
        $this->element($xml, $record, 'AberturaContaBancaria', $registration->bank_account_opened ? 'S' : 'N');
        $this->element($xml, $record, 'CodigoAplicacao', $registration->application_code);

        return (string) $xml->saveXML();
    }

    /** @param array<int, array{label: string, passed: bool}> $checks @param array<int, string> $blockers */
    private function check(array &$checks, array &$blockers, bool $passed, string $label, string $failure): void
    {
        $checks[] = ['label' => $label, 'passed' => $passed];
        if (! $passed) {
            $blockers[] = $failure;
        }
    }

    private function element(DOMDocument $xml, \DOMElement $parent, string $name, string $value): void
    {
        $parent->appendChild($xml->createElement($name, htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8')));
    }
}
