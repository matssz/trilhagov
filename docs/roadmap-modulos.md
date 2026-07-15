# Roadmap de módulos - TrilhaGov

Este documento organiza a evolução técnica e comercial. A ordem considera risco,
dependências e capacidade de validar cada entrega com usuários municipais.

## Módulo 0 - Identidade municipal

Situação: base concluída nesta etapa.

Necessidades:

- usuário autenticado;
- município obrigatório com nome, UF, CNPJ válido e código IBGE;
- seleção do município ativo;
- isolamento entre municípios;
- proteção de formulários contra reenvio;
- sessão e CSRF;
- futura recuperação de senha;
- convite seguro de servidores concluído no módulo de usuários.

Critério de conclusão: nenhuma área interna pode ser aberta sem usuário,
município completo e contexto municipal ativo.

## Módulo 1 - Inventário de emendas

Situação: primeira versão concluída.

Necessidades:

- identificação, esfera, exercício, autoria e modalidade;
- objeto e secretaria responsável;
- valores previsto e recebido;
- situação do ciclo;
- prazos e conclusão dos marcos;
- busca e filtros;
- painel consolidado.

Próxima validação: cadastrar uma emenda real anonimizada com um gestor e revisar
todos os termos usados no formulário.

## Módulo 2 - Usuários, perfis e trilha de auditoria

Situação: primeira versão concluída.

O gestor convida usuários por e-mail ou link temporário, altera perfis e revoga
convites pendentes. O aceite funciona para contas novas e existentes, sempre
limitado ao município do convite. Criação e alteração de emendas e mudanças de
perfil geram histórico imutável.

Necessidades:

- convite de usuários pelo gestor;
- perfis `gestor`, `editor`, `consulta` e `auditoria`;
- registro de criação e alteração de dados;
- valor anterior e novo valor nos campos críticos;
- data, usuário, município e origem da ação;
- histórico imutável pela interface comum.

Dependência: identidade municipal concluída.

Motivo: documentos e execução financeira não devem entrar sem ser possível
identificar quem alterou cada informação.

## Módulo 3 - Documentos e checklists

Situação: primeira versão concluída.

O gestor configura tipos ativos e obrigatórios para o município. Gestores e
editores podem anexar arquivos privados e versionados; consulta e auditoria podem
baixá-los após autorização municipal. Uploads e alterações do checklist geram
registros de auditoria.

Necessidades:

- arquivos privados por emenda;
- tipos de documento configuráveis;
- plano de trabalho, extratos, contratos, notas e relatórios;
- checklist por modalidade;
- versão e data do documento;
- download autorizado;
- registro na trilha de auditoria;
- política de tamanho, formato, retenção e backup.

Dependências: perfis e auditoria.

Próxima validação: comparar os tipos sugeridos com checklists reais de pelo menos
dois municípios e confirmar política de retenção, tamanho e formatos permitidos.

Validação necessária: obter checklists reais de pelo menos dois municípios ou
órgãos de controle antes de automatizar documentos obrigatórios.

## Módulo 4 - Execução física e financeira

Necessidades:

- conta bancária específica;
- metas, etapas e entregas;
- empenhos, pagamentos e saldo;
- fornecedor e processo de contratação;
- vínculo de cada gasto ao objeto;
- percentual executado;
- evidências da entrega;
- conciliação com valores recebidos.

Dependências: documentos, permissões e auditoria.

Alerta: não construir contabilidade paralela. O módulo deve consolidar controle
e evidências, com futura integração ao Siafic quando houver viabilidade.

## Módulo 5 - Transparência e prestação de contas

Necessidades:

- relatório consolidado por exercício, modalidade e situação;
- origem, objeto, valores e estágio de execução;
- exportação em PDF e planilha;
- página pública com campos aprovados;
- conferência de dados ausentes;
- histórico do relatório gerado.

Dependências: execução, documentos e auditoria.

Validação necessária: confirmar o formato exigido pelo tribunal de contas e pelo
Portal da Transparência do estado atendido.

## Módulo 6 - Alertas e responsabilidades

Necessidades:

- responsável por etapa;
- alertas internos e por e-mail;
- antecedência configurável;
- escalonamento de prazo vencido;
- confirmação de leitura;
- registro do alerta na auditoria.

Dependências: usuários, prazos confiáveis e auditoria.

Não incluir WhatsApp automático antes de validar consentimento, custo e canal
institucional de cada prefeitura.

## Módulo 7 - Integrações oficiais

Necessidades candidatas:

- dados abertos e APIs do Transferegov;
- importação de emendas e atualizações;
- identificação da fonte e horário da sincronização;
- tratamento de divergências sem sobrescrever dado confirmado;
- monitoramento de falhas e mudança de contrato da API.

Dependências: modelo estabilizado e dados reais suficientes para mapear os campos.

## Ordem recomendada

1. Concluir identidade municipal e robustez.
2. Validar o inventário com usuários reais.
3. Concluir perfis, convites e trilha de auditoria.
4. Concluir a primeira versão de documentos e checklists.
5. Construir execução física e financeira.
6. Gerar transparência e prestação de contas.
7. Adicionar alertas.
8. Integrar fontes oficiais.

## Itens fora do escopo atual

- inteligência artificial para decidir conformidade;
- promessa automática de ausência de multa;
- substituição do Transferegov ou Siafic;
- aplicativo mobile nativo;
- microsserviços;
- blockchain;
- automação de regras sem fonte normativa versionada.
