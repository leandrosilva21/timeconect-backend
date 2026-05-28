<?php

namespace App\Attachments\Backfill;

use App\Attachments\AttachmentService;
use App\Attachments\Concerns\DualWritesEntityAttachments;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * FASE 11.3 — Base reutilizável dos backfills de anexo direto da entidade
 * (PROJECT, CONTRACT).
 *
 * As 2 tabelas legadas têm schema quase idêntico:
 *  - {entity}_id, type (pt-BR), path, original_name, mime_type, size, uploaded_by_id
 *  - Disco LOCAL privado (sem 'public' no $file->store)
 *
 * O type pt-BR é mapeado pra category en-US via DualWritesEntityAttachments::mapAttachmentTypeToCategory.
 *
 * Subclasses implementam: entityType(), attachmentModel(), entityIdColumn().
 */
abstract class EntityAttachmentBackfillBase implements BackfillModule
{
    use DualWritesEntityAttachments;

    /** Ex: 'PROJECT' | 'CONTRACT'. */
    abstract protected function entityType(): string;

    /** Classe Eloquent do attachment legado. */
    abstract protected function attachmentModel(): string;

    /** Coluna FK pra entidade-dona (ex: 'project_id', 'contract_id'). */
    abstract protected function entityIdColumn(): string;

    public function run(AttachmentService $service, Command $cli, bool $dryRun, int $limit = 0): array
    {
        $stats = ['migrated' => 0, 'deduped' => 0, 'orphan_file' => 0, 'errors' => 0, 'skipped' => 0];

        $attModel = $this->attachmentModel();
        $entityType = $this->entityType();
        $entityIdCol = $this->entityIdColumn();

        $q = $attModel::query()->whereNotNull('path');
        if ($limit > 0) $q->limit($limit);

        $total = (clone $q)->count();
        if ($total === 0) {
            $cli->info("Nenhum {$entityType} attachment legado. OK.");
            return $stats;
        }

        $cli->info("Backfill {$entityType} attachment — varrendo {$total} anexo(s)...");
        $bar = $cli->getOutput()->createProgressBar($total);
        $bar->start();

        $fallbackActor = User::where('type', 'admin')->orderBy('id')->first();
        if ($fallbackActor === null) {
            $cli->error('Sem admin no sistema — impossível auditar backfill.');
            $stats['errors'] = $total;
            return $stats;
        }

        $q->chunkById(200, function ($chunk) use ($service, $cli, $dryRun, $fallbackActor, $bar, &$stats, $entityType, $entityIdCol) {
            foreach ($chunk as $att) {
                /** @var Model $att */
                try {
                    $path = (string) $att->path;
                    $entityId = (int) $att->{$entityIdCol};
                    $legacyType = (string) ($att->type ?? 'outro');
                    $category = self::mapAttachmentTypeToCategory($legacyType);

                    // Idempotência por (entity, path)
                    $existing = Attachment::query()
                        ->forEntity($entityType, $entityId)
                        ->where('storage_path', $path)
                        ->whereNull('deleted_at')
                        ->first();
                    if ($existing !== null) {
                        $stats['deduped']++;
                        $bar->advance();
                        continue;
                    }

                    // Disco LOCAL privado pra Project/Contract attachments. Confere em
                    // ambos os discos pra cobrir legados eventualmente movidos.
                    $existsLocal  = Storage::disk('local')->exists($path);
                    $existsPublic = Storage::disk('public')->exists($path);
                    if (!$existsLocal && !$existsPublic) {
                        $stats['orphan_file']++;
                        $cli->newLine();
                        $cli->warn("  ⚠ {$entityType}#{$entityId} att#{$att->id}: arquivo físico ausente — {$path}");
                        $bar->advance();
                        continue;
                    }

                    if ($dryRun) {
                        $stats['migrated']++;
                        $bar->advance();
                        continue;
                    }

                    $actor = ($att->uploaded_by_id ? User::find($att->uploaded_by_id) : null) ?? $fallbackActor;

                    $service->registerExisting($actor, [
                        'entity_type'   => $entityType,
                        'entity_id'     => $entityId,
                        'category'      => $category,
                        'storage_path'  => $path,
                        'original_name' => $att->original_name ?: basename($path),
                        'mime_type'     => $att->mime_type ?: 'application/octet-stream',
                        'metadata'      => [
                            'backfill'     => true,
                            'source'       => 'legacy_attachment_table',
                            'legacy_id'    => $att->id,
                            'legacy_type'  => $legacyType,
                        ],
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
