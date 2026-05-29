<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 11.7 — Drop legacy inline attachment columns.
 *
 * Pré-requisitos:
 *   - PRs 1-6 da FASE 11 mergeados em prod
 *   - Backfill completo + idempotente
 *   - `attachments:legacy-drop-preview` mostrando 0 missing em todas
 *     as colunas inline listadas abaixo
 *
 * Colunas dropadas (todas com Schema::hasColumn guard pra reentrant):
 *   - users.profile_photo
 *   - expenses.receipt_path, expenses.receipt_original_name
 *   - timesheets.attachment_path, timesheets.attachment_original_name
 *   - hour_contributions.proposta_path, hour_contributions.proposta_original_name
 *   - fechamento_notas.nfse_path, fechamento_notas.nfse_original_name
 *   - fechamento_notas.nota_debito_path, fechamento_notas.nota_debito_original_name
 *
 * NÃO inclui: tabelas dedicadas (project_attachments, contract_attachments, 3
 * *_message_attachments). Essas vão em PR 8 (refator dos controllers de leitura).
 *
 * Arquivos físicos NÃO são tocados — continuam nos paths atuais (receipts/,
 * profile_photos/, timesheets/, hour_contributions/, fechamento-notas/), agora
 * referenciados só pela tabela `attachments` (storage_path).
 *
 * Down() recria as colunas vazias (estrutural) — restore de dados não cabe a
 * migration. Use o backup .sql.gz pré-deploy se precisar voltar.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'profile_photo')) $t->dropColumn('profile_photo');
        });

        Schema::table('expenses', function (Blueprint $t) {
            if (Schema::hasColumn('expenses', 'receipt_path'))           $t->dropColumn('receipt_path');
            if (Schema::hasColumn('expenses', 'receipt_original_name'))  $t->dropColumn('receipt_original_name');
        });

        Schema::table('timesheets', function (Blueprint $t) {
            if (Schema::hasColumn('timesheets', 'attachment_path'))           $t->dropColumn('attachment_path');
            if (Schema::hasColumn('timesheets', 'attachment_original_name')) $t->dropColumn('attachment_original_name');
        });

        Schema::table('hour_contributions', function (Blueprint $t) {
            if (Schema::hasColumn('hour_contributions', 'proposta_path'))          $t->dropColumn('proposta_path');
            if (Schema::hasColumn('hour_contributions', 'proposta_original_name')) $t->dropColumn('proposta_original_name');
        });

        Schema::table('fechamento_notas', function (Blueprint $t) {
            if (Schema::hasColumn('fechamento_notas', 'nfse_path'))                 $t->dropColumn('nfse_path');
            if (Schema::hasColumn('fechamento_notas', 'nfse_original_name'))        $t->dropColumn('nfse_original_name');
            if (Schema::hasColumn('fechamento_notas', 'nota_debito_path'))          $t->dropColumn('nota_debito_path');
            if (Schema::hasColumn('fechamento_notas', 'nota_debito_original_name')) $t->dropColumn('nota_debito_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'profile_photo')) $t->string('profile_photo')->nullable();
        });

        Schema::table('expenses', function (Blueprint $t) {
            if (!Schema::hasColumn('expenses', 'receipt_path'))          $t->string('receipt_path')->nullable();
            if (!Schema::hasColumn('expenses', 'receipt_original_name')) $t->string('receipt_original_name')->nullable();
        });

        Schema::table('timesheets', function (Blueprint $t) {
            if (!Schema::hasColumn('timesheets', 'attachment_path'))           $t->string('attachment_path')->nullable();
            if (!Schema::hasColumn('timesheets', 'attachment_original_name')) $t->string('attachment_original_name')->nullable();
        });

        Schema::table('hour_contributions', function (Blueprint $t) {
            if (!Schema::hasColumn('hour_contributions', 'proposta_path'))          $t->string('proposta_path')->nullable();
            if (!Schema::hasColumn('hour_contributions', 'proposta_original_name')) $t->string('proposta_original_name')->nullable();
        });

        Schema::table('fechamento_notas', function (Blueprint $t) {
            if (!Schema::hasColumn('fechamento_notas', 'nfse_path'))                 $t->string('nfse_path')->nullable();
            if (!Schema::hasColumn('fechamento_notas', 'nfse_original_name'))        $t->string('nfse_original_name')->nullable();
            if (!Schema::hasColumn('fechamento_notas', 'nota_debito_path'))          $t->string('nota_debito_path')->nullable();
            if (!Schema::hasColumn('fechamento_notas', 'nota_debito_original_name')) $t->string('nota_debito_original_name')->nullable();
        });
    }
};
