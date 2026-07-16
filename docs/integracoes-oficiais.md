# Integrações e conferência de dados

## Fonte inicial

A primeira integração usa a API pública do módulo de Transferências Especiais
do Transferegov:

- documentação oficial: `https://api-publica.transferegov.gestao.gov.br/especiais`;
- beneficiários: `/beneficiarios_especiais`;
- planos de ação: `/planos_acao_especiais`;
- atualização da base: `/data-atualizacao`.

O município é localizado por seu CNPJ cadastrado. Em seguida, seus planos de ação
são consultados em páginas de até 200 registros.

## Regra de confiança

Dados oficiais não substituem automaticamente dados municipais. A fonte pode ter
atrasos, campos vazios ou diferenças de contexto. Por isso, a sincronização:

1. registra o retorno externo sem alterar emendas;
2. tenta localizar correspondências por códigos;
3. calcula diferenças campo a campo;
4. aguarda decisão de gestor ou editor;
5. registra vínculo, importação, aplicação ou descarte na auditoria.

Os campos aplicáveis nesta versão são exercício, autor, objeto, valor previsto e
código do plano. A seleção é individual. Um valor oficial menor que um recurso já
recebido é bloqueado para evitar inconsistência financeira.

## Resiliência

- tempo limite configurável;
- duas retentativas curtas;
- limite de 50 páginas por sincronização manual;
- falha persistida sem exibir detalhes técnicos ao usuário;
- token de submissão para impedir clique repetido;
- candidatos isolados pelo município ativo;
- hash do retorno para detectar mudança de um item anteriormente ignorado.

## Configuração

```env
TRANSFEREGOV_API_URL=https://api-publica.transferegov.gestao.gov.br/especiais
TRANSFEREGOV_API_TIMEOUT=20
```

## Conciliação financeira oficial

Cada plano pode ser conciliado manualmente pela Caixa de Conferência. A consulta
percorre uma cadeia identificável da API:

1. empenhos federais filtrados por `id_plano_acao`;
2. documentos hábeis filtrados por `id_empenho`;
3. ordens de pagamento e bancárias filtradas por `id_dh`;
4. último saldo publicado filtrado por `id_agencia_conta`.

A comparação respeita o significado contábil dos registros:

- empenhos federais são comparados ao valor previsto da emenda;
- ordens bancárias federais são comparadas ao valor recebido pelo município;
- saldo oficial é comparado ao valor recebido menos pagamentos municipais;
- empenhos e pagamentos municipais aparecem como contexto da execução, sem serem
  tratados como equivalentes aos lançamentos federais.

O mesmo documento hábil é somado apenas uma vez, mesmo quando possui mais de uma
ordem bancária. Rendimentos, tarifas e lançamentos ainda não cadastrados podem
explicar diferenças de saldo, por isso o sistema sinaliza para conferência e nunca
altera registros locais automaticamente.

Cada tentativa cria um retrato histórico com valores oficiais, valores locais,
evidências, data da base e usuário responsável. Falhas também são persistidas e
podem ser consultadas novamente com um novo token de submissão.
