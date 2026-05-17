<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aporte no nível da atividade (Pilar C do refactor 2026-05-15).
 *
 * Adiciona `delivery_id` FK em `stage_hour_aportes`. Linhas antigas
 * (delivery_id=null) ficam stage-level (compat). Novos aportes vão pra
 * atividade e incrementam `stage_deliveries.hours_planned` em vez de
 * `project_stages.hours_planned`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stage_hour_aportes', function (Blueprint $table) {
            if (!Schema::hasColumn('stage_hour_aportes', 'delivery_id')) {
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
        Schema::table('stage_hour_aportes', function (Blueprint $table) {
            if (Schema::hasColumn('stage_hour_aportes', 'delivery_id')) {
                $table->dropForeign(['delivery_id']);
                $table->dropIndex(['delivery_id', 'created_at']);
                $table->dropColumn('delivery_id');
            }
        });
    }
};
