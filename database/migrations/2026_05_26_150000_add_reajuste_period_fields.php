<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos do período EXPLÍCITO do reajuste:
 *  - contracts.data_ultimo_reajuste: data do último reajuste aplicado (= início do
 *    próximo período; na falta dele usa-se data_assinatura como data-base).
 *  - contract_value_changes.periodo_formatado: rótulo do período (ex.: "Jul/2024 → Jun/2025"),
 *    gravado no histórico para auditoria/transparência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'data_ultimo_reajuste')) {
                $table->date('data_ultimo_reajuste')->nullable()->after('pct_reajuste');
            }
        });
        Schema::table('contract_value_changes', function (Blueprint $table) {
            if (!Schema::hasColumn('contract_value_changes', 'periodo_formatado')) {
                $table->string('periodo_formatado', 60)->nullable()->after('periodo_fim');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'data_ultimo_reajuste')) {
                $table->dropColumn('data_ultimo_reajuste');
            }
        });
        Schema::table('contract_value_changes', function (Blueprint $table) {
            if (Schema::hasColumn('contract_value_changes', 'periodo_formatado')) {
                $table->dropColumn('periodo_formatado');
            }
        });
    }
};
