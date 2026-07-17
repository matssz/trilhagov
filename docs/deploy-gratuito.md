# Deploy gratuito com Render e Supabase

Esta configuracao serve para demonstracao e validacao do TrilhaGov sem dados
municipais reais. O Render hospeda o Laravel, o Supabase preserva banco e
documentos, e o GitHub Actions aciona as tarefas agendadas.

## 1. Criar o projeto no Supabase

1. Acesse `https://supabase.com/dashboard` e entre com o GitHub.
2. Crie o projeto `trilhagov` no plano Free e guarde a senha do banco.
3. Na pagina **Connect**, selecione **Session Pooler**.
4. Copie a URI e substitua o marcador de senha pela senha criada.

A URI completa sera usada no segredo `DB_URL`. O schema isolado `trilhagov` e
criado automaticamente no primeiro deploy.

## 2. Criar o bucket privado

1. Abra **Storage** e crie o bucket privado `trilhagov-documents`.
2. Abra a configuracao S3 do Storage e gere um par de chaves do servidor.
3. Guarde o Access Key ID, Secret Access Key, endpoint direto e regiao.

As chaves S3 ignoram as politicas RLS e devem existir somente como segredos do
Render. Nunca coloque esses valores no GitHub ou em arquivos versionados.

## 3. Gerar os segredos da aplicacao

No terminal local do projeto, gere uma chave Laravel:

```bash
php artisan key:generate --show
```

Gere tambem um token independente para o agendador:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Guarde os dois valores temporariamente. Eles serao informados ao Render e o
token do agendador tambem sera cadastrado como segredo do GitHub Actions.

## 4. Criar o servico no Render

1. Abra `https://render.com/deploy?repo=https://github.com/matssz/trilhagov`.
2. Entre com o GitHub e confirme o Blueprint no plano Free.
3. Preencha os segredos solicitados:

| Variavel | Valor |
| --- | --- |
| `APP_KEY` | Saida completa de `php artisan key:generate --show` |
| `DB_URL` | URI do Session Pooler do Supabase |
| `AWS_ACCESS_KEY_ID` | Access Key ID do Storage S3 |
| `AWS_SECRET_ACCESS_KEY` | Secret Access Key do Storage S3 |
| `AWS_DEFAULT_REGION` | Regiao mostrada na configuracao S3 |
| `AWS_BUCKET` | `trilhagov-documents` |
| `AWS_ENDPOINT` | Endpoint direto terminado em `/storage/v1/s3` |
| `SCHEDULER_TOKEN` | Token aleatorio de 64 caracteres |

O primeiro build instala PHP, compila a interface, cria as tabelas e pode levar
alguns minutos. Ao terminar, o Render mostra o dominio HTTPS `onrender.com`.

## 5. Ativar alertas horarios

No GitHub, abra **Settings > Secrets and variables > Actions** e crie:

| Segredo | Valor |
| --- | --- |
| `RENDER_APP_URL` | Dominio HTTPS completo fornecido pelo Render |
| `SCHEDULER_TOKEN` | O mesmo token cadastrado no Render |

Depois abra **Actions > Agendador > Run workflow**. O endpoint interno aceita
somente o token e possui limite de requisicoes.

## 6. Conferencia final

1. Acesse `/up` e confirme a resposta HTTP 200.
2. Cadastre um gestor e um municipio de demonstracao.
3. Cadastre uma emenda sem informacoes pessoais reais.
4. Envie e baixe um documento de teste.
5. Rode manualmente o workflow **Agendador**.
6. Reinicie o servico no Render e confirme que login, dados e documento continuam.

## Limites gratuitos

- O Render adormece depois de 15 minutos sem trafego e a primeira abertura pode
  levar aproximadamente um minuto.
- O Supabase Free pode pausar depois de uma semana sem atividade.
- O e-mail fica em modo de log; as notificacoes internas continuam funcionando.
- Esta arquitetura nao oferece SLA ou garantias suficientes para producao
  municipal com dados reais.
