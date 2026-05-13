<?php

namespace App\Console\Commands;

use App\Models\Timesheet;
use Illuminate\Console\Command;

class TimesheetsResendStatusEmailsCommand extends Command
{
    protected $signature   = 'timesheets:resend-status-emails {--days=90 : Janela em dias} {--status= : rejected|adjustment_requested|conflicted; vazio = todos os 3} {--dry-run : Lista sem enviar}';
    protected $description = 'Reenvia notificação de status para os donos dos apontamentos em rejected/adjustment_requested/conflicted dentro da janela.';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $status = $this->option('status');
        $dry    = (bool) $this->option('dry-run');

        $statuses = $status
            ? [$status]
            : [Timesheet::STATUS_REJECTED, Timesheet::STATUS_ADJUSTMENT_REQUESTED, Timesheet::STATUS_CONFLICTED];

        $statusKeyMap = [
            Timesheet::STATUS_REJECTED              => 'REJEITADO',
            Timesheet::STATUS_ADJUSTMENT_REQUESTED  => 'AJUSTE',
            Timesheet::STATUS_CONFLICTED            => 'CONFLITO',
        ];

        $since = now()->subDays($days);

        $query = Timesheet::with(['user:id,name,email', 'project:id,name,customer_id', 'project.customer:id,name'])
            ->whereIn('status', $statuses)
            ->where('updated_at', '>=', $since)
            ->whereHas('user', fn ($q) => $q->whereNotNull('email'))
            ->orderBy('updated_at');

        $total = $query->count();
        $this->info("Encontrados {$total} apontamentos com status [".implode(', ', $statuses)."] nos últimos {$days} dias.");

        if ($total === 0) return self::SUCCESS;

        $sent = 0;
        $skipped = 0;
        $failures = 0;
        $byUser = [];

        $query->chunkById(200, function ($chunk) use (&$sent, &$skipped, &$failures, &$byUser, $statusKeyMap, $dry) {
            foreach ($chunk as $ts) {
                $statusKey = $statusKeyMap[$ts->status] ?? null;
                if (!$statusKey || !$ts->user || !$ts->user->email) {
                    $skipped++;
                    continue;
                }
                $byUser[$ts->user->email] = ($byUser[$ts->user->email] ?? 0) + 1;
                if ($dry) {
                    $sent++;
                    continue;
                }
                try {
                    $ts->notifyOwnerOfStatus($statusKey, $ts->rejection_reason);
                    $sent++;
                } catch (\Throwable $e) {
                    $failures++;
                    $this->warn("Falha ts={$ts->id}: ".$e->getMessage());
                }
            }
        });

        $this->info(($dry ? '[DRY] ' : '')."Enviados: {$sent} | Pulados: {$skipped} | Falhas: {$failures}");
        $this->line('Distribuição por destinatário (top 20):');
        arsort($byUser);
        foreach (array_slice($byUser, 0, 20, true) as $email => $count) {
            $this->line("  {$count}x  {$email}");
        }

        return self::SUCCESS;
    }
}
