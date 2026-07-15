# TrilhaGov

[![Testes](https://github.com/matssz/trilhagov/actions/workflows/tests.yml/badge.svg)](https://github.com/matssz/trilhagov/actions/workflows/tests.yml)

Plataforma para municípios controlarem emendas parlamentares, valores, situação,
responsáveis e prazos de comunicação, execução e prestação de contas.

## Hipótese do produto

Municípios, especialmente os menores, não possuem uma visão interna consolidada
das emendas recebidas. As informações ficam distribuídas entre plataformas
oficiais, planilhas, documentos e secretarias.

O produto não substitui o Transferegov, o Siafic, o Portal da Transparência ou a
análise jurídica. Ele funciona como camada de controle operacional e evidências.

## Primeira entrega

- Cadastro do gestor e do município.
- Cadastro e edição de emendas federais ou estaduais.
- Controle de autoria, modalidade, objeto, órgão e códigos externos.
- Valores previsto e recebido.
- Situações do ciclo da emenda.
- Prazos de comunicação, execução e prestação de contas.
- Registro da conclusão de cada marco.
- Painel com totais, atrasos e próximos prazos.
- Isolamento dos dados entre municípios.
- Perfis municipais de gestão, edição, consulta e auditoria.
- Convites seguros e expiráveis para novos usuários municipais.
- Administração de perfis restrita ao gestor.
- Histórico de criação e alteração das emendas.
- Checklist documental configurável por município.
- Arquivos privados, versionados e vinculados às emendas.
- Download autorizado e auditoria dos documentos anexados.

## Stack

- PHP 8.2
- Laravel 12
- Blade
- Bootstrap 5
- Lucide Icons
- SQLite no desenvolvimento local

## Como executar

```bash
composer install
npm install
php artisan key:generate
php artisan migrate
npm run build
composer serve
```

Abra `http://127.0.0.1:8001`.

O comando `composer serve` prepara o PHP local para uploads de até 12 MB. A
aplicação aceita arquivos de até 10 MB; em produção, os limites do PHP e do
servidor web também precisam ser configurados.

Use sempre esse endereço, inclusive depois de reiniciar o servidor. Alternar entre
`localhost` e `127.0.0.1` cria cookies diferentes no navegador e exige um novo login.
Marque **Manter conectado** na tela de login quando estiver usando um computador
particular.

## Testes

```bash
php artisan test
```

## Documentação

- [Evidência e hipótese](docs/evidencia-e-hipotese.md)
- [Modelagem inicial](docs/modelagem-inicial.md)
- [Roteiro de entrevista municipal](docs/roteiro-entrevista-municipal.md)
- [Roadmap de módulos](docs/roadmap-modulos.md)
- [Identidade visual](docs/identidade-visual.md)

## Limite atual

O sistema ainda não deve ser apresentado como ferramenta que garante
conformidade ou evita multas. Regras, relatórios e automações serão validados com
advogados públicos, controladores e gestores municipais antes de virarem
promessas comerciais.
