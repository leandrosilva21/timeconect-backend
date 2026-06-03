<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 11.1 — Timeline de auditoria de anexos.
 *
 * Cada operação no AttachmentService grava um evento aqui (uploaded, downloaded,
 * soft_deleted, restored, integrity_fail, mime_violation, ...). Permite:
 *  - "quem baixou o que e quando" (audit trail compliance)
 *  - timeline transversal global (ex.: feed "Ricardo anexou contrato.pdf no projeto X")
 *  - root cause de incidentes documentais (rastreio de operações)
 *
 * NÃO usar pra eventos de NEGÓCIO da entidade-dona — esses ficam no domínio dela
 * (project_change_logs, kanban_logs, etc). Aqui só estado do anexo em si.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('attachment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attachment_id')->constrained('attachments')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('event_type', 32);
            // Actor: nullable pra eventos do scheduler (integrity-check) sem user logado.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();          // IPv4/IPv6
            $table->string('user_agent', 512)->nullable();
            // Contexto livre: ex. failure reason no integrity_fail, signed URL TTL no downloaded.
            // json() em vez de jsonb pra portabilidade (PG/SQLite). Em prod vira jsonb.
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['attachment_id', 'created_at'], 'idx_att_events_attachment_time');
            $table->index('event_type', 'idx_att_events_type');
            $table->index('actor_user_id', 'idx_att_events_actor');
            $table->index('created_at', 'idx_att_events_created_at');
        });

        if (\DB::getDriverName() === 'pgsql') {
            \DB::statement("ALTER TABLE attachment_events ADD CONSTRAINT chk_att_events_type CHECK (event_type IN ('uploaded','downloaded','url_signed','soft_deleted','restored','integrity_fail','mime_violation','size_exceeded','permission_denied'))");

            \DB::statement("COMMENT ON TABLE attachment_events IS 'FASE 11: timeline de auditoria do AttachmentService. Não usar pra eventos de negócio da entidade-dona.'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_events');
    }
};
