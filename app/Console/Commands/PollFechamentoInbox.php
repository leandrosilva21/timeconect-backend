<?php

namespace App\Console\Commands;

use App\Models\FechamentoConsultorEmail;
use App\Services\GraphMailService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fase 2 do fechamento por e-mail: lê a caixa do noreply via Microsoft Graph e
 * grava as RESPOSTAS dos consultores como linhas inbound, encadeadas à thread.
 *
 * No-op se o Graph não estiver configurado (sem credenciais → dorme).
 * O noreply é compartilhado por outros workflows, então SÓ entram mensagens cujo
 * In-Reply-To/References casa com um Message-ID nosso (fech-{id}-{ym}-{uuid}@timeconect.com.br).
 */
class PollFechamentoInbox extends Command
{
    protected $signature = 'fechamento:poll-inbox {--since= : ISO8601/data — sobrescreve a janela automática} {--top=50}';
    protected $description = 'Lê respostas dos consultores ao fechamento (caixa noreply via Graph) e encadeia na thread';

    /** fech-{consultantId}-{YYYY-MM}-{sufixo}@timeconect.com.br (uuid no uso real; tolerante a qualquer sufixo) */
    private const OUR_ID_RE = '/^fech-(\d+)-(\d{4}-\d{2})-[0-9A-Za-z-]+@timeconect\.com\.br$/i';

    public function handle(GraphMailService $graph): int
    {
        if (!$graph->isConfigured()) {
            $this->info('Microsoft Graph não configurado — polling pulado (Fase 2 dormente).');
            return self::SUCCESS;
        }

        $since = $this->resolveSince();
        $this->line("Lendo respostas da caixa {$graph->mailbox()} desde {$since->toIso8601ZuluString()}...");

        $messages = $graph->fetchRecentMessages($since, (int) $this->option('top'));
        $imported = 0;
        $skipped  = 0;

        foreach ($messages as $msg) {
            if (empty($msg['graph_id'])) {
                continue;
            }
            // Ignora nossos próprios envios (cópia/Sent que porventura apareça): from = a caixa lida.
            if (!empty($msg['from_email']) && strcasecmp($msg['from_email'], (string) $graph->mailbox()) === 0) {
                continue;
            }
            // Dedup: já importada?
            if (FechamentoConsultorEmail::where('graph_message_id', $msg['graph_id'])->exists()) {
                continue;
            }

            $match = $this->matchThread($msg);
            if (!$match) {
                $skipped++;
                continue; // não é resposta de fechamento (outro workflow do noreply)
            }

            FechamentoConsultorEmail::create([
                'sender_user_id'     => null,
                'consultant_user_id' => $match['consultant_user_id'],
                'year_month'         => $match['year_month'],
                'direction'          => FechamentoConsultorEmail::DIRECTION_INBOUND,
                'to_email'           => $graph->mailbox(),
                'from_email'         => $msg['from_email'],
                'subject'            => $msg['subject'],
                'message_id'         => $msg['internet_message_id'],
                'graph_message_id'   => $msg['graph_id'],
                'in_reply_to'        => $match['parent_message_id'],
                'body'               => $msg['body_text'],
                'is_continuation'    => true,
                'status'             => FechamentoConsultorEmail::STATUS_RECEBIDO,
                'received_at'        => $msg['received_at'] ?? now(),
            ]);
            $imported++;
        }

        $this->info("Respostas importadas: {$imported} | ignoradas (não-fechamento): {$skipped}.");
        if ($imported > 0) {
            Log::info('Fechamento inbox: respostas importadas', ['imported' => $imported, 'skipped' => $skipped]);
        }
        return self::SUCCESS;
    }

    /**
     * Casa a resposta com uma thread de fechamento via In-Reply-To/References.
     * 1) tenta achar um envio nosso (message_id no banco) → consultor+período exatos;
     * 2) fallback: extrai consultorId+período do padrão do nosso Message-ID.
     *
     * @return array{consultant_user_id:int, year_month:string, parent_message_id:?string}|null
     */
    private function matchThread(array $msg): ?array
    {
        $candidates = array_values(array_filter(array_merge(
            [$msg['in_reply_to'] ?? null],
            $msg['references'] ?? []
        )));
        if (empty($candidates)) {
            return null;
        }

        // 1) Match direto contra um envio nosso.
        $row = FechamentoConsultorEmail::whereIn('message_id', $candidates)
            ->whereNotNull('consultant_user_id')
            ->orderBy('id')
            ->first();
        if ($row) {
            return [
                'consultant_user_id' => (int) $row->consultant_user_id,
                'year_month'         => $row->year_month,
                'parent_message_id'  => $row->message_id,
            ];
        }

        // 2) Fallback: padrão do nosso Message-ID (cobre logs podados/sem o envio no banco).
        foreach ($candidates as $cand) {
            if (preg_match(self::OUR_ID_RE, $cand, $mm)) {
                return [
                    'consultant_user_id' => (int) $mm[1],
                    'year_month'         => $mm[2],
                    'parent_message_id'  => $cand,
                ];
            }
        }

        return null;
    }

    /** Janela de leitura: --since, ou desde a última resposta, limitada a 7 dias atrás. */
    private function resolveSince(): Carbon
    {
        if ($opt = $this->option('since')) {
            return Carbon::parse($opt);
        }
        $last = FechamentoConsultorEmail::where('direction', FechamentoConsultorEmail::DIRECTION_INBOUND)
            ->whereNotNull('received_at')
            ->max('received_at');

        $floor = now()->subDays(7);
        if (!$last) {
            return $floor;
        }
        // pequeno overlap (1 min) pra não perder mensagens na borda; dedup cobre repetições.
        $since = Carbon::parse($last)->subMinute();
        return $since->lt($floor) ? $floor : $since;
    }
}
