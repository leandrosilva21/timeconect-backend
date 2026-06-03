<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Envio de e-mail via Microsoft Graph (app-only / client-credentials), AS the
 * caixa do remetente logado (Send As por permissão de aplicação Mail.Send +
 * Application Access Policy). SEM SMTP, SEM app password.
 *
 * DORMENTE por padrão: sem as 3 variáveis em config('services.graph')
 * enabled() retorna false e os controllers caem no fluxo SMTP/SenderMailer atual.
 *
 * Usa apenas o HTTP client nativo do Laravel (Illuminate\Support\Facades\Http) —
 * NENHUMA dependência composer adicional.
 */
class GraphMailer
{
    private const TOKEN_URL  = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    /** Anexos inline (contentBytes) só valem até ~3 MB no total num único sendMail. */
    public const MAX_INLINE_ATTACHMENTS_BYTES = 3 * 1024 * 1024;

    /** true só quando as 3 credenciais estão preenchidas. */
    public static function enabled(): bool
    {
        $c = config('services.graph');

        return !empty($c['tenant_id'])
            && !empty($c['client_id'])
            && !empty($c['client_secret']);
    }

    /**
     * Token client-credentials (cacheado ~50min; tokens duram ~60min).
     *
     * @throws \RuntimeException em qualquer falha de obtenção.
     */
    protected static function token(): string
    {
        $c = config('services.graph');

        return Cache::remember('graph_mail_token', now()->addMinutes(50), function () use ($c) {
            $resp = Http::asForm()->post(sprintf(self::TOKEN_URL, $c['tenant_id']), [
                'grant_type'    => 'client_credentials',
                'client_id'     => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'scope'         => 'https://graph.microsoft.com/.default',
            ]);

            if (!$resp->successful()) {
                throw new \RuntimeException(
                    'Graph: falha ao obter token (HTTP ' . $resp->status() . '): ' . $resp->body()
                );
            }

            $token = $resp->json('access_token');
            if (empty($token)) {
                throw new \RuntimeException('Graph: token de acesso vazio na resposta do Azure AD.');
            }

            return $token;
        });
    }

    /**
     * Envia um e-mail HTML COMO a caixa $fromEmail.
     *
     * @param array<int, string> $to               e-mails (filtra vazios)
     * @param array<int, string> $cc               e-mails (filtra vazios)
     * @param array<int, string> $attachmentPaths  caminhos locais (PDF/XLSX/etc)
     *
     * @throws \RuntimeException em resposta não-2xx do Graph (com o corpo) ou anexos > ~3 MB.
     */
    public static function sendAs(
        string $fromEmail,
        array $to,
        array $cc,
        string $subject,
        string $htmlBody,
        array $attachmentPaths = []
    ): void {
        $message = self::buildMessage($subject, $htmlBody, $to, $cc, $attachmentPaths);

        $token = self::token();
        $url   = sprintf('%s/users/%s/sendMail', self::GRAPH_BASE, rawurlencode($fromEmail));

        $resp = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($url, [
                'message'         => $message,
                'saveToSentItems' => true,
            ]);

        if (!$resp->successful()) {
            throw new \RuntimeException(
                'Graph sendMail falhou (HTTP ' . $resp->status() . '): ' . $resp->body()
            );
        }
    }

    /**
     * Monta o array `message` do payload sendMail. Static/puro para ser testável
     * sem chamadas de rede.
     *
     * @param array<int, string> $to
     * @param array<int, string> $cc
     * @param array<int, string> $attachmentPaths
     * @return array<string, mixed>
     *
     * @throws \RuntimeException se o total de anexos exceder ~3 MB (precisa upload session).
     */
    public static function buildMessage(
        string $subject,
        string $htmlBody,
        array $to,
        array $cc,
        array $attachmentPaths = []
    ): array {
        $message = [
            'subject'      => $subject,
            'body'         => [
                'contentType' => 'HTML',
                'content'     => $htmlBody,
            ],
            'toRecipients' => self::recipients($to),
            'ccRecipients' => self::recipients($cc),
        ];

        $attachments = self::buildAttachments($attachmentPaths);
        if (!empty($attachments)) {
            $message['attachments'] = $attachments;
        }

        return $message;
    }

    /**
     * @param array<int, string> $emails
     * @return array<int, array{emailAddress: array{address: string}}>
     */
    private static function recipients(array $emails): array
    {
        $clean = array_values(array_filter(array_map('trim', $emails), fn ($e) => $e !== ''));

        return array_map(fn ($email) => ['emailAddress' => ['address' => $email]], $clean);
    }

    /**
     * @param array<int, string> $attachmentPaths
     * @return array<int, array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    private static function buildAttachments(array $attachmentPaths): array
    {
        $paths = array_values(array_filter(array_map('trim', $attachmentPaths), fn ($p) => $p !== ''));
        if (empty($paths)) {
            return [];
        }

        $attachments = [];
        $totalBytes  = 0;

        foreach ($paths as $path) {
            if (!is_file($path)) {
                throw new \RuntimeException("Graph: anexo não encontrado: {$path}");
            }

            $bytes = (string) file_get_contents($path);
            $totalBytes += strlen($bytes);

            if ($totalBytes > self::MAX_INLINE_ATTACHMENTS_BYTES) {
                throw new \RuntimeException(
                    'Graph: anexos excedem ~3 MB no total; anexos grandes exigem upload session '
                    . '(createUploadSession), ainda não implementado. Fechamento atual < 1 MB.'
                );
            }

            $name = basename($path);
            $attachments[] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $name,
                'contentType'  => self::contentTypeFor($name),
                'contentBytes' => base64_encode($bytes),
            ];
        }

        return $attachments;
    }

    /** Infere o Content-Type pela extensão (pdf/xlsx; senão octet-stream). */
    private static function contentTypeFor(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf'  => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }
}
