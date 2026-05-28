<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aporte v2 — anexo da proposta/aprovação comercial nos aportes
 * criados via Kanban Contratos. Aplica só a aportes em projetos PAI
 * (cards no kanban geram proposta comercial). Aportes em filhos não
 * carregam anexo (continuam funcionando como hoje).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hour_contributions', function (Blueprint $table) {
            if (!Schema::hasColumn('hour_contributions', 'proposta_path')) {
                $table->string('proposta_path', 500)->nullable()->after('description');
            }
            if (!Schema::hasColumn('hour_contributions', 'proposta_original_name')) {
                $table->string('proposta_original_name', 255)->nullable()->after('proposta_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hour_contributions', function (Blueprint $table) {
            if (Schema::hasColumn('hour_contributions', 'proposta_path')) {
                $table->dropColumn('proposta_path');
            }
            if (Schema::hasColumn('hour_contributions', 'proposta_original_name')) {
                $table->dropColumn('proposta_original_name');
            }
        });
    }
};
