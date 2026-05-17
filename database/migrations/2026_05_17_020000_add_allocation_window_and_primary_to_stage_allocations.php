<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atividade como núcleo operacional — alocação ganha janela temporal +
 * marcador de owner principal.
 *
 * - `allocation_start_at` / `allocation_end_at` (date nullable): janela em que
 *   o consultor está alocado naquela atividade. Default null = janela = prazo
 *   da atividade. Útil quando a alocação é parcial (ex: Maria só nas 2
 *   primeiras semanas).
 * - `is_primary` (bool default false): marca o owner principal da atividade.
 *   Deve coincidir com `stage_deliveries.responsible_user_id` (sincronizado
 *   pelo controller). Máximo 1 primary por delivery.
 *
 * Tabela mantém o nome `stage_allocations` (rename físico arriscado); papel
 * é "activity_allocations" desde Pilar B (delivery_id obrigatório de fato).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stage_allocations', function (Blueprint $table) {
            if (!Schema::hasColumn('stage_allocations', 'allocation_start_at')) {
                $table->date('allocation_start_at')->nullable()->after('planned_hours');
            }
            if (!Schema::hasColumn('stage_allocations', 'allocation_end_at')) {
                $table->date('allocation_end_at')->nullable()->after('allocation_start_at');
            }
            if (!Schema::hasColumn('stage_allocations', 'is_primary')) {
                $table->boolean('is_primary')->default(false)->after('allocation_end_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stage_allocations', function (Blueprint $table) {
            if (Schema::hasColumn('stage_allocations', 'is_primary')) {
                $table->dropColumn('is_primary');
            }
            if (Schema::hasColumn('stage_allocations', 'allocation_end_at')) {
                $table->dropColumn('allocation_end_at');
            }
            if (Schema::hasColumn('stage_allocations', 'allocation_start_at')) {
                $table->dropColumn('allocation_start_at');
            }
        });
    }
};
