<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente mínimo do Microsoft Graph (app-only / client-credentials) para LER a caixa
 * do noreply e puxar as respostas dos consultores ao fechamento (Fase 2).
 *
 * Config-driven: sem as 4 variáveis em config('services.microsoft_graph') o serviço
 * fica DORMENTE — isConfigured() retorna false e o command de polling vira no-op.
 *
 * Não envia e-mail (isso continua via SMTP/Mailable); só faz leitura (Mail.Read).
 */
class GraphMailService
{
    private const TOKEN_URL  = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    public function isConfigured(): bool
    {
        $c = config('services.microsoft_graph');
        return !empty($c['tenant_id']) && !empty($c['client_id'])
            && !empty($c['client_secret']) && !empty($c['mailbox']);
    }

    public function mailbox(): ?string
    {
        return config('services.microsoft_graph.mailbox');
    }

    /**
     * Token app-only (cacheado ~50min). Null se falhar/não configurado.
     */
    public function token(): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $c = config('services.microsoft_graph');

        return Cache::remember('graph_mail_token', now()->addMinutes(50), function () use ($c) {
            try {
                $resp = Http::asForm()->post(sprintf(self::TOKEN_URL, $c['tenant_id']), [
                    'client_id'     => $c['client_id'],
                    'client_secret' => $c['client_secret'],
                    'scope'         => 'https://graph.microsoft.com/.default',
                    'grant_type'    => 'client_credentials',
                ]);
                if (!$resp->successful()) {
                    Log::error('Graph token: resposta não OK', ['status' => $resp->status(), 'body' => $resp->body()]);
                    return null;
                }
                return $resp->json('access_token');
            } catch (\Throwable $e) {
                Log::error('Graph token: exceção', ['erro' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Lê mensagens recentes da caixa (Inbox) recebidas a partir de $since.
     * Retorna lista normalizada; [] se não configurado/erro.
     *
     * @return array<int, array{
     *   graph_id:string, internet_message_id:?string, subject:?string, from_email:?string,
     *   received_at:?Carbon, body_text:string, in_reply_to:?string, references:array<int,string>
     * }>
     */
    public function fetchRecentMessages(Carbon $since, int $top = 50): array
    {
        $token = $this->token();
        if (!$token) {
            return [];
        }

        $mailbox = $this->mailbox();
        // Lê Entrada E Lixo Eletrônico: resposta externa de domínio "novo" costuma
        // ser jogada no Junk. NÃO lemos Itens Enviados (senão reimportaríamos nossos
        // próprios envios, que carregam In-Reply-To casando com a thread).
        $out = [];
        $seen = [];
        foreach (['inbox', 'junkemail'] as $folder) {
            $url = sprintf('%s/users/%s/mailFolders/%s/messages', self::GRAPH_BASE, rawurlencode($mailbox), $folder);
            try {
                $resp = Http::withToken($token)
                    ->withHeaders(['Prefer' => 'outlook.body-content-type="text"'])
                    ->get($url, [
                        '$top'     => $top,
                        '$orderby' => 'receivedDateTime desc',
                        '$filter'  => 'receivedDateTime ge ' . $since->toIso8601ZuluString(),
                        '$select'  => 'id,internetMessageId,subject,from,receivedDateTime,body,internetMessageHeaders',
                    ]);

                if (!$resp->successful()) {
                    Log::error('Graph fetch: resposta não OK', ['folder' => $folder, 'status' => $resp->status(), 'body' => $resp->body()]);
                    continue;
                }

                foreach ($resp->json('value', []) as $m) {
                    $norm = $this->normalize($m);
                    if ($norm['graph_id'] !== '' && !isset($seen[$norm['graph_id']])) {
                        $seen[$norm['graph_id']] = true;
                        $out[] = $norm;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Graph fetch: exceção', ['folder' => $folder, 'erro' => $e->getMessage()]);
            }
        }
        return $out;
    }

    /** Normaliza uma mensagem da Graph para o shape consumido pelo polling. */
    private function normalize(array $m): array
    {
        $headers = collect($m['internetMessageHeaders'] ?? [])
            ->mapWithKeys(fn ($h) => [strtolower($h['name'] ?? '') => (string) ($h['value'] ?? '')]);

        $inReplyTo  = $this->firstMessageId($headers->get('in-reply-to', ''));
        $references = $this->allMessageIds($headers->get('references', ''));

        // bodyPreview já texto via Prefer; body.content pode vir text (Prefer) ou html.
        $rawBody = (string) ($m['body']['content'] ?? '');
        $isHtml  = strtolower((string) ($m['body']['contentType'] ?? 'text')) === 'html';
        $bodyText = $this->bodyToText($rawBody, $isHtml);

        return [
            'graph_id'            => (string) ($m['id'] ?? ''),
            'internet_message_id' => $this->firstMessageId((string) ($m['internetMessageId'] ?? '')),
            'subject'             => $m['subject'] ?? null,
            'from_email'          => $m['from']['emailAddress']['address'] ?? null,
            'received_at'         => isset($m['receivedDateTime']) ? Carbon::parse($m['receivedDateTime']) : null,
            'body_text'           => $bodyText,
            'in_reply_to'         => $inReplyTo,
            'references'          => $references,
        ];
    }

    /** Extrai o 1º Message-ID (sem <>) de um header. */
    private function firstMessageId(string $raw): ?string
    {
        $all = $this->allMessageIds($raw);
        return $all[0] ?? (trim($raw) !== '' ? trim($raw, " \t<>") : null);
    }

    /** Extrai todos os Message-IDs (<...>) de um header References/In-Reply-To. */
    private function allMessageIds(string $raw): array
    {
        if (preg_match_all('/<([^>]+)>/', $raw, $mm) && !empty($mm[1])) {
            return array_values(array_unique(array_map('trim', $mm[1])));
        }
        $t = trim($raw);
        return $t !== '' ? [trim($t, '<>')] : [];
    }

    /**
     * HTML/texto da resposta → texto limpo, cortando o histórico citado quando dá
     * (Outlook/Gmail usam separadores conhecidos). Mantém o texto inteiro se não achar corte.
     */
    private function bodyToText(string $content, bool $isHtml): string
    {
        if ($isHtml) {
            $content = preg_replace('/<\s*(br|\/p|\/div|\/tr)\s*>/i', "\n", $content);
            $content = strip_tags($content);
            $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $content = preg_replace("/\r\n?/", "\n", $content);

        // Remove o aviso de "e-mail externo" que a regra do Exchange prepende (1º parágrafo).
        $lead = ltrim($content);
        $firstLine = strtok($lead, "\n");
        if ($firstLine !== false && preg_match('/\b(e-?mail\s+externo|external\s+e-?mail)\b/iu', $firstLine)) {
            if (preg_match('/\n[ \t]*\n/', $lead, $mm, PREG_OFFSET_CAPTURE)) {
                $content = substr($lead, $mm[0][1] + strlen($mm[0][0]));
            }
        }

        // Corta o histórico citado / rodapés de cliente no 1º separador conhecido.
        $cutPatterns = [
            '/^\s*-{2,}\s*Original Message\s*-{2,}.*$/im',
            '/^\s*De:\s.*$/im',
            '/^\s*From:\s.*$/im',
            '/^\s*Em\s.+escreveu:\s*$/im',
            '/^\s*On\s.+wrote:\s*$/im',
            '/^_{5,}\s*$/m',
            // rodapés de app de e-mail
            '/^\s*(Obter Outlook para|Get Outlook for|Baixe o Outlook|Enviado do meu|Sent from my)\b.*$/im',
        ];
        $cut = strlen($content);
        foreach ($cutPatterns as $re) {
            if (preg_match($re, $content, $mm, PREG_OFFSET_CAPTURE)) {
                $cut = min($cut, $mm[0][1]);
            }
        }
        $reply = substr($content, 0, $cut);

        $reply = trim(preg_replace("/\n{3,}/", "\n\n", $reply));
        // Se o corte deixou vazio (resposta toda citada/inline), devolve o conteúdo inteiro.
        return $reply !== '' ? $reply : trim($content);
    }
}
