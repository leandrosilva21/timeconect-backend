<?php

namespace App\Attachments\Backfill;

use App\Attachments\AttachmentService;
use Illuminate\Console\Command;

/**
 * FASE 11.3 — Contrato comum dos módulos de backfill.
 *
 * Cada implementação:
 *  1. Itera registros legados candidatos (com path != null, sem attachment correspondente)
 *  2. Pra cada um: chama AttachmentService::registerExisting
 *  3. Retorna stats: ['migrated','deduped','orphan_file','errors','skipped']
 *
 * Idempotência é garantida pelo dedup do service (checksum match dentro de entity+category).
 */
interface BackfillModule
{
    /**
     * @return array{migrated: int, deduped: int, orphan_file: int, errors: int, skipped: int}
     */
    public function run(AttachmentService $service, Command $cli, bool $dryRun, int $limit = 0): array;
}
