<?php

namespace App\Console\Commands;

use App\Models\Timesheet;
use App\Services\MovideskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Limpeza retroativa de timesheets duplicados gerados antes do fix em
 * MovideskService::findEditedCandidate, quando o Movidesk recriava o
 * `appointment.id` em edições e o sync criava um timesheet novo em vez
 * de atualizar o existente.
 *
 * Estratégia segura:
 *   1. Identifica grupos suspeitos (mesmo user+ticket+date com >1 ativo).
 *   2. Pra cada grupo, consulta o ticket no Movidesk e coleta os
 *      appointment.id ATUAIS.
 *   3. Soft-deleta APENAS os timesheets cujo movidesk_appointment_id
 *      sumiu do Movidesk (= edição real que recriou id).
 *   4. Timesheets cujo id AINDA existe no Movidesk são apontamentos
 *      legítimos — não toca.
 *
 * Gera log de auditoria via TimesheetObserver (source='movidesk_sync',
 * action='deleted'), visível em /auditoria/apontamentos.
 */
class MovideskDedupeEditedTimesheetsCommand extends Command
{
    protected $signature = 'movidesk:dedupe-edited-timesheets
        {--dry-run : Apenas lista o que seria feito, sem modificar nada}
        {--limit=0 : Limita quantos grupos processar (0 = todos)}';

    protected $description = 'Soft-deleta timesheets cujo appointment.id sumiu do Movidesk (edições antigas que duplicaram), validando contra a API';

    public function handle(MovideskService $movidesk): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = (int) $this->option('limit');

        $this->info($dryRun
            ? '🔍 DRY-RUN — nenhum registro será modificado.'
            : '⚠️  MODO APPLY — duplicatas confirmadas pela API serão SOFT-DELETADAS.');

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
            $this->info('✅ Nenhum grupo suspeito. Nada a fazer.');
            return self::SUCCESS;
        }

        $this->info("📋 {$groups->count()} grupo(s) suspeito(s).");
        $this->newLine();

        // Agrupa por ticket pra fazer 1 chamada de API por ticket (cache local)
        $ticketCache = [];

        $totalToDelete   = 0;
        $totalKeptLegit  = 0;
        $deletedIds      = [];
        $apiFailures     = 0;

        foreach ($groups as $g) {
            $rows = Timesheet::with(['user:id,name'])
                ->where('user_id', $g->user_id)
                ->where('ticket', $g->ticket)
                ->where('date', $g->date)
                ->whereNotNull('movidesk_appointment_id')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            if ($rows->count() < 2) continue;

            // 1 fetch por ticket (vários grupos podem compartilhar o mesmo ticket)
            $ticketIdInt = (int) $g->ticket;
            if (!array_key_exists($ticketIdInt, $ticketCache)) {
                $ticketData = $movidesk->fetchTicket($ticketIdInt);
                if ($ticketData === null) {
                    $ticketCache[$ticketIdInt] = null;
                } else {
                    $apptIds = [];
                    foreach ($ticketData['actions'] ?? [] as $action) {
                        foreach ($action['timeAppointments'] ?? [] as $appt) {
                            if (!empty($appt['id'])) {
                                $apptIds[] = (string) $appt['id'];
                            }
                        }
                    }
                    $ticketCache[$ticketIdInt] = $apptIds;
                }
            }

            $movideskApptIds = $ticketCache[$ticketIdInt];
            $userName = $rows->first()->user?->name ?? '—';
            $this->line("─── Ticket {$g->ticket} · user {$g->user_id} ({$userName}) · {$g->date} ───");

            if ($movideskApptIds === null) {
                $this->warn("  ⚠️  Falha ao consultar Movidesk pra este ticket — pulando grupo.");
                $apiFailures++;
                $this->newLine();
                continue;
            }

            $this->line('  📡 Movidesk tem ' . count($movideskApptIds) . ' appointment(s) ativo(s) neste ticket: ['
                . implode(',', $movideskApptIds) . ']');

            foreach ($rows as $r) {
                $apptId = (string) $r->movidesk_appointment_id;
                $existsInMovidesk = in_array($apptId, $movideskApptIds, true);

                if ($existsInMovidesk) {
                    $this->line(sprintf(
                        '  ✅ MANTÉM  id=%d  appt=%s  effort=%dmin  created=%s  (existe no Movidesk)',
                        $r->id, $apptId, $r->effort_minutes, $r->created_at?->format('Y-m-d H:i')
                    ));
                    $totalKeptLegit++;
                } else {
                    $this->line(sprintf(
                        '  ❌ DELETA  id=%d  appt=%s  effort=%dmin  created=%s  status=%s  (SUMIU do Movidesk)',
                        $r->id, $apptId, $r->effort_minutes, $r->created_at?->format('Y-m-d H:i'), $r->status
                    ));
                    $totalToDelete++;
                    $deletedIds[] = $r->id;

                    if (!$dryRun) {
                        $r->_logSource = 'movidesk_sync';
                        $r->delete(); // soft-delete + log via TimesheetObserver
                    }
                }
            }
            $this->newLine();
        }

        $this->newLine();
        $this->line("Resumo:");
        $this->line("  • Timesheets confirmados como legítimos (id existe no Movidesk): {$totalKeptLegit}");
        $this->line("  • Timesheets confirmados como órfãos (id sumiu do Movidesk):     {$totalToDelete}");
        if ($apiFailures > 0) {
            $this->warn("  • Grupos pulados por falha na API Movidesk: {$apiFailures}");
        }

        if ($dryRun) {
            $this->warn("⚠️  DRY-RUN: {$totalToDelete} timesheet(s) seriam soft-deletados. Rode sem --dry-run pra aplicar.");
        } else {
            $this->info("✅ {$totalToDelete} timesheet(s) soft-deletados. Log em timesheet_logs (source=movidesk_sync, action=deleted).");
            if (!empty($deletedIds)) {
                $this->line('IDs: ' . implode(', ', $deletedIds));
            }
        }

        return self::SUCCESS;
    }
}
