# Emendas Municipais

Aplicação para municípios controlarem emendas parlamentares, valores, situação,
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

## Stack

- PHP 8.2
- Laravel 12
- Blade
- Bootstrap 5
- SQLite no desenvolvimento local

## Como executar

```bash
composer install
npm install
php artisan key:generate
php artisan migrate
npm run build
php artisan serve --port=8001
```

Abra `http://127.0.0.1:8001`.

## Testes

```bash
php artisan test
```

## Documentação

- [Evidência e hipótese](docs/evidencia-e-hipotese.md)
- [Modelagem inicial](docs/modelagem-inicial.md)
- [Roteiro de entrevista municipal](docs/roteiro-entrevista-municipal.md)

## Limite atual

O sistema ainda não deve ser apresentado como ferramenta que garante
conformidade ou evita multas. Regras, relatórios e automações serão validados com
advogados públicos, controladores e gestores municipais antes de virarem
promessas comerciais.
