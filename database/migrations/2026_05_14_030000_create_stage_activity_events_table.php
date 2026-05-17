<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timeline operacional consolidada da etapa (append-only).
 * Recebe TODOS os eventos relevantes: movimentação de entrega, apontamento,
 * aporte, bloqueio, conclusão, comentários.
 *
 * Ver ADR 0005. Não criar tabelas paralelas — toda atividade entra aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stage_activity_events')) {
            return;
        }

        Schema::create('stage_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('project_stages')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30)->comment('delivery_moved | delivery_created | delivery_completed | hours_logged | aporte_created | block_set | block_cleared | comment');
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['stage_id', 'created_at'], 'stage_activity_stage_created_idx');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_activity_events');
    }
};
