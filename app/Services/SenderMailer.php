<?php

namespace App\Services;

use App\Models\User;

/**
 * Resolve qual mailer/From usar ao enviar um fechamento:
 *
 *  - Se o remetente (usuário logado) tem App Password de SMTP configurado, registra em
 *    runtime um mailer 'user_smtp' autenticando COMO ele (Send As self via O365) e devolve
 *    From = o próprio e-mail/nome do usuário.
 *  - Caso contrário, devolve o mailer/From de fallback (comportamento atual, idêntico).
 *
 * Os envios de fechamento são SÍNCRONOS (->send()), então registrar config em runtime
 * é seguro: o mailer 'user_smtp' é resolvido no mesmo request.
 */
class SenderMailer
{
    /** Resolve qual mailer/From usar: o do usuário logado (app password) ou o fallback. */
    public static function for(User $sender, string $fallbackMailer, string $fallbackFromAddress, ?string $fallbackFromName): array
    {
        if ($sender->canSendAsSelf()) {
            config(['mail.mailers.user_smtp' => [
                'transport' => 'smtp',
                'scheme' => env('MAIL_SCHEME'),
                'host' => env('MAIL_HOST', 'smtp.office365.com'),
                'port' => env('MAIL_PORT', 587),
                'username' => $sender->email,
                'password' => $sender->smtp_app_password,
                'timeout' => env('MAIL_TIMEOUT', 60),
                'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
                'encryption' => env('MAIL_ENCRYPTION', 'tls'),
                'verify_peer' => true,
                'allow_self_signed' => false,
                'stream_context_options' => ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]],
            ]]);

            return ['mailer' => 'user_smtp', 'from_address' => $sender->email, 'from_name' => $sender->name];
        }

        return ['mailer' => $fallbackMailer, 'from_address' => $fallbackFromAddress, 'from_name' => $fallbackFromName];
    }
}
