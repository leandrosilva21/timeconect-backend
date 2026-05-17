<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prazo macro da etapa (Pilar 2).
 *
 * `stage_start_at` = data prevista de início da etapa. `expected_end_date`
 * (já existe) serve como fim previsto. O cálculo de risco de atraso do
 * projeto compara max(expected_end_date) das etapas contra
 * `projects.expected_end_date`. Sem derivação automática — apenas alerta
 * visual quando o somatório das etapas estoura o prazo macro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_stages', function (Blueprint $table) {
            if (!Schema::hasColumn('project_stages', 'stage_start_at')) {
                $table->date('stage_start_at')->nullable()->after('expected_end_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_stages', function (Blueprint $table) {
            if (Schema::hasColumn('project_stages', 'stage_start_at')) {
                $table->dropColumn('stage_start_at');
            }
        });
    }
};
