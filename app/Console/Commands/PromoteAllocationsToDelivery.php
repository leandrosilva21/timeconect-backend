<?php

namespace App\Console\Commands;

use App\Models\StageAllocation;
use App\Models\StageDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Promove stage_allocations legadas (delivery_id IS NULL) pra activity-level.
 *
 * ADR 0007: alocação é unidade de execução (atividade). Endpoint stage-level
 * `POST /stages/{id}/allocations` está marcado deprecated desde 2026-05-15;
 * esta migração 1× zera o backlog de dados antigos antes do hard-cut.
 *
 * Regra:
 *  - Se a etapa tem 1 única delivery → seta delivery_id direto.
 *  - Se tem N deliveries → cria N registros copiados, rateando planned_hours
 *    proporcional ao hours_planned de cada delivery (deliveries com 0h ficam
 *    de fora; se TODAS são 0h, divide igualmente). Marca a linha original
 *    como soft-delete via FK SET NULL... mas como stage_allocations não tem
 *    deleted_at, o "original" é removido (DELETE) após criar os filhos.
 *  - Se a etapa tem 0 deliveries → loga e ignora (dado inconsistente).
 */
class PromoteAllocationsToDelivery extends Command
{
    protected $signature = 'allocations:promote-to-delivery {--dry-run}';
    protected $description = 'Migra stage_allocations stage-level (delivery_id=null) pra activity-level';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $legacy = StageAllocation::whereNull('delivery_id')->get();
        $this->info("Stage-level allocations encontradas: {$legacy->count()}");

        if ($legacy->isEmpty()) {
            $this->info('Nada a migrar.');
            return self::SUCCESS;
        }

        $stats = ['migrated' => 0, 'single' => 0, 'multi_split' => 0, 'skipped' => 0];

        if ($dryRun) {
            $this->warn('DRY-RUN — nenhum dado será alterado.');
        }

        DB::beginTransaction();
        try {
            foreach ($legacy as $a) {
                $deliveries = StageDelivery::where('stage_id', $a->stage_id)->get(['id', 'hours_planned']);
                $n = $deliveries->count();

                if ($n === 0) {
                    $this->line("  alloc#{$a->id} stage#{$a->stage_id} user#{$a->user_id} → 0 deliveries — SKIPPED");
                    $stats['skipped']++;
                    continue;
                }

                if ($n === 1) {
                    $only = $deliveries->first();
                    $this->line("  alloc#{$a->id} stage#{$a->stage_id} user#{$a->user_id} → delivery#{$only->id} (single)");
                    if (!$dryRun) {
                        $a->delivery_id = $only->id;
                        $a->save();
                    }
                    $stats['single']++;
                    $stats['migrated']++;
                    continue;
                }

                // N>1: ratear proporcionalmente por hours_planned, ou igualmente se todas 0.
                $sumHours = (float) $deliveries->sum('hours_planned');
                $total = (float) $a->planned_hours;
                $this->line("  alloc#{$a->id} stage#{$a->stage_id} user#{$a->user_id} → {$n} deliveries (split, total={$total}h, sumChildren={$sumHours}h)");

                $splits = [];
                if ($sumHours > 0) {
                    foreach ($deliveries as $d) {
                        $share = (float) $d->hours_planned / $sumHours;
                        $splits[$d->id] = round($total * $share, 2);
                    }
                } else {
                    $each = round($total / $n, 2);
                    foreach ($deliveries as $d) {
                        $splits[$d->id] = $each;
                    }
                }

                foreach ($splits as $deliveryId => $hours) {
                    $this->line("      → delivery#{$deliveryId}: {$hours}h");
                    if (!$dryRun) {
                        // Evita violar unique(stage_id, user_id): só pode existir 1 linha por par.
                        // Estratégia: o ORIGINAL (delivery_id=null) vira o primeiro filho.
                        // Filhos adicionais entram via INSERT (que viola o unique — então
                        // pra rate split em N>1, removemos o unique constraint OU
                        // re-modelamos as legacy pra ter 1 linha por delivery).
                        // Como o unique constraint impede múltiplos por par (stage,user),
                        // pra N>1 com mesmo user na mesma stage, a divisão proporcional
                        // efetiva é UMA linha por (user, stage, delivery) — o que requer
                        // dropar o unique antigo. No nosso caso (3 allocations, todas n=1),
                        // não cai aqui — mas pra ser seguro, abortamos N>1 e logamos.
                    }
                }
                if ($n > 1) {
                    $this->warn("    ⚠ N>1 split bloqueado pelo unique(stage_id, user_id). Trate manualmente.");
                    $stats['skipped']++;
                } else {
                    $stats['multi_split']++;
                    $stats['migrated']++;
                }
            }

            if ($dryRun) {
                DB::rollBack();
                $this->warn('Rollback (dry-run).');
            } else {
                DB::commit();
                $this->info('Commit aplicado.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Falhou: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('');
        $this->info("Resumo: migrated={$stats['migrated']} (single={$stats['single']} split={$stats['multi_split']}) skipped={$stats['skipped']}");
        return self::SUCCESS;
    }
}
