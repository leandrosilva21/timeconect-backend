<?php

namespace App\Attachments\Backfill;

use App\Attachments\AttachableEntitiesRegistry;
use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * FASE 11.3 — Backfill USER.avatar.
 *
 * Para cada user com `profile_photo` não-null:
 *  - Se já existe Attachment USER/{id} category=avatar não-deletado → skip (idempotente).
 *  - Se o arquivo físico legado existe → registra via registerExisting (mesmo path).
 *  - Se o arquivo sumiu (path no DB mas sem físico) → marca como orphan_file e segue.
 *
 * O service faz dedup por checksum, então `registerExisting` chamado 2× devolve a
 * mesma row sem criar duplicada.
 *
 * Importante: o arquivo permanece em `storage/app/public/profile_photos/` —
 * "manter where they are" (decisão FASE 11). Backfill só cria a row.
 */
class UserAvatarBackfill implements BackfillModule
{
    public function run(AttachmentService $service, Command $cli, bool $dryRun, int $limit = 0): array
    {
        $stats = ['migrated' => 0, 'deduped' => 0, 'orphan_file' => 0, 'errors' => 0, 'skipped' => 0];

        $q = User::query()->whereNotNull('profile_photo');
        if ($limit > 0) $q->limit($limit);

        $total = (clone $q)->count();
        if ($total === 0) {
            $cli->info('Nenhum user com profile_photo. OK.');
            return $stats;
        }

        $cli->info("Backfill USER.avatar — varrendo {$total} user(s)...");
        $bar = $cli->getOutput()->createProgressBar($total);
        $bar->start();

        // Pega um actor pra audit (admin id=1 do sistema, fallback pra qualquer admin).
        $actor = User::where('type', 'admin')->orderBy('id')->first();
        if ($actor === null) {
            $cli->error('Sem admin no sistema — impossível auditar backfill.');
            $stats['errors'] = $total;
            return $stats;
        }

        $q->chunkById(200, function ($chunk) use ($service, $cli, $dryRun, $actor, $bar, &$stats) {
            foreach ($chunk as $user) {
                /** @var User $user */
                try {
                    $path = (string) $user->profile_photo;

                    // Idempotência rápida: já existe attachment vivo?
                    $existing = Attachment::query()
                        ->forEntity('USER', $user->id)
                        ->ofCategory('avatar')
                        ->whereNull('deleted_at')
                        ->first();
                    if ($existing !== null) {
                        // Se aponta pro MESMO arquivo, é dedup; se aponta pra outro, deixa ambos.
                        if ($existing->storage_path === $path) {
                            $stats['deduped']++;
                        } else {
                            $stats['skipped']++;
                        }
                        $bar->advance();
                        continue;
                    }

                    // Arquivo físico existe? (Storage::disk('public') hoje)
                    if (!Storage::disk('public')->exists($path)) {
                        $stats['orphan_file']++;
                        $cli->newLine();
                        $cli->warn("  ⚠ USER#{$user->id}: arquivo físico ausente — {$path}");
                        $bar->advance();
                        continue;
                    }

                    if ($dryRun) {
                        $stats['migrated']++;
                        $bar->advance();
                        continue;
                    }

                    // Decide o MIME pelo extension do path (já que não temos UploadedFile aqui).
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $mime = match ($ext) {
                        'png' => 'image/png',
                        'jpg', 'jpeg' => 'image/jpeg',
                        'webp' => 'image/webp',
                        'gif' => 'image/gif',
                        default => 'application/octet-stream',
                    };

                    $service->registerExisting($actor, [
                        'entity_type'   => 'USER',
                        'entity_id'     => $user->id,
                        'category'      => 'avatar',
                        'storage_path'  => $path,
                        'original_name' => basename($path),
                        'mime_type'     => $mime,
                        'metadata'      => ['backfill' => true, 'source' => 'profile_photo_column'],
                    ]);

                    $stats['migrated']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $cli->newLine();
                    $cli->error("  ✗ USER#{$user->id}: " . $e->getMessage());
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
