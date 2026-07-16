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
- reset individual de estado temporário e prevenção de telas autenticadas em cache;
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

Situação: primeira versão concluída.

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

## Módulo 5 - Prestação de contas e transparência

Situação: primeira versão da prestação de contas, inteligência gerencial,
transparência pública e exportação em planilha concluída.

Necessidades:

- relatório consolidado por exercício, modalidade e situação;
- origem, objeto, valores e estágio de execução;
- exportação em PDF e planilha;
- página pública com campos aprovados;
- conferência de dados ausentes;
- histórico do relatório gerado.

Entregue na primeira versão:

- processo com responsável, prazo, situação, protocolo e aprovação;
- checklist operacional configurável e documentos vinculados;
- conciliação entre recebido, pago, devolvido e saldo pendente;
- diligências com responsável, prazo, resposta e protocolo;
- indicador de prontidão e bloqueio de envio com pendências;
- dossiê consolidado em PDF e pacote ZIP com documentos privados;
- alertas, escalonamento e auditoria das operações.
- painel analítico filtrável com funil financeiro, execução física e qualidade cadastral;
- diagnósticos automáticos de prazo, risco, responsabilidade e concentração de recursos;
- exportação CSV interna auditada e protegida contra fórmulas;
- portal público opcional, ativado pelo gestor, com filtros e exportação de dados;
- bloqueio de dados internos, documentos, fornecedores, usuários e motivos de risco na visão pública.

Dependências: execução, documentos e auditoria.

Validação necessária: confirmar o formato exigido pelo tribunal de contas e pelo
Portal da Transparência do estado atendido.

Próxima evolução: versionar relatórios oficiais e integrar fontes do Transferegov
sem sobrescrever informações municipais confirmadas.

## Módulo 6 - Alertas e responsabilidades

Situação: primeira versão concluída.

Cada emenda possui responsável operacional validado contra a equipe municipal.
Prazos, documentos e inconsistências alimentam notificações idempotentes, dois
níveis configuráveis de escalonamento e uma matriz de risco explicável.

Necessidades:

- responsável por etapa;
- responsável operacional por emenda;
- alertas internos e por e-mail;
- antecedência configurável;
- escalonamento de prazo vencido;
- confirmação de leitura;
- registro do alerta na auditoria.
- nota de risco com os motivos detectados.

Dependências: usuários, prazos confiáveis e auditoria.

Não incluir WhatsApp automático antes de validar consentimento, custo e canal
institucional de cada prefeitura.

## Módulo 7 - Integrações oficiais

Situação: primeira versão da integração com Transferências Especiais do
Transferegov concluída.

Necessidades candidatas:

- dados abertos e APIs do Transferegov;
- importação de emendas e atualizações;
- identificação da fonte e horário da sincronização;
- tratamento de divergências sem sobrescrever dado confirmado;
- monitoramento de falhas e mudança de contrato da API.

Entregue na primeira versão:

- consulta do beneficiário pelo CNPJ municipal na API pública oficial;
- leitura paginada dos planos de ação e da data de atualização da fonte;
- histórico de sincronizações, quantidades, falhas e usuário iniciador;
- candidatos externos versionados por hash e separados por município;
- correspondência por código do plano ou da emenda;
- detecção explicável de divergências em exercício, autoria, objeto e valor;
- vínculo sem sobrescrita, aplicação seletiva de campos e auditoria;
- importação somente após preenchimento das responsabilidades e datas municipais;
- descarte com justificativa e reabertura quando a fonte oficial mudar;
- retentativas limitadas e mensagem humana quando a API estiver indisponível.

Evolução entregue: conciliação histórica de empenhos federais, ordens bancárias e
saldo oficial, sem tratar a execução municipal como contabilidade equivalente.

Dependências: modelo estabilizado e dados reais suficientes para mapear os campos.

## Módulo 8 - Central de Trabalho Municipal

Situação: primeira versão concluída.

Entregue na primeira versão:

- fila operacional única para equipes municipais pequenas;
- geração idempotente a partir de responsabilidades, prazos, documentos,
  execução, conciliação e prestação de contas;
- prioridade explicável por vencimento e sensibilidade operacional;
- atribuição, andamento e anotações sem permitir conclusão fictícia;
- resolução automática quando o dado de origem é corrigido;
- reabertura sem duplicidade quando uma pendência retorna;
- atalhos para o contexto exato e atualização horária agendada;
- isolamento municipal, perfis e auditoria.

Próxima validação: observar uma equipe municipal usando a fila durante uma semana
e medir quais ações são úteis, redundantes ou ainda precisam de orientação.

## Ordem recomendada

1. Concluir identidade municipal e robustez.
2. Validar o inventário com usuários reais.
3. Concluir perfis, convites e trilha de auditoria.
4. Concluir a primeira versão de documentos e checklists.
5. Construir execução física e financeira.
6. Gerar transparência e prestação de contas.
7. Adicionar alertas.
8. Integrar fontes oficiais.
9. Consolidar as próximas ações na Central de Trabalho.

## Itens fora do escopo atual

- inteligência artificial para decidir conformidade;
- promessa automática de ausência de multa;
- substituição do Transferegov ou Siafic;
- aplicativo mobile nativo;
- microsserviços;
- blockchain;
- automação de regras sem fonte normativa versionada.
