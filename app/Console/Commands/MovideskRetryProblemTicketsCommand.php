<?php

namespace App\Console\Commands;

use App\Models\MovideskProblemTicket;
use App\Services\MovideskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MovideskRetryProblemTicketsCommand extends Command
{
    protected $signature   = 'movidesk:retry-problem-tickets';
    protected $description = 'Slow-lane: reprocessa tickets que falharam no movidesk:sync via fetchTicketLight (timeout 30s, $expand mínimo). Após MAX_ATTEMPTS sem sucesso, marca como blacklisted.';

    public function handle(MovideskService $service): int
    {
        if (!config('services.movidesk.token')) {
            $this->error('MOVIDESK_API_TOKEN não configurado.');
            return Command::FAILURE;
        }

        $rows = MovideskProblemTicket::retryable()->orderBy('last_attempt_at')->get();

        if ($rows->isEmpty()) {
            $this->info('Sem tickets na fila de problemas. Nada a fazer.');
            return Command::SUCCESS;
        }

        $this->info("🔁 Reprocessando {$rows->count()} ticket(s) da slow-lane...");
        Log::info('🔁 [MOVIDESK RETRY] Iniciando', ['queue_size' => $rows->count()]);

        $recovered    = 0;
        $stillFailing = 0;
        $blacklisted  = 0;

        foreach ($rows as $row) {
            try {
                $ticketData    = $service->fetchTicketLight((int) $row->ticket_id);
                $created       = $service->processTicket($ticketData);
                $row->delete();
                $recovered++;
                $this->line("  ✅ Ticket #{$row->ticket_id} recuperado ({$created} apontamento(s))");
            } catch (\Throwable $e) {
                $row->attempts        = $row->attempts + 1;
                $row->last_error      = mb_substr($e->getMessage(), 0, 1000);
                $row->last_attempt_at = now();
                if ($row->attempts >= MovideskProblemTicket::MAX_ATTEMPTS) {
                    $row->blacklisted_at = now();
                    $blacklisted++;
                    $this->warn("  ⛔ Ticket #{$row->ticket_id} BLACKLIST após {$row->attempts} falhas: {$e->getMessage()}");
                    Log::warning('⛔ [MOVIDESK RETRY] Ticket blacklisted', [
                        'ticket_id' => $row->ticket_id,
                        'attempts'  => $row->attempts,
                        'error'     => $e->getMessage(),
                    ]);
                } else {
                    $stillFailing++;
                    $this->line("  ↺ Ticket #{$row->ticket_id} segue falhando (tentativa {$row->attempts}/" . MovideskProblemTicket::MAX_ATTEMPTS . "): {$e->getMessage()}");
                }
                $row->save();
            }
        }

        $this->info("✅ Retry concluído. Recuperados: {$recovered}, ainda falhando: {$stillFailing}, blacklisted: {$blacklisted}");
        Log::info('✅ [MOVIDESK RETRY] Concluído', [
            'recovered'     => $recovered,
            'still_failing' => $stillFailing,
            'blacklisted'   => $blacklisted,
        ]);

        return Command::SUCCESS;
    }
}
