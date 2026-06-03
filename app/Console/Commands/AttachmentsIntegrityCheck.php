<?php

namespace App\Console\Commands;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use Illuminate\Console\Command;

/**
 * FASE 11 — Job de integridade diária.
 *
 * Para cada Attachment non-deleted:
 *   - entidade-dona existe? (via EntityRegistry)
 *   - storage_provider conhecido?
 *   - arquivo físico existe?
 *   - checksum bate?
 *
 * Falhas são gravadas em attachment_events (event_type=integrity_fail) pelo
 * próprio AttachmentService::integrityCheck. Este comando agrega o resultado e
 * imprime um sumário no log + opcionalmente envia email pro admin.
 *
 * Uso:
 *   php artisan attachments:integrity-check                 # incremental (default: últimos 7 dias)
 *   php artisan attachments:integrity-check --all           # varre tudo
 *   php artisan attachments:integrity-check --since=2026-01 # desde a data
 *   php artisan attachments:integrity-check --limit=1000    # cap
 *   php artisan attachments:integrity-check --dry-run       # não grava nada
 *
 * NOTA: o flag --dry-run aqui é informativo — a service grava attachment_events
 * automaticamente em falhas. Pra dry-run real, futuro: passar flag ao service.
 */
class AttachmentsIntegrityCheck extends Command
{
    protected $signature = 'attachments:integrity-check
        {--all : Varre todos os anexos (default: últimos 7 dias)}
        {--since= : Data inicial YYYY-MM-DD (sobrepõe --all)}
        {--limit=0 : Limita o número de anexos (0=sem limite)}
        {--dry-run : Não escreve eventos de falha (informativo)}';

    protected $description = 'Verifica integridade dos anexos: entidade-dona, arquivo físico, checksum.';

    public function handle(AttachmentService $service): int
    {
        $q = Attachment::query()->whereNull('deleted_at');

        if ($since = $this->option('since')) {
            $q->where('created_at', '>=', $since . ' 00:00:00');
            $this->info("Escopo: desde {$since}");
        } elseif (!$this->option('all')) {
            $q->where('created_at', '>=', now()->subDays(7));
            $this->info('Escopo: últimos 7 dias (use --all pra varrer tudo)');
        } else {
            $this->info('Escopo: todos os anexos');
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) $q->limit($limit);

        $total = (clone $q)->count();
        if ($total === 0) {
            $this->info('Nenhum anexo no escopo. OK.');
            return self::SUCCESS;
        }

        $this->info("Verificando {$total} anexo(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $okCount = 0;
        $failCount = 0;
        $byReason = [
            'entity_missing'            => 0,
            'file_missing'              => 0,
            'checksum_mismatch'         => 0,
            'checksum_error'            => 0,
            'storage_provider_mismatch' => 0,
        ];
        $sampleFailures = []; // até 20 amostras pra log/email

        // chunkById pra não estourar memória em muitos anexos.
        $q->chunkById(200, function ($chunk) use ($service, $bar, &$okCount, &$failCount, &$byReason, &$sampleFailures) {
            foreach ($chunk as $att) {
                try {
                    $report = $service->integrityCheck($att);
                    if ($report->isHealthy()) {
                        $okCount++;
                    } else {
                        $failCount++;
                        foreach ($report->failures() as $f) {
                            $byReason[$f['reason']] = ($byReason[$f['reason']] ?? 0) + 1;
                        }
                        if (count($sampleFailures) < 20) {
                            $sampleFailures[] = [
                                'id'      => $att->id,
                                'entity'  => "{$att->entity_type}#{$att->entity_id}",
                                'summary' => $report->summary(),
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    $failCount++;
                    if (count($sampleFailures) < 20) {
                        $sampleFailures[] = [
                            'id'      => $att->id,
                            'entity'  => "{$att->entity_type}#{$att->entity_id}",
                            'summary' => 'EXCEPTION: ' . $e->getMessage(),
                        ];
                    }
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("OK:   {$okCount}");
        $this->warn("Fail: {$failCount}");
        $this->newLine();

        if ($failCount > 0) {
            $this->table(['reason', 'count'], collect($byReason)->filter()->map(
                fn ($n, $r) => ['reason' => $r, 'count' => $n]
            )->values()->all());

            $this->newLine();
            $this->warn('Amostras de falhas (até 20):');
            $this->table(['id', 'entity', 'summary'], $sampleFailures);

            // Log estruturado também (pra agregar em monitoring no futuro).
            \Log::warning('attachments:integrity-check found failures', [
                'total'    => $total,
                'failed'   => $failCount,
                'by_reason'=> $byReason,
                'samples'  => $sampleFailures,
            ]);
        }

        // Exit code: 0 se tudo OK, 1 se houve qualquer falha (CI/monitoring pode alertar).
        return $failCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}
