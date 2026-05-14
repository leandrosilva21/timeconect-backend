<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suporte a anexos em eventos da timeline (Pilar 3 — chat operacional).
 *
 * Mantém ADR 0005 (timeline única append-only). Anexos são metadados
 * inline na própria linha do evento — sem tabela paralela. O arquivo vive
 * no disco `public` em `stage-attachments/{stage_id}/{uuid}.ext`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stage_activity_events', function (Blueprint $table) {
            if (!Schema::hasColumn('stage_activity_events', 'attachment_path')) {
                $table->string('attachment_path')->nullable();
            }
            if (!Schema::hasColumn('stage_activity_events', 'attachment_original_name')) {
                $table->string('attachment_original_name')->nullable();
            }
            if (!Schema::hasColumn('stage_activity_events', 'attachment_mime')) {
                $table->string('attachment_mime', 100)->nullable();
            }
            if (!Schema::hasColumn('stage_activity_events', 'attachment_size')) {
                $table->integer('attachment_size')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stage_activity_events', function (Blueprint $table) {
            foreach (['attachment_path', 'attachment_original_name', 'attachment_mime', 'attachment_size'] as $col) {
                if (Schema::hasColumn('stage_activity_events', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
