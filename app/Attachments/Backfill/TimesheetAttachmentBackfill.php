<?php

namespace App\Attachments\Backfill;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * FASE 11.3 — Backfill TIMESHEET.attachment.
 *
 * Padrão idêntico ao UserAvatar/ExpenseReceipt. Particularidade: o destroy do
 * Timesheet em produção historicamente NÃO apaga o arquivo físico, então é
 * possível encontrar attachment_path apontando pra arquivo legítimo cujo
 * timesheet está soft-deleted ou hard-deleted. O Eloquent default exclui
 * soft-deleted; o filtro abaixo segue isso (não migra timesheet morto).
 */
class TimesheetAttachmentBackfill implements BackfillModule
{
    public function run(AttachmentService $service, Command $cli, bool $dryRun, int $limit = 0): array
    {
        $stats = ['migrated' => 0, 'deduped' => 0, 'orphan_file' => 0, 'errors' => 0, 'skipped' => 0];

        $q = Timesheet::query()->whereNotNull('attachment_path');
        if ($limit > 0) $q->limit($limit);

        $total = (clone $q)->count();
        if ($total === 0) {
            $cli->info('Nenhum timesheet com attachment_path. OK.');
            return $stats;
        }

        $cli->info("Backfill TIMESHEET.attachment — varrendo {$total} apontamento(s)...");
        $bar = $cli->getOutput()->createProgressBar($total);
        $bar->start();

        $fallbackActor = User::where('type', 'admin')->orderBy('id')->first();
        if ($fallbackActor === null) {
            $cli->error('Sem admin no sistema — impossível auditar backfill.');
            $stats['errors'] = $total;
            return $stats;
        }

        $q->chunkById(200, function ($chunk) use ($service, $cli, $dryRun, $fallbackActor, $bar, &$stats) {
            foreach ($chunk as $timesheet) {
                /** @var Timesheet $timesheet */
                try {
                    $path = (string) $timesheet->attachment_path;

                    // Idempotência
                    $existing = Attachment::query()
                        ->forEntity('TIMESHEET', $timesheet->id)
                        ->ofCategory('attachment')
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
                        $cli->warn("  ⚠ Timesheet#{$timesheet->id}: arquivo físico ausente — {$path}");
                        $bar->advance();
                        continue;
                    }

                    if ($dryRun) {
                        $stats['migrated']++;
                        $bar->advance();
                        continue;
                    }

                    // Actor: dono do timesheet (preserva autoria na timeline).
                    $actor = User::find($timesheet->user_id) ?? $fallbackActor;

                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $mime = match ($ext) {
                        'pdf'   => 'application/pdf',
                        'png'   => 'image/png',
                        'jpg', 'jpeg' => 'image/jpeg',
                        'webp'  => 'image/webp',
                        'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'txt'   => 'text/plain',
                        default => 'application/octet-stream',
                    };

                    $service->registerExisting($actor, [
                        'entity_type'   => 'TIMESHEET',
                        'entity_id'     => $timesheet->id,
                        'category'      => 'attachment',
                        'storage_path'  => $path,
                        'original_name' => $timesheet->attachment_original_name ?: basename($path),
                        'mime_type'     => $mime,
                        'metadata'      => ['backfill' => true, 'source' => 'attachment_path_column'],
                    ]);

                    $stats['migrated']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $cli->newLine();
                    $cli->error("  ✗ Timesheet#{$timesheet->id}: " . $e->getMessage());
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
