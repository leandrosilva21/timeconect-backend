<?php

namespace App\Attachments\Backfill;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * FASE 11.3 — Base reutilizável dos 3 backfills de chat (PROJECT, CONTRACT, REQUEST).
 *
 * As 3 tabelas legadas têm schema idêntico: id, message_id, original_name, file_path,
 * file_size, mime_type. Os 3 backfills só diferem em: classe do attachment legado,
 * classe da mensagem (pra resolver actor preservando autoria), e entity_type.
 *
 * Subclasses implementam só 3 métodos: entityType(), attachmentModel(), messageModel().
 */
abstract class MessageAttachmentBackfillBase implements BackfillModule
{
    /** Retorna 'PROJECT_MESSAGE' | 'CONTRACT_MESSAGE' | 'REQUEST_MESSAGE'. */
    abstract protected function entityType(): string;

    /** Classe Eloquent do attachment legado (com cols: message_id, original_name, file_path, file_size, mime_type). */
    abstract protected function attachmentModel(): string;

    /** Classe Eloquent da mensagem (com col: user_id ou author_id — preserva autoria). */
    abstract protected function messageModel(): string;

    /** Nome do campo de autor na mensagem (default 'author_id'; varia por modelo). */
    protected function authorColumn(): string
    {
        return 'author_id';
    }

    public function run(AttachmentService $service, Command $cli, bool $dryRun, int $limit = 0): array
    {
        $stats = ['migrated' => 0, 'deduped' => 0, 'orphan_file' => 0, 'errors' => 0, 'skipped' => 0];

        $attModel = $this->attachmentModel();
        $msgModel = $this->messageModel();
        $entityType = $this->entityType();
        $authorCol  = $this->authorColumn();

        $q = $attModel::query()->whereNotNull('file_path');
        if ($limit > 0) $q->limit($limit);

        $total = (clone $q)->count();
        if ($total === 0) {
            $cli->info("Nenhum {$entityType}.attachment legado. OK.");
            return $stats;
        }

        $cli->info("Backfill {$entityType}.attachment — varrendo {$total} anexo(s)...");
        $bar = $cli->getOutput()->createProgressBar($total);
        $bar->start();

        $fallbackActor = User::where('type', 'admin')->orderBy('id')->first();
        if ($fallbackActor === null) {
            $cli->error('Sem admin no sistema — impossível auditar backfill.');
            $stats['errors'] = $total;
            return $stats;
        }

        $q->chunkById(200, function ($chunk) use ($service, $cli, $dryRun, $fallbackActor, $bar, &$stats, $msgModel, $entityType, $authorCol) {
            foreach ($chunk as $att) {
                /** @var Model $att */
                try {
                    $path = (string) $att->file_path;
                    $messageId = (int) $att->message_id;

                    // Idempotência por (entity, message_id, path)
                    $existing = Attachment::query()
                        ->forEntity($entityType, $messageId)
                        ->ofCategory('attachment')
                        ->where('storage_path', $path)
                        ->whereNull('deleted_at')
                        ->first();
                    if ($existing !== null) {
                        $stats['deduped']++;
                        $bar->advance();
                        continue;
                    }

                    if (!Storage::disk('public')->exists($path)) {
                        $stats['orphan_file']++;
                        $cli->newLine();
                        $cli->warn("  ⚠ {$entityType}#{$messageId} att#{$att->id}: arquivo físico ausente — {$path}");
                        $bar->advance();
                        continue;
                    }

                    if ($dryRun) {
                        $stats['migrated']++;
                        $bar->advance();
                        continue;
                    }

                    // Actor: autor da mensagem (preserva autoria); fallback admin.
                    $msg = $msgModel::find($messageId);
                    $actorId = $msg ? ($msg->{$authorCol} ?? null) : null;
                    $actor = ($actorId ? User::find($actorId) : null) ?? $fallbackActor;

                    $service->registerExisting($actor, [
                        'entity_type'   => $entityType,
                        'entity_id'     => $messageId,
                        'category'      => 'attachment',
                        'storage_path'  => $path,
                        'original_name' => $att->original_name ?: basename($path),
                        'mime_type'     => $att->mime_type ?: 'application/octet-stream',
                        'metadata'      => ['backfill' => true, 'source' => 'legacy_attachment_table', 'legacy_id' => $att->id],
                    ]);

                    $stats['migrated']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $cli->newLine();
                    $cli->error("  ✗ {$entityType} att#{$att->id}: " . $e->getMessage());
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
