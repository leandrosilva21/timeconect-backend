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

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'movidesk' => [
        'token'            => env('MOVIDESK_API_TOKEN'),
        'webhook_secret'   => env('MOVIDESK_WEBHOOK_SECRET'),
        'webhook_validate' => filter_var(env('MOVIDESK_WEBHOOK_VALIDATE', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'n8n' => [
        'timesheet_webhook_url'     => env('N8N_TIMESHEET_WEBHOOK_URL', 'https://erpserv.app.n8n.cloud/webhook/apontamento-status'),
        'timesheet_webhook_enabled' => filter_var(env('N8N_TIMESHEET_WEBHOOK_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'anthropic' => [
        'api_key'   => env('ANTHROPIC_API_KEY'),
        'ocr_model' => env('ANTHROPIC_OCR_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    /*
     * Microsoft Graph — leitura da caixa do noreply para puxar as RESPOSTAS dos
     * consultores ao fechamento (Fase 2). client-credentials (app-only).
     * Sem as 4 variáveis abaixo o leitor fica DORMENTE (não quebra nada).
     * Requer app registrado no Azure AD + permissão de aplicação Mail.Read
     * (idealmente escopada à caixa via Application Access Policy no Exchange).
     */
    'microsoft_graph' => [
        'tenant_id'     => env('GRAPH_TENANT_ID'),
        'client_id'     => env('GRAPH_CLIENT_ID'),
        'client_secret' => env('GRAPH_CLIENT_SECRET'),
        // Caixa lida (UPN/e-mail). Default = mesma conta do remetente (noreply).
        'mailbox'       => env('GRAPH_MAILBOX', env('MAIL_FROM_ADDRESS')),
    ],

];
