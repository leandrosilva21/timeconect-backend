<?php

namespace App\Console\Commands;

use App\Models\Timesheet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rede de segurança pro reset de conflitos órfãos.
 *
 * O TimesheetObserver já chama resolveStaleConflicts em saved/deleted —
 * mas isso só vale pra mudanças via Eloquent. Esta varredura agendada
 * (cron a cada 10 min) cobre:
 *   - Apontamentos legados que ficaram presos em "conflicted" antes do
 *     observer ser plugado.
 *   - Mudanças feitas via SQL bruto (DB::table) que não disparam observer.
 *   - Race conditions ou cenários onde o save acontece em paralelo.
 *
 * Estratégia: agrupa todos os timesheets ativos com status=conflicted
 * por (user_id, date) e roda Timesheet::resolveStaleConflicts em cada par.
 * O método decide individualmente quem ainda tem sobreposição (mantém) e
 * quem perdeu (vira pending).
 */
class TimesheetsResolveStaleConflictsCommand extends Command
{
    protected $signature = 'timesheets:resolve-stale-conflicts {--dry-run : Apenas lista o que seria feito, sem alterar}';

    protected $description = 'Re-avalia apontamentos com status=conflicted e reverte pra pending os que não têm mais sobreposição';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $groups = DB::table('timesheets')
            ->whereNull('deleted_at')
            ->where('status', Timesheet::STATUS_CONFLICTED)
            ->whereNotNull('user_id')
            ->whereNotNull('date')
            ->select('user_id', 'date')
            ->distinct()
            ->get();

        $this->info(sprintf(
            '%s%d par(es) (user, data) com status=conflicted.',
            $dryRun ? '🔍 DRY-RUN — encontrados ' : '⚙️  Processando ',
            $groups->count()
        ));

        if ($groups->isEmpty()) {
            return self::SUCCESS;
        }

        $before = Timesheet::where('status', Timesheet::STATUS_CONFLICTED)->count();

        foreach ($groups as $g) {
            $date = $g->date instanceof \DateTimeInterface ? $g->date->format('Y-m-d') : substr((string) $g->date, 0, 10);
            if ($dryRun) {
                $count = Timesheet::where('user_id', $g->user_id)
                    ->where('date', $date)
                    ->where('status', Timesheet::STATUS_CONFLICTED)
                    ->count();
                $this->line(sprintf('  user=%d date=%s conflicted=%d', $g->user_id, $date, $count));
                continue;
            }
            Timesheet::resolveStaleConflicts((int) $g->user_id, $date);
        }

        if ($dryRun) {
            $this->warn("Rode sem --dry-run pra aplicar.");
            return self::SUCCESS;
        }

        $after = Timesheet::where('status', Timesheet::STATUS_CONFLICTED)->count();
        $resolved = max(0, $before - $after);
        $this->info(sprintf('✅ %d apontamento(s) revertido(s) de conflicted → pending. Restantes em conflito: %d.', $resolved, $after));

        return self::SUCCESS;
    }
}
