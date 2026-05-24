<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => env('MAIL_TIMEOUT', 60),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'verify_peer' => true,
            'allow_self_signed' => false,
            'stream_context_options' => [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ]
            ],
        ],

        // Conta NF-e — usada SOMENTE no fechamento do cliente (nfe@erpserv.com.br).
        'nfe' => [
            'transport' => 'smtp',
            'host' => env('MAIL_NFE_HOST', env('MAIL_HOST', 'smtp.office365.com')),
            'port' => env('MAIL_NFE_PORT', env('MAIL_PORT', 587)),
            'username' => env('MAIL_NFE_USERNAME', 'nfe@erpserv.com.br'),
            'password' => env('MAIL_NFE_PASSWORD'),
            'timeout' => env('MAIL_TIMEOUT', 60),
            'encryption' => env('MAIL_NFE_ENCRYPTION', env('MAIL_ENCRYPTION', 'tls')),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cópia do financeiro (fechamentos)
    |--------------------------------------------------------------------------
    | Endereço que recebe cópia (CC) dos e-mails de fechamento enviados pela
    | rotina de fechamento de consultores. Vazio desativa o CC.
    */
    'financeiro_cc' => env('MAIL_FINANCEIRO_CC', 'financeiro@erpserv.com.br'),

    /*
    |--------------------------------------------------------------------------
    | Reply-To dos fechamentos (caixa de resposta)
    |--------------------------------------------------------------------------
    | Endereço pra onde a resposta do consultor vai (Reply-To) e que o Minutor
    | LÊ via Graph (Fase 2). Separado do From: o From pode ser o noreply (que
    | costuma ter regra descartando e-mail externo), mas a resposta precisa cair
    | numa caixa que ACEITE externo. Default = o próprio From.
    | Mantenha igual ao GRAPH_MAILBOX (a caixa lida).
    */
    'fechamento_reply_to' => env('MAIL_FECHAMENTO_REPLY_TO', env('MAIL_FROM_ADDRESS')),

    /*
    |--------------------------------------------------------------------------
    | Nome de exibição do remetente (From) dos fechamentos
    |--------------------------------------------------------------------------
    | Identidade fixa do remetente nas rotinas de fechamento (consultor, parceiro,
    | cliente). NÃO é o nome de quem enviou — esse continua no Reply-To e na
    | assinatura do corpo. Fixo evita ainda o filtro anti-impersonation (nome de
    | executivo no From de e-mail externo).
    */
    'fechamento_from_name' => env('MAIL_FECHAMENTO_FROM_NAME', 'Fechamento ERPSERV'),

    /*
    |--------------------------------------------------------------------------
    | Fechamento de CLIENTE — conta/remetente próprios (NF-e)
    |--------------------------------------------------------------------------
    | Só o fechamento do cliente envia pela conta nfe@erpserv.com.br (mailer 'nfe'),
    | com From = nfe@erpserv.com.br e nome "NF-e ERPSERV". Consultor/parceiro seguem
    | no remetente padrão. From = a própria conta autenticada (O365 não permite Send As).
    */
    'fechamento_cliente_mailer'    => env('MAIL_FECHAMENTO_CLIENTE_MAILER', 'nfe'),
    'fechamento_cliente_from'      => env('MAIL_FECHAMENTO_CLIENTE_FROM', 'nfe@erpserv.com.br'),
    'fechamento_cliente_from_name' => env('MAIL_FECHAMENTO_CLIENTE_FROM_NAME', 'NF-e ERPSERV'),

];
