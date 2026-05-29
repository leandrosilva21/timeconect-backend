<?php

namespace App\Attachments\Backfill;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\StageActivityEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * FASE 11.3 — Backfill STAGE_ACTIVITY_EVENT.attachment.
 *
 * stage_activity_events tem 4 colunas: attachment_path, attachment_original_name,
 * attachment_mime, attachment_size. Disco 'public'. Categoria='attachment'.
 *
 * Particularidades:
 *  - Eventos podem ter ATTACHMENT sem texto (comentário só com arquivo).
 *  - actor_user_id pode ser null (eventos do sistema) → fallback admin.
 *  - Os 4 valores da row vem já validados pelo legado; preservamos.
 */
class StageActivityEventAttachmentBackfill implements BackfillModule
{
    public function run(AttachmentService $service, Command $cli, bool $dryRun, int $limit = 0): array
    {
        $stats = ['migrated' => 0, 'deduped' => 0, 'orphan_file' => 0, 'errors' => 0, 'skipped' => 0];

        $q = StageActivityEvent::query()->whereNotNull('attachment_path');
        if ($limit > 0) $q->limit($limit);

        $total = (clone $q)->count();
        if ($total === 0) {
            $cli->info('Nenhum stage_activity_event com attachment_path. OK.');
            return $stats;
        }

        $cli->info("Backfill STAGE_ACTIVITY_EVENT.attachment — varrendo {$total} evento(s)...");
        $bar = $cli->getOutput()->createProgressBar($total);
        $bar->start();

        $fallbackActor = User::where('type', 'admin')->orderBy('id')->first();
        if ($fallbackActor === null) {
            $cli->error('Sem admin no sistema — impossível auditar backfill.');
            $stats['errors'] = $total;
            return $stats;
        }

        $q->chunkById(200, function ($chunk) use ($service, $cli, $dryRun, $fallbackActor, $bar, &$stats) {
            foreach ($chunk as $ev) {
                /** @var StageActivityEvent $ev */
                try {
                    $path = (string) $ev->attachment_path;

                    $existing = Attachment::query()
                        ->forEntity('STAGE_ACTIVITY_EVENT', $ev->id)
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
                        $cli->warn("  ⚠ StageActivityEvent#{$ev->id}: arquivo físico ausente — {$path}");
                        $bar->advance();
                        continue;
                    }

                    if ($dryRun) {
                        $stats['migrated']++;
                        $bar->advance();
                        continue;
                    }

                    $actor = ($ev->actor_user_id ? User::find($ev->actor_user_id) : null) ?? $fallbackActor;

                    $service->registerExisting($actor, [
                        'entity_type'   => 'STAGE_ACTIVITY_EVENT',
                        'entity_id'     => $ev->id,
                        'category'      => 'attachment',
                        'storage_path'  => $path,
                        'original_name' => $ev->attachment_original_name ?: basename($path),
                        'mime_type'     => $ev->attachment_mime ?: 'application/octet-stream',
                        'metadata'      => ['backfill' => true, 'source' => 'attachment_path_column'],
                    ]);

                    $stats['migrated']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $cli->newLine();
                    $cli->error("  ✗ StageActivityEvent#{$ev->id}: " . $e->getMessage());
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
