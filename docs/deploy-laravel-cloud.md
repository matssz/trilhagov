# Deploy do TrilhaGov no Laravel Cloud

## Arquitetura inicial

- App Laravel em uma instancia Flex.
- PostgreSQL gerenciado para dados, sessoes, cache e filas.
- Laravel Object Storage privado para documentos e dossies.
- Scheduler habilitado para alertas e Central de Trabalho.
- Dominio `laravel.cloud` com HTTPS automatico.

Essa composicao evita o SQLite e arquivos locais em producao. O sistema pode
reiniciar ou receber um novo deploy sem perder dados ou documentos.

## Preparacao da conta

1. Criar uma conta em `https://cloud.laravel.com` e conectar o GitHub.
2. Autorizar o repositorio publico `matssz/trilhagov`.
3. Criar a aplicacao `TrilhaGov` na regiao mais proxima disponivel.
4. Usar PHP 8.3 ou superior e Node.js 22.

## Recursos do ambiente

1. Adicionar um PostgreSQL e anexa-lo ao ambiente.
2. Adicionar um bucket privado e marca-lo como disco padrao.
3. Habilitar o Scheduler no cluster da aplicacao.
4. Manter hibernacao apenas durante a validacao. Em producao real, os alertas
   agendados exigem que o ambiente esteja ativo.

O Cloud injeta as credenciais do banco e do bucket automaticamente. Configure
estas variaveis adicionais:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
```

Enquanto `MAIL_MAILER=log`, os alertas internos funcionam normalmente e os
e-mails ficam registrados no log. Antes do uso com um municipio, conecte um
provedor transacional e substitua as variaveis de e-mail.

## Comandos

O Laravel Cloud detecta Composer, npm e Vite. Use como comando de deploy:

```bash
php artisan migrate --force
```

Depois do primeiro deploy:

1. abrir o dominio HTTPS fornecido;
2. cadastrar o gestor e o municipio;
3. testar upload e download de um documento;
4. executar `php artisan alerts:process` no console do ambiente;
5. confirmar o Scheduler e os logs sem erros.

## Seguranca antes do piloto

- Nao usar dados pessoais reais no ambiente de demonstracao.
- Ativar autenticacao de dois fatores na conta do GitHub e da hospedagem.
- Restringir o bucket como privado.
- Confirmar backups e restauracao do banco antes do primeiro municipio piloto.
- Contratar provedor de e-mail com SPF, DKIM e DMARC no dominio definitivo.
