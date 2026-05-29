<?php

namespace App\Attachments\Backfill;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * FASE 11.3 — Backfill EXPENSE.receipt.
 *
 * Mesmo padrão do UserAvatarBackfill, ajustado pro escopo:
 *  - Lê Expense::whereNotNull('receipt_path')
 *  - Categoria = 'receipt'
 *  - Storage disk = 'public'
 *  - Actor da auditoria = expense.user (não admin) pra preservar autoria
 *    do upload original na timeline.
 */
class ExpenseReceiptBackfill implements BackfillModule
{
    public function run(AttachmentService $service, Command $cli, bool $dryRun, int $limit = 0): array
    {
        $stats = ['migrated' => 0, 'deduped' => 0, 'orphan_file' => 0, 'errors' => 0, 'skipped' => 0];

        // FASE 11.7 — coluna inline removida; backfill noop.
        if (!\Schema::hasColumn('expenses', 'receipt_path')) {
            $cli->info('Coluna expenses.receipt_path já removida (FASE 11.7). Backfill não aplicável.');
            return $stats;
        }

        $q = Expense::query()->whereNotNull('receipt_path');
        if ($limit > 0) $q->limit($limit);

        $total = (clone $q)->count();
        if ($total === 0) {
            $cli->info('Nenhuma expense com receipt_path. OK.');
            return $stats;
        }

        $cli->info("Backfill EXPENSE.receipt — varrendo {$total} despesa(s)...");
        $bar = $cli->getOutput()->createProgressBar($total);
        $bar->start();

        // Fallback de actor: admin do sistema. Só usado quando expense.user sumiu.
        $fallbackActor = User::where('type', 'admin')->orderBy('id')->first();
        if ($fallbackActor === null) {
            $cli->error('Sem admin no sistema — impossível auditar backfill.');
            $stats['errors'] = $total;
            return $stats;
        }

        $q->chunkById(200, function ($chunk) use ($service, $cli, $dryRun, $fallbackActor, $bar, &$stats) {
            foreach ($chunk as $expense) {
                /** @var Expense $expense */
                try {
                    $path = (string) $expense->receipt_path;

                    // Idempotência
                    $existing = Attachment::query()
                        ->forEntity('EXPENSE', $expense->id)
                        ->ofCategory('receipt')
                        ->whereNull('deleted_at')
                        ->first();
                    if ($existing !== null) {
                        if ($existing->storage_path === $path) {
                            $stats['deduped']++;
                        } else {
                            $stats['skipped']++;
                        }
                        $bar->advance();
                        continue;
                    }

                    if (!Storage::disk('public')->exists($path)) {
                        $stats['orphan_file']++;
                        $cli->newLine();
                        $cli->warn("  ⚠ Expense#{$expense->id}: arquivo físico ausente — {$path}");
                        $bar->advance();
                        continue;
                    }

                    if ($dryRun) {
                        $stats['migrated']++;
                        $bar->advance();
                        continue;
                    }

                    // Actor preservando autoria: o dono da despesa fez o upload original.
                    $actor = User::find($expense->user_id) ?? $fallbackActor;

                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $mime = match ($ext) {
                        'pdf' => 'application/pdf',
                        'png' => 'image/png',
                        'jpg', 'jpeg' => 'image/jpeg',
                        'webp' => 'image/webp',
                        default => 'application/octet-stream',
                    };

                    $service->registerExisting($actor, [
                        'entity_type'   => 'EXPENSE',
                        'entity_id'     => $expense->id,
                        'category'      => 'receipt',
                        'storage_path'  => $path,
                        'original_name' => $expense->receipt_original_name ?: basename($path),
                        'mime_type'     => $mime,
                        'metadata'      => ['backfill' => true, 'source' => 'receipt_path_column'],
                    ]);

                    $stats['migrated']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $cli->newLine();
                    $cli->error("  ✗ Expense#{$expense->id}: " . $e->getMessage());
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $cli->newLine(2);

        $cli->table(
            ['metric', 'count'],
            collect($stats)->map(fn ($n, $k) => ['metric' => $k, 'count' => $n])->values()->all()
        );

        return $stats;
    }
}
