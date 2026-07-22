# Homologação Audesp municipal

## Responsabilidade do módulo

A Homologação Audesp organiza a conferência entre arquivos XML produzidos pelo
Siafic e os dados preparados no TrilhaGov. Ela reconhece o Cadastro Contábil de
Emendas e o Detalhe do Movimento Mensal. O módulo não transmite dados ao TCESP,
não acessa credenciais do órgão e não altera a contabilidade municipal.

A transmissão oficial continua no Coletor Audesp. Segundo a orientação do TCESP,
o operador precisa do Coletor 3.0 ou superior, da permissão para transmissão de
pacotes e do papel correspondente à fase que será enviada.

## Fluxo implementado

1. O contador ou fornecedor do Siafic gera o XML de Cadastros Contábeis ou do
   Detalhe do Movimento Mensal.
2. Um gestor ou editor registra exercício, competência, fornecedor e arquivo.
3. No Cadastro Contábil, o TrilhaGov lê `EmendasParlamentares` e compara os campos
   com o cadastro municipal do XSD `2026_A`.
4. No Movimento Mensal, o vínculo é feito exclusivamente pelo `CodigoAplicacao`.
   O sistema soma os movimentos líquidos de pré-empenho, emissão/reforço/anulação
   de empenho, liquidação/estorno e pagamento/estorno.
5. A comparação local usa a reserva originada no Portal Legislativo e os eventos
   financeiros da mesma competência. Nas competências 13 e 14, usa o exercício.
6. Registros coincidentes liberam a etapa **Pronto para o Coletor**.
7. Registros divergentes geram alerta de integridade e ação crítica na Central de
   Trabalho. O XML original permanece imutável.
8. Após corrigir a origem, o operador pode reconferir o mesmo arquivo enquanto ele
   ainda não foi transmitido.
9. A transmissão externa é registrada com data e identificador do pacote ou
   protocolo, podendo receber uma evidência inicial.
10. Cada consulta posterior é registrada como recebido, validado sem erro,
   rejeitado ou armazenado, sempre com evidência anexada.
11. Uma rejeição gera alerta e ação crítica. O reenvio nasce como um novo lote
   vinculado à tentativa anterior.

Os nomes dos retornos acompanham a terminologia publicada pelo TCESP. No
TrilhaGov, o estado é informado manualmente pelo operador e só vale como evidência
quando acompanhado do arquivo ou recibo obtido no ambiente oficial.

## Critérios da conciliação financeira

- O vínculo nunca é inferido por nome, fornecedor, objeto ou valor.
- Código de aplicação ausente, desconhecido ou ambíguo permanece sem vínculo.
- Cada valor guarda o movimento de crédito e débito de origem no snapshot do lote.
- Para evitar dupla contagem, os totais usam as contas orçamentárias canônicas de
  cada evento; contas patrimoniais e de controle repetidas não entram na soma.
- A tolerância monetária é de um centavo.
- A reserva legislativa é comparada ao pré-empenho contábil como controle de
  conciliação. A equivalência operacional deve ser confirmada pelo contador e pelo
  fornecedor do Siafic do Município piloto.
- O saldo de crédito disponível é preservado no snapshot para análise, mas não é
  tratado como valor da emenda nem sobrescreve a reserva.

## Segurança e auditoria

- XML e evidências ficam no armazenamento privado configurado pela aplicação.
- O parser bloqueia `DOCTYPE`, não expande entidades e desabilita rede durante a
  leitura do XML.
- Cada arquivo recebe hash SHA-256; o mesmo município não registra duas vezes o
  mesmo XML.
- Nomes originais, tamanho, tipo, responsável e data são preservados.
- Lotes e downloads respeitam o município ativo e os perfis de acesso.
- Apenas gestor e editor registram arquivos e retornos; consulta e auditoria têm
  acesso somente de leitura.
- Formulários usam token de uso único e bloqueio contra cliques repetidos.
- A comparação CSV neutraliza conteúdo que poderia ser interpretado como fórmula.

## Homologação de campo ainda necessária

Antes de vender o recurso como integração homologada, o município piloto deve:

- fornecer XMLs reais anonimizados de cadastro e movimento mensal;
- confirmar a estrutura completa do pacote e o significado de `OperacaoCadastro`;
- transmitir pelo Coletor usando o ambiente e as permissões definidos pelo TCESP;
- anexar um recibo real, uma rejeição real anonimizada e um resultado armazenado;
- validar com o contador se o pré-empenho representa a reserva adotada no fluxo
  local e se cada divergência exige correção no Siafic, no
  cadastro operacional ou em ambos;
- documentar o procedimento interno e os responsáveis por geração, conferência,
  transmissão, correção e aceite.

## Fontes oficiais

- XSD de Cadastros Contábeis de Emendas Parlamentares 2026:
  <https://www.tce.sp.gov.br/audesp/documentacao/emendas-parlamentares-cadastros-contabeis-schema-xsd-2026>
- XSDs Audesp de Balancetes 2026:
  <https://www.tce.sp.gov.br/audesp/documentacao/xsds-audesp-balancetes-2026>
- Resolução TCESP nº 17/2025:
  <https://www.tce.sp.gov.br/sites/default/files/legislacao/RESOLU%C3%87%C3%83O-17-2025-EMENDAS%20PARLAMENTARES%20-%20vers%C3%A3o%20final.pdf>
- Orientações do Coletor Audesp:
  <https://www.tce.sp.gov.br/audesp/coletor>
- Significado dos estados de documentos no Audesp:
  <https://www.tce.sp.gov.br/faq/qual-significado-status-documentos>
- Calendário de obrigações Audesp 2026, Comunicado SDG 67/2025:
  <https://www.tce.sp.gov.br/sites/default/files/legislacao/Comunicado%20SDG%2067-2025%20-%20Calendario%20AUDESP%202026_disponibilizado%20em%2029%20de%20novembro%20de%202025.pdf>
