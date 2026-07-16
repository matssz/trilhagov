# Central de Trabalho Municipal

## Objetivo

A Central transforma dados já existentes no TrilhaGov em uma fila operacional
única. O foco são municípios com equipes pequenas, nos quais uma mesma pessoa
pode acompanhar planejamento, documentos, execução e prestação de contas.

## Ações geradas

O motor avalia cada emenda não concluída e pode gerar ações para:

- definir responsável operacional;
- concluir comunicação e publicidade;
- anexar documentos marcados como obrigatórios pelo município;
- planejar e acompanhar etapas de execução;
- registrar contratação e empenho após o recebimento do recurso;
- conferir o repasse com os dados oficiais disponíveis;
- iniciar a preparação da prestação de contas;
- resolver itens pendentes do checklist e responder diligências.

Cada ação preserva a emenda, frente de trabalho, orientação, responsável, prazo,
prioridade, anotação municipal e atalho para o contexto que precisa ser corrigido.

## Regra de resolução

Ações geradas não podem ser concluídas manualmente. O usuário pode atribuir um
responsável, registrar uma anotação e marcar o trabalho como iniciado. A resolução
ocorre somente quando uma nova avaliação confirma que a pendência de origem não
existe mais.

Essa regra evita que um item seja marcado como concluído sem que a comunicação,
o documento, a etapa ou o registro financeiro tenha sido efetivamente atualizado.
Se a pendência retornar, a mesma ação é reaberta sem duplicação e sem perder o
histórico operacional.

## Prazos e limites

A Central não cria prazo legal. Ela usa datas cadastradas na emenda, nas etapas,
na prestação de contas ou nas diligências. Documentos obrigatórios também são os
tipos configurados pelo próprio município.

A prioridade é calculada assim:

- crítica para prazo vencido;
- alta para os próximos sete dias ou situações operacionais sensíveis;
- normal para os demais acompanhamentos.

O resultado organiza trabalho e evidências, mas não promete conformidade jurídica
nem substitui o Transferegov, o Siafic, a assessoria jurídica ou o órgão de controle.

## Atualização

Gestores e editores podem atualizar a Central pela interface. Em produção, o
comando `php artisan work-items:sync` também é agendado a cada hora, quinze minutos
após a hora cheia, sem sobreposição de execuções.

Todas as alterações de atribuição e andamento, assim como as avaliações manuais,
são registradas na trilha de auditoria do município.
