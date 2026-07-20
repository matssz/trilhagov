# Roteiro de implantação municipal do TrilhaGov

## Princípio

O produto deve partir da rotina de uma prefeitura com equipe reduzida, dados
dispersos em planilhas e baixa capacidade de integração. Cada automação precisa
funcionar primeiro de forma assistida e auditável; integrações avançadas entram
depois que o fluxo manual estiver validado com dados reais.

## Etapa 1 - Município piloto e base normativa

Situação: módulo técnico entregue; validação de campo pendente.

- selecionar um município piloto e definir gestor, procuradoria, controle
  interno, contabilidade, planejamento e ponto focal da Câmara;
- cadastrar Lei Orgânica, Regimento, PPA, LDO, LOA e regulamentações;
- validar percentuais, RCL, saúde, prazos, rastreabilidade e retenção;
- registrar parecer jurídico e ativar a primeira versão do exercício;
- comparar o diagnóstico do sistema com a leitura das áreas responsáveis.

Saída: configuração vigente aprovada pelo município, sem pendências de ativação.

## Etapa 2 - Regras locais aplicadas ao fluxo

Situação: primeira versão operacional entregue; validação com dados reais pendente.

- associar automaticamente a emenda à configuração vigente do seu exercício;
- validar quantidade, valor mínimo, teto e reserva da saúde;
- calcular prazos de comunicação e saneamento de impedimentos;
- levar os prazos locais para alertas e Central de Trabalho;
- registrar qual versão normativa fundamentou cada análise e parecer.

Entregue no sistema: vínculo histórico, valor mínimo, quantidade, teto por autor,
prazos de comunicação e saneamento, alertas, Central de Trabalho e consolidação
provisória da reserva da saúde. A validação contábil com o piloto permanece
obrigatória antes de tratar o resultado como conferência concluída.

Saída: nenhuma validação crítica depende de prazo ou percentual fixo no código.

## Etapa 3 - Transparência municipal completa

- completar os campos públicos exigidos pelo ato do Tribunal competente;
- publicar valores autorizado, liberado e executado separadamente;
- exibir conta ou identificador de rastreabilidade conforme a regra aplicável;
- publicar instrumento, processo, cronograma e prazo de aplicação;
- registrar mudanças, cancelamentos e data da última atualização;
- incluir pesquisa, filtros e exportação acessível.

Saída: checklist de transparência aprovado por controle interno e procuradoria.

## Etapa 4 - Audesp e contabilidade para municípios paulistas

- obter o XSD e comunicados vigentes diretamente do TCESP;
- mapear identificadores, contas, fonte de recurso e códigos de aplicação;
- importar arquivo de teste sem alterar a contabilidade municipal;
- validar a regra 47.4.63 e divergências antes da remessa mensal;
- gerar prévia, relatório de erros e pacote auditável;
- homologar com contador e fornecedor do Siafic do município piloto.

Saída: arquivo de homologação aceito e procedimento operacional documentado.

## Etapa 5 - Relatórios para controle e Câmara

- relatório mensal de emendas, execução, impedimentos e providências;
- relatório de reserva e execução em saúde;
- relatório de divergências entre orçamento, financeiro e entrega física;
- ofícios e comunicações de impedimento com protocolo;
- dossiê anual para controle interno, Câmara e prestação de contas.

Saída: modelos aprovados pelas áreas que efetivamente recebem os documentos.

## Etapa 6 - Saúde, obras e terceiro setor

- matriz de aplicação em ações e serviços públicos de saúde baseada na LC 141;
- controles de engenharia, licenciamento, medição e aceite para obras;
- plano de contratação e processo conforme a Lei 14.133;
- plano de trabalho, seleção, conflito de interesses e prestação de contas para
  organizações da sociedade civil conforme a Lei 13.019;
- checklists locais versionados por modalidade e órgão executor.

Saída: um caso real de cada modalidade concluído do cadastro ao dossiê.

## Etapa 7 - Integrações graduais

- manter Transferegov com conciliação assistida;
- criar adaptadores por fornecedor de Siafic, começando pelo município piloto;
- importar cadastros da Câmara por planilha padronizada quando não houver API;
- integrar protocolos e assinaturas somente após validar segurança e custo;
- monitorar indisponibilidade sem bloquear o trabalho municipal.

Saída: integrações economizam digitação sem sobrescrever decisões confirmadas.

## Etapa 8 - Segurança, LGPD e continuidade

- inventário de dados pessoais e definição de perfis de acesso;
- política de retenção, descarte, backup e restauração testada;
- registro e resposta a incidentes;
- testes de autorização, idempotência, carga e recuperação;
- treinamento, manual operacional e plano para indisponibilidade;
- revisão contratual, termos de uso, privacidade e responsabilidades.

Saída: aceite técnico, jurídico e operacional para entrada em produção.

## Etapa 9 - Piloto e expansão comercial

- operar um exercício real com acompanhamento semanal;
- medir tempo economizado, pendências prevenidas e completude documental;
- corrigir linguagem e fluxo a partir dos servidores municipais;
- formar preço por porte, quantidade de emendas e nível de integração;
- oferecer implantação, treinamento e suporte como parte do serviço;
- expandir por Tribunal de Contas, criando matrizes estaduais versionadas.

Saída: estudo de caso autorizado, processo repetível de implantação e proposta
comercial baseada em resultado mensurável.

## Fontes federais para os módulos especializados

- Constituição Federal:
  <https://www.planalto.gov.br/ccivil_03/constituicao/constituicao.htm>
- Lei Complementar 141/2012, saúde:
  <https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp141.htm>
- Lei 13.019/2014, parcerias com organizações da sociedade civil:
  <https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2014/lei/l13019.htm>
- Lei 14.133/2021, licitações e contratos:
  <https://www.planalto.gov.br/ccivil_03/_ato2019-2022/2021/lei/l14133.htm>
- Lei 13.709/2018, proteção de dados pessoais:
  <https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm>

Cada etapa especializada ainda requer validação com a legislação local, o
Tribunal de Contas competente e os profissionais responsáveis no município.
