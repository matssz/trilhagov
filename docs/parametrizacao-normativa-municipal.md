# Parametrização normativa municipal

## Finalidade

O módulo registra, por município e exercício, as regras locais que orientam o
fluxo das emendas impositivas. Ele evita que percentuais, prazos e exigências
sejam fixados no código como se fossem iguais em todas as prefeituras.

Uma configuração possui versões `Em preparação`, `Vigente` e `Arquivada`. A
versão vigente é imutável. Uma alteração posterior cria uma nova revisão com
cópia dos parâmetros e instrumentos, preservando o histórico usado em cada
decisão municipal.

## Fontes oficiais consultadas

- Constituição Federal, especialmente a disciplina orçamentária dos artigos
  165 e seguintes: <https://www.planalto.gov.br/ccivil_03/constituicao/constituicao.htm>
- Manual de Emendas Parlamentares Impositivas Municipais do TCESP, publicado
  em 8 de julho de 2026:
  <https://www.tce.sp.gov.br/publicacoes/manual-emendas-parlamentares-impositivas-municipais>
- PDF oficial do manual:
  <https://www.tce.sp.gov.br/sites/default/files/publicacoes/Manual_Emendas_Or%C3%A7ament%C3%A1rias_Impositivas%20Revisado.pdf>

O manual do TCESP é aplicável aos municípios paulistas sob sua jurisdição e não
deve ser automaticamente convertido em regra nacional. Para outros estados, a
matriz deverá receber uma versão baseada no Tribunal de Contas competente.

## Decisões representadas no sistema

### Instituição do regime

O diagnóstico exige a situação local do regime e o registro da Lei Orgânica.
Quando o regime é marcado como instituído, também exige Regimento Interno, PPA,
LDO e LOA. A existência da LOA isoladamente não é tratada como prova suficiente
da instituição do regime.

### Limites e saúde

O percentual-limite não possui valor automático. O gestor informa o percentual
validado e a RCL do exercício anterior; só então o sistema calcula o teto. O
mesmo princípio vale para a reserva destinada à saúde e para a forma de apuração
por vereador ou no conjunto das emendas.

O Manual TCESP de julho de 2026 recomenda cautela jurídica quanto aos limites
municipais enquanto houver controvérsia judicial. Por isso, o TrilhaGov não
apresenta um percentual como certificação legal e exige referência do parecer
municipal antes da ativação.

### Admissibilidade e impedimentos

São parametrizados:

- tratamento de objetos genéricos;
- análise técnica anterior à votação;
- exigência de plano de trabalho;
- conferência com o Plano de Contratações Anual;
- quantidade e valor mínimo de emendas;
- prazo local para comunicar impedimentos;
- prazo local para correção ou saneamento.

Os prazos não são presumidos. Devem ser extraídos da LDO, Lei Orgânica,
Regimento ou ato regulamentador vigente no exercício.

### Transparência e Audesp

O perfil registra o prazo municipal de publicação, a regra de rastreabilidade
bancária, a política de retenção documental e a situação da preparação Audesp.
Para municípios paulistas, o diagnóstico alerta quando o prazo de publicação é
superior ao próximo dia útil e quando a operação Audesp ainda não está pronta.

O status `Operação preparada` é uma declaração interna do município. Não
representa aceite de arquivo nem validação pelo TCESP.

## Requisitos para ativação

Toda configuração exige:

1. situação do regime definida;
2. Lei Orgânica registrada;
3. revisão jurídica com responsável, referência e data.

Quando o regime está instituído, também são exigidos os instrumentos de
planejamento e orçamento, limites, reserva da saúde, decisões de admissibilidade,
prazos de impedimento e regra de rastreabilidade bancária.

## Próximas integrações

Entregue na aplicação operacional:

1. Vínculo imutável entre emenda, impedimento e versão normativa do exercício.
2. Vínculo automático no cadastro manual, importação por planilha e ativação da norma.
3. Bloqueio explicável de valor mínimo, quantidade e teto total por autor.
4. Detecção de registros importados fora dos parâmetros, sem exclusão de dados.
5. Prazo local separado para comunicação formal e para saneamento do impedimento.
6. Encerramento automático de alertas após registrar data e protocolo da comunicação.
7. Ações correspondentes na Integridade e na Central de Trabalho.
8. Apuração provisória da reserva da saúde no método global ou por vereador.

Próximas integrações:

1. Validar a apuração da saúde com contabilidade e controle interno do piloto.
2. Publicar os campos mínimos de transparência com registro de atualização.
3. Mapear o leiaute XSD vigente da Audesp antes de gerar qualquer arquivo.
4. Criar matrizes próprias para outros Tribunais de Contas, sempre versionadas.

## Limite jurídico

O módulo organiza fontes, decisões e evidências. Não emite parecer jurídico, não
certifica conformidade e não promete ausência de apontamento, multa ou rejeição
de contas.
