<?php

namespace App\Console\Commands;

use App\Models\Timesheet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Limpeza retroativa de timesheets duplicados criados quando o Movidesk
 * substituía o `appointment.id` em edições e o sync (antes do fix em
 * MovideskService::findEditedCandidate) criava um timesheet novo em vez
 * de atualizar o existente.
 *
 * Critério de match: timesheets ativos com mesmo (ticket, user_id, date)
 * e movidesk_appointment_id NOT NULL. Mantém o de MAIOR created_at
 * (provavelmente carrega o appointment.id atual do Movidesk) e soft-deleta
 * os demais — gerando log de auditoria via TimesheetObserver.
 */
class MovideskDedupeEditedTimesheetsCommand extends Command
{
    protected $signature = 'movidesk:dedupe-edited-timesheets
        {--dry-run : Apenas lista os grupos suspeitos, sem deletar}
        {--limit=0 : Limita quantos grupos processar (0 = todos)}';

    protected $description = 'Soft-deleta timesheets duplicados gerados por edições antigas no Movidesk (mesmo ticket+user+date)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = (int) $this->option('limit');

        $this->info($dryRun
            ? '🔍 DRY-RUN — nenhum registro será modificado.'
            : '⚠️  MODO APPLY — duplicatas serão SOFT-DELETADAS com log de auditoria.');

        // 1) Identifica grupos suspeitos:
        //    mesmo (user_id, ticket, date), >1 registro ativo, todos com movidesk_appointment_id.
        $groupsQuery = DB::table('timesheets')
            ->select('user_id', 'ticket', 'date', DB::raw('COUNT(*) as total'))
            ->whereNull('deleted_at')
            ->whereNotNull('movidesk_appointment_id')
            ->whereNotNull('ticket')
            ->groupBy('user_id', 'ticket', 'date')
            ->havingRaw('COUNT(*) > 1');

        if ($limit > 0) $groupsQuery->limit($limit);

        $groups = $groupsQuery->get();

        if ($groups->isEmpty()) {
            $this->info('✅ Nenhum grupo de duplicação encontrado. Nada a fazer.');
            return self::SUCCESS;
        }

        $this->info("📋 {$groups->count()} grupo(s) suspeito(s) de duplicação encontrado(s).");
        $this->newLine();

        $totalToDelete = 0;
        $deletedIds    = [];

        foreach ($groups as $g) {
            $rows = Timesheet::with(['user:id,name', 'project:id,code,name'])
                ->where('user_id', $g->user_id)
                ->where('ticket', $g->ticket)
                ->where('date', $g->date)
                ->whereNotNull('movidesk_appointment_id')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            if ($rows->count() < 2) continue;

            $keep    = $rows->first();
            $discard = $rows->slice(1);

            $this->line("─── Ticket {$g->ticket} · user {$g->user_id} ({$keep->user?->name}) · {$g->date} ───");
            $this->line(sprintf(
                '  ✅ MANTÉM  id=%d  appt=%s  effort=%dmin  created=%s',
                $keep->id,
                $keep->movidesk_appointment_id,
                $keep->effort_minutes,
                $keep->created_at?->format('Y-m-d H:i')
            ));

            foreach ($discard as $d) {
                $this->line(sprintf(
                    '  ❌ DELETA  id=%d  appt=%s  effort=%dmin  created=%s  status=%s',
                    $d->id,
                    $d->movidesk_appointment_id,
                    $d->effort_minutes,
                    $d->created_at?->format('Y-m-d H:i'),
                    $d->status
                ));
                $totalToDelete++;
                $deletedIds[] = $d->id;

                if (!$dryRun) {
                    $d->_logSource = 'movidesk_sync';
                    $d->delete(); // soft-delete + TimesheetObserver gera entrada em timesheet_logs
                }
            }
            $this->newLine();
        }

        $this->newLine();
        if ($dryRun) {
            $this->warn("⚠️  DRY-RUN: $totalToDelete timesheet(s) seriam soft-deletados.");
            $this->line('Rode novamente sem --dry-run pra aplicar.');
        } else {
            $this->info("✅ $totalToDelete timesheet(s) soft-deletados. Log de auditoria gerado em timesheet_logs.");
            $this->line('IDs: ' . implode(', ', $deletedIds));
        }

        return self::SUCCESS;
    }
}
