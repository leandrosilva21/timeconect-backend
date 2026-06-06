<?php

namespace App\Console\Commands;

use App\Models\Timesheet;
use Illuminate\Console\Command;

/**
 * Limpa timesheets de origem Movidesk com duração < 5 minutos.
 *
 * Regra global: apontamentos do Movidesk com effort_minutes < 5 não devem
 * existir no Time Conect (já bloqueado em MovideskService::processAppointment e
 * MovideskService::reprocessTimesheet). Este comando faz a varredura
 * retroativa pra remover registros que entraram antes/durante a brecha.
 *
 * Soft-delete via $r->delete() + _logSource='movidesk_sync', registrando
 * em timesheet_logs pelo TimesheetObserver.
 */
class MovideskCleanupShortTimesheetsCommand extends Command
{
    protected $signature = 'movidesk:cleanup-short-timesheets
        {--dry-run : Apenas lista o que seria feito, sem modificar nada}
        {--threshold=5 : Duração mínima em minutos (inclusivo no corte: <X é apagado)}';

    protected $description = 'Soft-deleta timesheets de origem Movidesk com effort_minutes < threshold (default 5)';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $threshold = (int) $this->option('threshold');

        $this->info($dryRun
            ? "🔍 DRY-RUN — listando timesheets Movidesk com effort_minutes < {$threshold}."
            : "⚠️  MODO APPLY — timesheets Movidesk com effort_minutes < {$threshold} serão SOFT-DELETADOS.");

        $rows = Timesheet::query()
            ->whereIn('origin', ['movidesk', 'webhook'])
            ->where('effort_minutes', '<', $threshold)
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'project_id', 'date', 'effort_minutes', 'ticket', 'movidesk_appointment_id', 'status', 'created_at']);

        $total = $rows->count();
        $this->line("Encontrados: {$total} timesheet(s).");
        if ($total === 0) return self::SUCCESS;

        $this->newLine();
        foreach ($rows as $r) {
            $this->line(sprintf(
                '  id=%d  user=%d  proj=%d  date=%s  effort=%dmin  ticket=%s  appt=%s  status=%s  created=%s',
                $r->id,
                $r->user_id,
                $r->project_id,
                $r->date?->format('Y-m-d') ?? '—',
                $r->effort_minutes,
                $r->ticket ?? '—',
                $r->movidesk_appointment_id ?? '—',
                $r->status,
                $r->created_at?->format('Y-m-d H:i') ?? '—'
            ));
        }
        $this->newLine();

        if ($dryRun) {
            $this->warn("⚠️  DRY-RUN: {$total} timesheet(s) seriam soft-deletados. Rode sem --dry-run pra aplicar.");
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($rows as $r) {
            $ts = Timesheet::find($r->id);
            if (!$ts) continue;
            $ts->_logSource = 'movidesk_sync';
            $ts->delete();
            $deleted++;
        }

        $this->info("✅ {$deleted} timesheet(s) soft-deletados. Log em timesheet_logs (source=movidesk_sync, action=deleted).");
        return self::SUCCESS;
    }
}
