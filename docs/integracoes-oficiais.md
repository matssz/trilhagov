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

Empenhos, ordens de pagamento e saldo bancário permanecem fora da primeira
versão até a conferência dos identificadores em emendas municipais reais.
