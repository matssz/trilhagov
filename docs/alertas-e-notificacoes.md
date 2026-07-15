# Alertas e notificações

## O que está ativo

O TrilhaGov possui uma Central de Integridade que verifica, por município:

- prazos de comunicação, execução e prestação de contas;
- documentos marcados como obrigatórios que ainda não foram anexados;
- status de recurso recebido sem valor ou data de recebimento;
- valor recebido acima do previsto;
- emenda concluída sem todos os marcos registrados.

Ao corrigir a origem da pendência, o alerta é resolvido automaticamente. O botão
**Verificar e notificar** e o comando agendado podem ser executados repetidamente:
uma chave por alerta, usuário, canal e ciclo impede envios duplicados.

### Dentro do sistema

Ativo por padrão. Cada integrante recebe os avisos na caixa de entrada e vê a
quantidade não lida no sino. O Laravel mantém notificações lidas e não lidas em
banco, conforme a [documentação oficial de notificações](https://laravel.com/docs/12.x/notifications#database-notifications).

### E-mail

Implementado, mas desativado por usuário até que ele faça a opção nas próprias
preferências. No ambiente local, `MAIL_MAILER=log` grava o conteúdo em
`storage/logs/laravel.log`; não envia para a internet. Em produção, é necessário
configurar um serviço SMTP ou transacional e validar remetente, reputação e
entregabilidade.

## Processamento automático

O comando abaixo detecta pendências e envia somente os ciclos ainda não entregues:

```bash
php artisan alerts:process
```

O Laravel Scheduler executa esse comando a cada hora com bloqueio de sobreposição.
Em produção, o servidor precisa chamar `php artisan schedule:run` a cada minuto,
como orienta a [documentação oficial do agendador](https://laravel.com/docs/12.x/scheduling#running-the-scheduler).
O bloqueio `withoutOverlapping` reduz concorrência entre execuções, enquanto a
chave de entrega no banco fornece a proteção definitiva contra duplicidade.

## Canais avaliados

| Canal | Situação | Requisitos para ativação |
| --- | --- | --- |
| Sistema | Ativo | Conta vinculada ao município |
| E-mail | Implementado | Opt-in do usuário e provedor de e-mail em produção |
| WhatsApp | Planejado | API oficial/provedor, telefone, consentimento e regras de modelo de mensagem |
| SMS | Planejado | Provedor, telefone, consentimento, orçamento e política de repetição |
| Push web | Planejado | HTTPS, service worker, permissão explícita e armazenamento seguro da inscrição |
| Teams/Slack | Planejado | Aplicativo ou webhook institucional autorizado pelo município |
| Webhook próprio | Planejado | URL assinada, segredo rotativo, tentativas e trilha de entrega |

O Push API exige um service worker ativo e uma inscrição autorizada pelo usuário;
os endpoints e chaves da inscrição devem ser protegidos. Consulte a
[referência do Push API no MDN](https://developer.mozilla.org/en-US/docs/Web/API/Push_API).

## Privacidade e responsabilidade

E-mail, telefone, inscrições push e identificadores de conta são dados pessoais.
A ativação de novos canais deve registrar finalidade, consentimento quando
aplicável, retenção, revogação e os papéis de controlador e operador. A referência
inicial é a área de [direitos dos titulares da ANPD](https://www.gov.br/anpd/pt-br/assuntos/titular-de-dados-1)
e o [guia de agentes de tratamento da ANPD](https://www.gov.br/anpd/pt-br/assuntos/noticias/nova-versao-do-guia-dos-agentes-de-tratamento).

Alertas apoiam o controle operacional, mas não garantem conformidade legal nem
substituem validação do prazo por responsável jurídico ou controlador municipal.
