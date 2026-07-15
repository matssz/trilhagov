# Modelagem inicial

## Relações

```text
Usuário <-> Município -> Emenda parlamentar
```

Usuários e municípios usam uma relação muitos-para-muitos porque uma prefeitura
pode ter vários servidores e uma consultoria pode, no futuro, atender mais de um
município. Após o login, a sessão mantém o `active_municipality_id`; contas com
mais de um vínculo precisam escolher o município antes de entrar no painel.

## municipalities

- `name`: nome do município.
- `state`: unidade federativa.
- `cnpj`: CNPJ principal, quando informado.
- `ibge_code`: código IBGE, quando informado.

## municipality_user

Tabela intermediária que define quais usuários podem acessar cada município.
O campo `role` aceita `manager`, `editor`, `viewer` e `auditor`. Gestores e
editores podem alterar emendas; consulta e auditoria possuem acesso somente de
leitura.

## audit_logs

- município e usuário responsável;
- nome do responsável preservado;
- ação realizada;
- tipo e identificação do registro alterado;
- valores anteriores e novos;
- IP, agente do navegador e data.

A aplicação não oferece alteração ou exclusão dos registros de auditoria.

## parliamentary_amendments

### Identificação

- referência e exercício;
- esfera federal ou estadual;
- tipo de autoria;
- modalidade de transferência;
- código do Transferegov.

### Origem e destinação

- autor e partido;
- objeto;
- secretaria ou órgão responsável.

### Execução

- valor previsto e valor recebido;
- situação atual;
- datas de indicação e recebimento.

### Controle de prazo

- comunicação e publicidade;
- execução;
- prestação de contas;
- data de conclusão de cada marco.

## Decisões de segurança

- O município não vem de um campo escondido do formulário.
- O controller resolve o município ativo pela sessão e confirma o vínculo.
- Contas sem município completo não entram na área interna.
- CNPJ e código IBGE são obrigatórios e únicos.
- Formulários de escrita usam token de submissão de uso único.
- Consultas de detalhe e edição filtram primeiro os municípios do usuário.
- Emendas não podem ser apagadas pela interface nesta etapa.
- A referência não pode se repetir no mesmo município, esfera e exercício.

## Próximas entidades candidatas

Somente depois da validação do fluxo atual:

- documentos e evidências;
- responsáveis por etapa;
- fontes normativas e cronogramas;
- relatório público de transparência;
- importação por dados abertos do Transferegov.
