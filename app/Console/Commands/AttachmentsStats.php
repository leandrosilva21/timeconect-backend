<?php

namespace App\Console\Commands;

use App\Attachments\AttachableEntitiesRegistry;
use App\Models\Attachment;
use App\Models\AttachmentEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * FASE 11.5 — Sumário CLI da camada de anexos.
 *
 * Mesmas métricas do endpoint /attachments/stats, formatadas pro console.
 * Útil pra cron diário com pipe pra alerta (Slack/email) ou inspeção rápida
 * via SSH em prod.
 *
 * Usos:
 *   php artisan attachments:stats
 *   php artisan attachments:stats --json   # output JSON (pra parse em script)
 *   php artisan attachments:stats --health # foca em saúde (integrity, mime, perm)
 */
class AttachmentsStats extends Command
{
    protected $signature = 'attachments:stats
        {--json : Output em JSON para parse}
        {--health : Foco em saúde (integrity, mime violations, permission denied)}';

    protected $description = 'FASE 11.5 — Sumário da camada de anexos (live count, bytes, top entities/categories).';

    public function handle(): int
    {
        $totalLive   = Attachment::query()->whereNull('deleted_at')->count();
        $totalAll    = Attachment::query()->withoutGlobalScopes()->withTrashed()->count();
        $totalBytes  = (int) Attachment::query()->whereNull('deleted_at')->sum('size_bytes');

        $upload24h = Attachment::query()->where('created_at', '>=', now()->subDay())->count();
        $upload7d  = Attachment::query()->where('created_at', '>=', now()->subDays(7))->count();
        $upload30d = Attachment::query()->where('created_at', '>=', now()->subDays(30))->count();

        $byEntity = Attachment::query()
            ->whereNull('deleted_at')
            ->groupBy('entity_type')
            ->select('entity_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(size_bytes) as bytes'))
            ->orderByDesc('count')
            ->get();

        $byCategory = Attachment::query()
            ->whereNull('deleted_at')
            ->groupBy('entity_type', 'category')
            ->select('entity_type', 'category', DB::raw('COUNT(*) as count'))
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        $integrityFail24h = AttachmentEvent::query()->where('event_type', AttachmentEvent::TYPE_INTEGRITY_FAIL)->where('created_at', '>=', now()->subDay())->count();
        $integrityFail7d  = AttachmentEvent::query()->where('event_type', AttachmentEvent::TYPE_INTEGRITY_FAIL)->where('created_at', '>=', now()->subDays(7))->count();
        $mimeViolations24h = AttachmentEvent::query()->where('event_type', AttachmentEvent::TYPE_MIME_VIOLATION)->where('created_at', '>=', now()->subDay())->count();
        $permDenied24h     = AttachmentEvent::query()->where('event_type', AttachmentEvent::TYPE_PERMISSION_DENIED)->where('created_at', '>=', now()->subDay())->count();

        if ($this->option('json')) {
            $this->line(json_encode([
                'totals' => [
                    'live' => $totalLive, 'all' => $totalAll, 'deleted' => $totalAll - $totalLive,
                    'bytes' => $totalBytes, 'human_size' => $this->humanSize($totalBytes),
                ],
                'uploads' => ['last_24h' => $upload24h, 'last_7d' => $upload7d, 'last_30d' => $upload30d],
                'health'  => [
                    'integrity_fail_24h'    => $integrityFail24h,
                    'integrity_fail_7d'     => $integrityFail7d,
                    'mime_violations_24h'   => $mimeViolations24h,
                    'permission_denied_24h' => $permDenied24h,
                    'healthy'               => $integrityFail7d === 0,
                ],
                'by_entity_type' => $byEntity->map(fn ($r) => [
                    'entity_type' => $r->entity_type,
                    'count' => (int) $r->count,
                    'bytes' => (int) $r->bytes,
                ])->all(),
                'by_category' => $byCategory->map(fn ($r) => [
                    'entity_type' => $r->entity_type,
                    'category' => $r->category,
                    'count' => (int) $r->count,
                ])->all(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        if ($this->option('health')) {
            return $this->renderHealth($integrityFail24h, $integrityFail7d, $mimeViolations24h, $permDenied24h);
        }

        $this->info('━━ Attachments — Sumário ━━');
        $this->line('');

        $this->line("<info>Totais</info>");
        $this->table(['Métrica', 'Valor'], [
            ['Live (não-deletados)', number_format($totalLive)],
            ['Todos (incl. soft-deleted)', number_format($totalAll)],
            ['Soft-deleted', number_format($totalAll - $totalLive)],
            ['Tamanho total', $this->humanSize($totalBytes)],
        ]);

        $this->line('');
        $this->line("<info>Uploads recentes</info>");
        $this->table(['Janela', 'Count'], [
            ['Últimas 24h', number_format($upload24h)],
            ['Últimos 7 dias', number_format($upload7d)],
            ['Últimos 30 dias', number_format($upload30d)],
        ]);

        $this->line('');
        $this->line("<info>Por entity_type</info>");
        $this->table(
            ['entity_type', 'count', 'bytes', 'registry?'],
            $byEntity->map(fn ($r) => [
                $r->entity_type,
                number_format((int) $r->count),
                $this->humanSize((int) $r->bytes),
                in_array($r->entity_type, AttachableEntitiesRegistry::knownTypes(), true) ? '✓' : '⚠',
            ])->all()
        );

        $this->line('');
        $this->line("<info>Por entity_type + category (top 20)</info>");
        $this->table(
            ['entity_type', 'category', 'count'],
            $byCategory->map(fn ($r) => [$r->entity_type, $r->category, number_format((int) $r->count)])->all()
        );

        $this->line('');
        $this->line("<info>Saúde</info>");
        $this->table(['Sinal', 'Valor', 'Status'], [
            ['Integrity fail 24h', $integrityFail24h, $integrityFail24h === 0 ? '<fg=green>OK</>' : '<fg=red>FAIL</>'],
            ['Integrity fail 7d',  $integrityFail7d,  $integrityFail7d === 0 ? '<fg=green>OK</>' : '<fg=red>FAIL</>'],
            ['MIME violations 24h', $mimeViolations24h, $mimeViolations24h === 0 ? '<fg=green>OK</>' : '<fg=yellow>ATENÇÃO</>'],
            ['Permission denied 24h', $permDenied24h, $permDenied24h === 0 ? '<fg=green>OK</>' : '<fg=yellow>ATENÇÃO</>'],
        ]);

        return $integrityFail7d > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function renderHealth(int $iFail24h, int $iFail7d, int $mime24h, int $perm24h): int
    {
        $this->info('━━ Attachments — Saúde ━━');
        $this->table(['Sinal', 'Valor', 'Status'], [
            ['Integrity fail 24h', $iFail24h, $iFail24h === 0 ? '<fg=green>OK</>' : '<fg=red>FAIL</>'],
            ['Integrity fail 7d',  $iFail7d,  $iFail7d === 0 ? '<fg=green>OK</>' : '<fg=red>FAIL</>'],
            ['MIME violations 24h', $mime24h, $mime24h === 0 ? '<fg=green>OK</>' : '<fg=yellow>ATENÇÃO</>'],
            ['Permission denied 24h', $perm24h, $perm24h === 0 ? '<fg=green>OK</>' : '<fg=yellow>ATENÇÃO</>'],
        ]);

        if ($iFail7d > 0) {
            $this->line('');
            $this->warn('Últimas 10 falhas de integridade:');
            $recent = AttachmentEvent::query()
                ->where('event_type', AttachmentEvent::TYPE_INTEGRITY_FAIL)
                ->with('attachment:id,entity_type,entity_id,category,storage_path')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();
            $this->table(['when', 'attachment', 'entity', 'category', 'failures'],
                $recent->map(fn ($e) => [
                    $e->created_at?->format('Y-m-d H:i'),
                    '#' . $e->attachment_id,
                    $e->attachment ? "{$e->attachment->entity_type}#{$e->attachment->entity_id}" : '?',
                    $e->attachment?->category ?? '?',
                    implode('; ', array_column($e->metadata['failures'] ?? [], 'reason')),
                ])->all()
            );
        }

        return $iFail7d > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        if ($bytes < 1073741824) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        return number_format($bytes / 1073741824, 1, ',', '.') . ' GB';
    }
}
