<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'transferegov' => [
        'base_url' => env('TRANSFEREGOV_API_URL', 'https://api-publica.transferegov.gestao.gov.br/especiais'),
        'timeout' => (int) env('TRANSFEREGOV_API_TIMEOUT', 20),
    ],

    'scheduler' => [
        'token' => env('SCHEDULER_TOKEN'),
    ],

    'audesp' => [
        // Exercícios para os quais o XSD atual (AudespAmendmentRegistration::SCHEMA_VERSION)
        // está homologado. Quando o TCESP publicar um novo schema, ajuste esta variável de
        // ambiente (lista separada por vírgula) em vez de alterar código.
        'homologated_fiscal_years' => array_values(array_filter(array_map(
            fn (string $year): int => (int) trim($year),
            explode(',', (string) env('AUDESP_HOMOLOGATED_FISCAL_YEARS', '2026')),
        ))),
    ],

    'municipal_reports' => [
        // Exercícios para os quais a metodologia atual de Relatórios de Governança e
        // Relatórios Especializados foi validada. Independente do parâmetro do Audesp
        // acima -- são revisões de metodologia distintas, mesmo coincidindo no valor hoje.
        // Ajuste via variável de ambiente (lista separada por vírgula) em vez de código.
        'homologated_fiscal_years' => array_values(array_filter(array_map(
            fn (string $year): int => (int) trim($year),
            explode(',', (string) env('MUNICIPAL_REPORTS_HOMOLOGATED_FISCAL_YEARS', '2026')),
        ))),
    ],

];
