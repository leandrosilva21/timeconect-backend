<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cronograma operacional (ADR 0009 — cronograma e board mesma entidade).
 *
 * Adiciona em `stage_deliveries`:
 * - `planned_start_at`: data planejada de início da atividade.
 * - `actual_start_at`: timestamp real do primeiro move out of backlog
 *   (auto-set pelo Observer).
 * - `depends_on_delivery_id`: FK self nullable, dependência leve (1 atividade
 *   pode depender de no máximo 1 outra; sem DAG).
 *
 * `due_date` (= planejado fim) e `completed_at` (= real fim) já existem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('stage_deliveries', 'planned_start_at')) {
                $table->date('planned_start_at')->nullable()->after('hours_planned');
            }
            if (!Schema::hasColumn('stage_deliveries', 'actual_start_at')) {
                $table->dateTime('actual_start_at')->nullable()->after('completed_at');
            }
            if (!Schema::hasColumn('stage_deliveries', 'depends_on_delivery_id')) {
                $table->foreignId('depends_on_delivery_id')
                    ->nullable()
                    ->after('order_index')
                    ->constrained('stage_deliveries')
                    ->nullOnDelete();
                $table->index('depends_on_delivery_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('stage_deliveries', 'depends_on_delivery_id')) {
                $table->dropForeign(['depends_on_delivery_id']);
                $table->dropIndex(['depends_on_delivery_id']);
                $table->dropColumn('depends_on_delivery_id');
            }
            if (Schema::hasColumn('stage_deliveries', 'actual_start_at')) {
                $table->dropColumn('actual_start_at');
            }
            if (Schema::hasColumn('stage_deliveries', 'planned_start_at')) {
                $table->dropColumn('planned_start_at');
            }
        });
    }
};
