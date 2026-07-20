# Audesp e rastreabilidade contábil municipal

## Objetivo

O módulo reduz erros antes do envio mensal da contabilidade de municípios
paulistas. Ele organiza o Cadastro de Emendas Parlamentares, confere os campos do
XSD `2026_A` e torna visível a cadeia empenho, liquidação e pagamento.

O TrilhaGov não substitui o Siafic, não altera lançamentos contábeis e não transmite
dados ao TCESP. A saída XML é uma prévia interna para conferência e homologação.

## Base oficial adotada

- Resolução TCESP 17/2025: transparência e rastreabilidade das emendas;
- Comunicado Audesp 55/2025: combinação dos códigos de aplicação e criação do
  cadastro específico;
- Comunicado Audesp 09/2026: exceção da conta individual na execução direta pela
  Prefeitura, condicionada à Fonte e aos códigos de aplicação;
- Comunicado Audesp 17/2026: cadastro prévio ao balancete de abril de 2026;
- Comunicado Audesp 19/2026 e XSD publicado em 22/04/2026;
- Comunicado Audesp 24/2026: regra `47.4.63` aplicada ao saldo final e rejeição do
  balancete quando houver código vinculado a emenda sem cadastro;
- Manual de Emendas Orçamentárias Impositivas Municipais, capítulos de execução
  da despesa, codificação e cadastro Audesp.

## O que o sistema confere

O cadastro usa os campos oficiais `AmbitoEmenda`, `TipoEmenda`,
`FundamentoLegal`, `ParlamentarProponente`, `NumeroEmenda`, `AnoEmenda`,
`ObjetoEmenda`, `FinalidadeEmenda`, `Funcao`, `SubFuncao`, `DestinacaoEmenda`,
`AberturaContaBancaria` e `CodigoAplicacao`.

Também são conferidos:

- tamanho mínimo e máximo dos textos definido no XSD;
- função e subfunções previstas na tabela auxiliar `2026_A`;
- padrão do código combinado de aplicação;
- Fonte `08` ou `98` e códigos fixo/variável quando a Prefeitura usa a exceção de
  execução direta sem conta individual;
- conta específica quando declarada como aberta;
- evidência da reclassificação para saldo de 2025 ou anterior;
- existência de liquidação antes do pagamento;
- limites `pago <= liquidado <= empenhado`.

## Operação municipal

1. A área orçamentária confirma os dados da emenda e os códigos com a
   contabilidade.
2. O operador preenche a aba **Audesp** e salva o diagnóstico.
3. A área executora registra cada liquidação com nota fiscal ou medição e a
   referência do ateste ou termo de recebimento.
4. O pagamento é vinculado a uma liquidação com saldo disponível.
5. Sem bloqueios, o sistema libera a prévia XML interna.
6. Contador e fornecedor do Siafic comparam a prévia com o arquivo produzido pelo
   sistema contábil.
7. O recibo e o retorno do ambiente Audesp devem ser preservados no dossiê quando
   a integração homologada for implantada.

Liquidações e pagamentos são imutáveis no TrilhaGov. Uma correção contábil deve
ser realizada no sistema competente e preservada por novo registro ou evidência,
sem apagar o histórico original.

## Próxima homologação

- obter um cadastro e um balancete reais anonimizados do município piloto;
- comparar os identificadores usados pelo Siafic com os campos do TrilhaGov;
- validar o tratamento do tipo `5`, permitido no XSD mas não descrito no comentário
  que enumera os tipos `1` a `4`;
- validar códigos de aplicação e operações de inclusão/alteração/exclusão com o
  contador;
- submeter um arquivo gerado pelo Siafic ao ambiente de testes do Audesp;
- registrar recibo, rejeições e correções sem permitir transmissão automática antes
  do aceite formal.

## Fontes

- <https://www.tce.sp.gov.br/audesp/documentacao/emendas-parlamentares-cadastros-contabeis-schema-xsd-2026>
- <https://www.tce.sp.gov.br/legislacao/comunicado/emendas-parlamentares-adequacao-resolucao-tce-sp-172025>
- <https://www.tce.sp.gov.br/legislacao/comunicado/fiscalizacao-transparencia-e-rastreabilidade-emendas-parlamentares-municipais>
- <https://www.tce.sp.gov.br/legislacao/comunicado/cadastro-contabil-emendas-parlamentares-area-municipal>
- <https://www.tce.sp.gov.br/legislacao/comunicado/emendas-parlamentares-balancete-contabil-abril-2026>
