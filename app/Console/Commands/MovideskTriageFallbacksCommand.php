<?php

namespace App\Console\Commands;

use App\Models\MovideskAgent;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\MovideskService;
use Illuminate\Console\Command;

class MovideskTriageFallbacksCommand extends Command
{
    protected $signature   = 'movidesk:triage-fallbacks {--execute : Aplica de fato (soft-delete + reassign). Sem essa flag é dry-run.}';
    protected $description = 'Triage de timesheets com origin=movidesk_fallback criados antes dos fixes (Promax → soft-delete, consultor cadastrado → reassign)';

    public function handle(MovideskService $svc): int
    {
        $execute = (bool) $this->option('execute');
        $mode    = $execute ? 'EXECUTE' : 'DRY-RUN';
        $this->info("Modo: {$mode}");

        $tss = Timesheet::where('origin', 'movidesk_fallback')
            ->whereNotNull('movidesk_appointment_id')
            ->get();

        $sum = ['total' => $tss->count(), 'deleted' => 0, 'reassigned' => 0, 'kept' => 0, 'errors' => 0];

        foreach ($tss as $t) {
            try {
                $ticket = $svc->fetchTicket((int) $t->ticket);
                if (!$ticket) {
                    $this->warn("ts={$t->id} ticket={$t->ticket}: fetch falhou — KEEP");
                    $sum['kept']++;
                    continue;
                }

                $found = null;
                foreach ($ticket['actions'] ?? [] as $a) {
                    foreach ($a['timeAppointments'] ?? [] as $appt) {
                        if ((int) $appt['id'] === (int) $t->movidesk_appointment_id) {
                            $found = $a['createdBy'] ?? null;
                            break 2;
                        }
                    }
                }

                $mid = $found['id'] ?? null;
                $em  = strtolower($found['email'] ?? '');
                $nm  = $found['businessName'] ?? $found['name'] ?? '';

                $ag = $mid ? MovideskAgent::where('movidesk_id', (string) $mid)->first() : null;
                if (!$ag && $em) $ag = MovideskAgent::where('email', $em)->first();

                $eff = $em ?: ($ag ? strtolower((string) $ag->email) : '');
                $isPromax = ($eff && stripos($eff, '@promax.') !== false)
                         || ($ag && stripos((string) $ag->team, 'promax') !== false);

                $action = null;
                $newUserId = null;

                if ($isPromax) {
                    $action = "DELETE-PROMAX (email={$eff} name={$nm})";
                } elseif ($ag && $ag->user_id) {
                    $u = User::where('id', $ag->user_id)->where('enabled', true)->first();
                    if ($u) {
                        $action = "REASSIGN→user:{$u->id} (via agent cache, email={$eff})";
                        $newUserId = $u->id;
                    }
                } elseif ($eff) {
                    $u = User::where('email', $eff)->where('enabled', true)->first();
                    if ($u) {
                        $action = "REASSIGN→user:{$u->id} (via email match)";
                        $newUserId = $u->id;
                    }
                }

                if (!$action) {
                    $action = "KEEP-PADRAO (email={$eff} name={$nm})";
                    $sum['kept']++;
                    $this->line("ts={$t->id} ticket={$t->ticket} → {$action}");
                    continue;
                }

                $this->line("ts={$t->id} ticket={$t->ticket} curr_user={$t->user_id} → {$action}");

                if (!$execute) continue;

                if ($isPromax) {
                    $t->_logSource = 'movidesk_sync';
                    $t->delete();
                    $sum['deleted']++;
                } elseif ($newUserId) {
                    $t->user_id = $newUserId;
                    $t->origin  = 'webhook';
                    $t->_logSource = 'movidesk_sync';
                    $t->save();
                    $sum['reassigned']++;
                }
            } catch (\Throwable $e) {
                $sum['errors']++;
                $this->error("ts={$t->id} ticket={$t->ticket}: erro — " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("SUMMARY [{$mode}] total={$sum['total']} deleted={$sum['deleted']} reassigned={$sum['reassigned']} kept={$sum['kept']} errors={$sum['errors']}");

        if (!$execute && ($sum['total'] > 0)) {
            $this->newLine();
            $this->warn("Pra aplicar de verdade: php artisan movidesk:triage-fallbacks --execute");
        }

        return self::SUCCESS;
    }
}
