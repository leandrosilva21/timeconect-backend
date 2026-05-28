<?php

namespace App\Attachments\Backfill;

use App\Attachments\AttachmentService;
use App\Models\Attachment;
use App\Models\FechamentoNota;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * FASE 11.3 — Backfill FECHAMENTO_NOTA.
 *
 * Particularidade: cada row tem ATÉ 2 documentos (nfse_path + nota_debito_path),
 * que viram 2 attachments separados (category='nfse' e category='nota_debito').
 *
 * Disco 'public' (fechamento-notas/...).
 *
 * Actor preservado: nfse_decided_by / nota_debito_decided_by quando setados;
 * fallback admin.
 */
class FechamentoNotaBackfill implements BackfillModule
{
    public function run(AttachmentService $service, Command $cli, bool $dryRun, int $limit = 0): array
    {
        $stats = ['migrated' => 0, 'deduped' => 0, 'orphan_file' => 0, 'errors' => 0, 'skipped' => 0];

        // Pega rows com pelo menos 1 documento.
        $q = FechamentoNota::query()
            ->where(function ($w) {
                $w->whereNotNull('nfse_path')->orWhereNotNull('nota_debito_path');
            });
        if ($limit > 0) $q->limit($limit);

        $total = (clone $q)->count();
        if ($total === 0) {
            $cli->info('Nenhuma fechamento_nota com documento. OK.');
            return $stats;
        }

        $cli->info("Backfill FECHAMENTO_NOTA — varrendo {$total} row(s) (até 2 docs/row)...");
        $bar = $cli->getOutput()->createProgressBar($total);
        $bar->start();

        $fallbackActor = User::where('type', 'admin')->orderBy('id')->first();
        if ($fallbackActor === null) {
            $cli->error('Sem admin no sistema — impossível auditar backfill.');
            $stats['errors'] = $total;
            return $stats;
        }

        $q->chunkById(200, function ($chunk) use ($service, $cli, $dryRun, $fallbackActor, $bar, &$stats) {
            foreach ($chunk as $nota) {
                /** @var FechamentoNota $nota */
                foreach (['nfse', 'nota_debito'] as $tipo) {
                    $path = (string) ($nota->{$tipo . '_path'} ?? '');
                    if ($path === '') continue;

                    try {
                        // Idempotência: já tem attachment vivo nesse path+categoria?
                        $existing = Attachment::query()
                            ->forEntity('FECHAMENTO_NOTA', $nota->id)
                            ->ofCategory($tipo)
                            ->where('storage_path', $path)
                            ->whereNull('deleted_at')
                            ->first();
                        if ($existing !== null) {
                            $stats['deduped']++;
                            continue;
                        }

                        if (!Storage::disk('public')->exists($path)) {
                            $stats['orphan_file']++;
                            $cli->newLine();
                            $cli->warn("  ⚠ FechamentoNota#{$nota->id} {$tipo}: arquivo físico ausente — {$path}");
                            continue;
                        }

                        if ($dryRun) {
                            $stats['migrated']++;
                            continue;
                        }

                        // Actor: quem decidiu o doc (uma proxy razoável); fallback admin.
                        $decidedBy = $nota->{$tipo . '_decided_by'};
                        $actor = ($decidedBy ? User::find($decidedBy) : null) ?? $fallbackActor;

                        $original = $nota->{$tipo . '_original_name'} ?: basename($path);
                        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                        $mime = match ($ext) {
                            'pdf'   => 'application/pdf',
                            'png'   => 'image/png',
                            'jpg', 'jpeg' => 'image/jpeg',
                            default => 'application/octet-stream',
                        };

                        $service->registerExisting($actor, [
                            'entity_type'   => 'FECHAMENTO_NOTA',
                            'entity_id'     => $nota->id,
                            'category'      => $tipo,
                            'storage_path'  => $path,
                            'original_name' => $original,
                            'mime_type'     => $mime,
                            'metadata'      => [
                                'backfill'     => true,
                                'source'       => 'fechamento_notas_columns',
                                'notable_type' => $nota->notable_type,
                                'notable_id'   => $nota->notable_id,
                                'year_month'   => $nota->year_month,
                                'tipo'         => $tipo,
                            ],
                        ]);

                        $stats['migrated']++;
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        $cli->newLine();
                        $cli->error("  ✗ FechamentoNota#{$nota->id} {$tipo}: " . $e->getMessage());
                    }
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
