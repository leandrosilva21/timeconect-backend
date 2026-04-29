<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\MovideskService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MovideskSyncCommand extends Command
{
    protected $signature   = 'movidesk:sync
                                {--since= : Buscar tickets desde esta data (ISO 8601, ex: 2026-04-08T10:00:00)}
                                {--hours=24 : Horas para buscar se não houver último sync salvo}';

    protected $description = 'Sincroniza apontamentos do Movidesk via API (fallback do webhook)';

    // Overlap para compensar delays da API do Movidesk
    private const OVERLAP_MINUTES = 5;

    // Janela mínima de busca (garante que nunca deixamos passar apontamentos
    // mesmo que o last_sync tenha sido gravado após um sync sem importações)
    private const MIN_LOOKBACK_HOURS = 48;

    public function handle(MovideskService $service): int
    {
        if (!config('services.movidesk.token')) {
            $this->error('MOVIDESK_API_TOKEN não configurado.');
            return Command::FAILURE;
        }

        $since = $this->resolveSince();

        $this->info("🔄 Buscando tickets atualizados desde: {$since->toIso8601String()}");
        Log::info('🔄 [MOVIDESK SYNC] Iniciando', ['since' => $since->toIso8601String()]);

        $tickets = $service->fetchTicketsSince($since);
        $count   = count($tickets);

        $this->info("📋 {$count} ticket(s) encontrado(s)");

        if ($count === 0) {
            $this->updateLastSync();
            return Command::SUCCESS;
        }

        $totalCreated = 0;

        foreach ($tickets as $ticketData) {
            $ticketId = $ticketData['id'] ?? '?';

            // Listing retorna só id+lastUpdate ($select obrigatório no Movidesk).
            // Busca ticket completo individualmente para ter clients, owner e timeAppointments.
            $ticketData = $service->fetchTicket((int) $ticketId);
            if (!$ticketData) {
                $this->warn("  ⚠️  Ticket #{$ticketId}: falhou ao buscar detalhes");
                continue;
            }

            $created       = $service->processTicket($ticketData);
            $totalCreated += $created;

            if ($created > 0) {
                $this->line("  ✅ Ticket #{$ticketId}: {$created} apontamento(s) importado(s)");
            }
        }

        $this->updateLastSync();

        $this->info("✅ Sync concluído. Timesheets criados: {$totalCreated}");
        Log::info('✅ [MOVIDESK SYNC] Concluído', [
            'tickets'    => $count,
            'created'    => $totalCreated,
        ]);

        return Command::SUCCESS;
    }

    private function resolveSince(): Carbon
    {
        // 1. --since explícito via CLI
        if ($sinceOption = $this->option('since')) {
            return Carbon::parse($sinceOption);
        }

        // 2. Último sync salvo (com overlap) — mas nunca mais recente que MIN_LOOKBACK_HOURS atrás
        $lastSync = SystemSetting::get('movidesk_last_sync');
        if ($lastSync) {
            $fromLastSync  = Carbon::parse($lastSync)->subMinutes(self::OVERLAP_MINUTES);
            $minLookback   = now()->subHours(self::MIN_LOOKBACK_HOURS);

            // Retorna o mais antigo dos dois (garante janela mínima de 48h)
            return $fromLastSync->lt($minLookback) ? $fromLastSync : $minLookback;
        }

        // 3. Fallback: N horas atrás (configurável via --hours)
        return now()->subHours((int) $this->option('hours'));
    }

    private function updateLastSync(): void
    {
        SystemSetting::set('movidesk_last_sync', now()->toIso8601String(), 'string', 'movidesk');
    }
}
