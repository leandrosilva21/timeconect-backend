<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 11.7 (PR 7b) — Drop tabelas dedicadas de anexos.
 *
 * Pré-requisitos:
 *   - PRs 1-6 + PR 7a mergeados em prod
 *   - Backfill rodado (todos com 0 missing no legacy-drop-preview)
 *
 * Tabelas dropadas:
 *   - project_attachments              (PROJECT          / category=proposal|contract|logo|attachment)
 *   - contract_attachments             (CONTRACT         / category=proposal|contract|logo|client_approval)
 *   - project_message_attachments      (PROJECT_MESSAGE  / category=attachment)
 *   - contract_message_attachments     (CONTRACT_MESSAGE / category=attachment)
 *   - contract_request_message_attachments (REQUEST_MESSAGE / category=attachment)
 *
 * Cada uma com Schema::hasTable guard pra reentrant.
 *
 * Down() NÃO recria as tabelas — drop em prod é via PR de rollback do backup
 * .sql.gz, não migration:rollback (perda iminente). Documentado no runbook.
 *
 * Arquivos físicos preservados (legacy storage paths em receipts/, projects/,
 * contracts/, message-attachments/, contract-message-attachments/,
 * req-message-attachments/) — agora referenciados só por `attachments.storage_path`.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'project_attachments',
            'contract_attachments',
            'project_message_attachments',
            'contract_message_attachments',
            'contract_request_message_attachments',
        ] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }

    public function down(): void
    {
        // No-op por design. Recovery via backup .sql.gz se necessário —
        // schema recriado vazio só atrapalha porque os dados não voltam.
    }
};
