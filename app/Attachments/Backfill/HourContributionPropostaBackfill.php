<?php

namespace App\Attachments\Backfill;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\HourContribution;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * FASE 11.3 — Backfill HOUR_CONTRIBUTION.proposal.
 *
 * Único módulo até agora com storage no disco `local` (privado) — não `public`.
 * O LocalStorageProvider tem heurística para isso (path 'hour_contributions/...'
 * resolve disco `local` por padrão).
 *
 * Particularidade: existem aportes legítimos SEM proposta (filhos, ajustes
 * manuais, etc.). O filtro whereNotNull('proposta_path') já cuida disso.
 */
class HourContributionPropostaBackfill implements BackfillModule
{
    public function run(AttachmentService $service, Command $cli, bool $dryRun, int $limit = 0): array
    {
        $stats = ['migrated' => 0, 'deduped' => 0, 'orphan_file' => 0, 'errors' => 0, 'skipped' => 0];

        // FASE 11.7 — coluna inline removida; backfill noop.
        if (!\Schema::hasColumn('hour_contributions', 'proposta_path')) {
            $cli->info('Coluna hour_contributions.proposta_path já removida (FASE 11.7). Backfill não aplicável.');
            return $stats;
        }

        $q = HourContribution::query()->whereNotNull('proposta_path');
        if ($limit > 0) $q->limit($limit);

        $total = (clone $q)->count();
        if ($total === 0) {
            $cli->info('Nenhum aporte com proposta_path. OK.');
            return $stats;
        }

        $cli->info("Backfill HOUR_CONTRIBUTION.proposal — varrendo {$total} aporte(s)...");
        $bar = $cli->getOutput()->createProgressBar($total);
        $bar->start();

        $fallbackActor = User::where('type', 'admin')->orderBy('id')->first();
        if ($fallbackActor === null) {
            $cli->error('Sem admin no sistema — impossível auditar backfill.');
            $stats['errors'] = $total;
            return $stats;
        }

        $q->chunkById(200, function ($chunk) use ($service, $cli, $dryRun, $fallbackActor, $bar, &$stats) {
            foreach ($chunk as $hc) {
                /** @var HourContribution $hc */
                try {
                    $path = (string) $hc->proposta_path;

                    // Idempotência
                    $existing = Attachment::query()
                        ->forEntity('HOUR_CONTRIBUTION', $hc->id)
                        ->ofCategory('proposal')
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

                    // Aporte usa disco LOCAL (privado), não public.
                    if (!Storage::disk('local')->exists($path)) {
                        $stats['orphan_file']++;
                        $cli->newLine();
                        $cli->warn("  ⚠ HourContribution#{$hc->id}: arquivo físico ausente — {$path}");
                        $bar->advance();
                        continue;
                    }

                    if ($dryRun) {
                        $stats['migrated']++;
                        $bar->advance();
                        continue;
                    }

                    $actor = User::find($hc->contributed_by) ?? $fallbackActor;

                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $mime = match ($ext) {
                        'pdf'   => 'application/pdf',
                        'doc'   => 'application/msword',
                        'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'xls'   => 'application/vnd.ms-excel',
                        'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'ppt'   => 'application/vnd.ms-powerpoint',
                        'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                        'png'   => 'image/png',
                        'jpg', 'jpeg' => 'image/jpeg',
                        'txt'   => 'text/plain',
                        'csv'   => 'text/csv',
                        'zip'   => 'application/zip',
                        default => 'application/octet-stream',
                    };

                    $service->registerExisting($actor, [
                        'entity_type'   => 'HOUR_CONTRIBUTION',
                        'entity_id'     => $hc->id,
                        'category'      => 'proposal',
                        'storage_path'  => $path,
                        'original_name' => $hc->proposta_original_name ?: basename($path),
                        'mime_type'     => $mime,
                        'metadata'      => ['backfill' => true, 'source' => 'proposta_path_column'],
                    ]);

                    $stats['migrated']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $cli->newLine();
                    $cli->error("  ✗ HourContribution#{$hc->id}: " . $e->getMessage());
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
