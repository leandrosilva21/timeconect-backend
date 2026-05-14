<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atividade como unidade de execução (Pilar A do refactor).
 *
 * Adiciona `delivery_id` em `stage_activity_events` para que comentários,
 * anexos e eventos automáticos (apontamento, aporte, move) tenham escopo
 * de atividade. Eventos com `delivery_id=null` permanecem stage-level
 * (compat com histórico).
 *
 * Mantém ADR 0005 (timeline única).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stage_activity_events', function (Blueprint $table) {
            if (!Schema::hasColumn('stage_activity_events', 'delivery_id')) {
                $table->foreignId('delivery_id')
                    ->nullable()
                    ->after('stage_id')
                    ->constrained('stage_deliveries')
                    ->nullOnDelete();
                $table->index(['delivery_id', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('stage_activity_events', function (Blueprint $table) {
            if (Schema::hasColumn('stage_activity_events', 'delivery_id')) {
                $table->dropForeign(['delivery_id']);
                $table->dropIndex(['delivery_id', 'created_at']);
                $table->dropColumn('delivery_id');
            }
        });
    }
};
