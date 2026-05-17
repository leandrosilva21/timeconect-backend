<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cronograma operacional — extras (ADR 0009 appendix).
 *
 * - `stage_deliveries.dependency_type`: tipo da dependência leve. Hoje
 *   whitelist = ['FS']; coluna existe para abrir caminho futuro (SS/FF/SF)
 *   sem nova migration.
 * - `project_stages.actual_start_at` / `actual_end_at`: rollup das datas reais
 *   das atividades. Setadas pelo StageDeliveryObserver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('stage_deliveries', 'dependency_type')) {
                $table->string('dependency_type', 8)
                    ->default('FS')
                    ->after('depends_on_delivery_id');
            }
        });

        Schema::table('project_stages', function (Blueprint $table) {
            if (!Schema::hasColumn('project_stages', 'actual_start_at')) {
                $table->dateTime('actual_start_at')->nullable()->after('stage_start_at');
            }
            if (!Schema::hasColumn('project_stages', 'actual_end_at')) {
                $table->dateTime('actual_end_at')->nullable()->after('actual_start_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('stage_deliveries', 'dependency_type')) {
                $table->dropColumn('dependency_type');
            }
        });

        Schema::table('project_stages', function (Blueprint $table) {
            if (Schema::hasColumn('project_stages', 'actual_end_at')) {
                $table->dropColumn('actual_end_at');
            }
            if (Schema::hasColumn('project_stages', 'actual_start_at')) {
                $table->dropColumn('actual_start_at');
            }
        });
    }
};
