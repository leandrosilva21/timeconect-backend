<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 11.7 — Backfill safety hop (homolog/dev2 only).
 *
 * Em prod o backfill foi rodado MANUALMENTE entre os PRs #4 e #7a/#7b. Em
 * ambientes com auto-migrate (homolog Render / dev2 Render) as migrations
 * rodam todas em sequência no startup — sem essa migration de safety, as
 * 000010 (drop inline cols) e 000020 (drop dedicated tables) apagariam dados
 * que ainda não foram migrados pra tabela `attachments`.
 *
 * Esta migration roda `attachments:backfill --all` SE a tabela `attachments`
 * já existir (000001 sempre vem antes) E o command estiver registrado. É
 * idempotente — rodar 2× só dedup por checksum.
 *
 * Em prod (já rodado manualmente) o command vai ser noop porque tudo está
 * deduped, e a migration registra-se em `migrations` sem efeito colateral.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('attachments')) {
            return; // sem infra, nada a fazer
        }

        try {
            Artisan::call('attachments:backfill', ['--all' => true]);
        } catch (\Throwable $e) {
            // Não bloqueia migrate — backfill pode ser re-rodado manualmente.
            // Mas loga pra investigação.
            \Log::warning('FASE 11.7 backfill safety hop falhou; rodar manualmente pós-deploy', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        // No-op por design.
    }
};
