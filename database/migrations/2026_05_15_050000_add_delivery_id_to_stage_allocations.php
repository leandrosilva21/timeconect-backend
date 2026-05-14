<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alocação no nível da atividade (Pilar B do refactor 2026-05-15).
 *
 * Adiciona `delivery_id` em `stage_allocations`. Linhas antigas
 * (delivery_id=null) permanecem stage-level (compat). Novos endpoints
 * `/activities/{id}/allocations` setam delivery_id e validam contra
 * `delivery.hours_planned`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stage_allocations', function (Blueprint $table) {
            if (!Schema::hasColumn('stage_allocations', 'delivery_id')) {
                $table->foreignId('delivery_id')
                    ->nullable()
                    ->after('stage_id')
                    ->constrained('stage_deliveries')
                    ->nullOnDelete();
                $table->index('delivery_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stage_allocations', function (Blueprint $table) {
            if (Schema::hasColumn('stage_allocations', 'delivery_id')) {
                $table->dropForeign(['delivery_id']);
                $table->dropIndex(['delivery_id']);
                $table->dropColumn('delivery_id');
            }
        });
    }
};
