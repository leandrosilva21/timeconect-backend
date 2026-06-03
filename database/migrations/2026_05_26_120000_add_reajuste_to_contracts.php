<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reajuste dos contratos recorrentes (gestão de aniversário):
 *  - valor_inicial: valor-base contratado sobre o qual incide o reajuste
 *  - taxa_reajuste: índice aplicado (IPCA, IGP-M)
 *  - pct_reajuste:  percentual do índice aplicado no período
 * Valor ajustado = valor_inicial * (1 + pct_reajuste/100) — calculado (não persistido).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'valor_inicial')) {
                $table->decimal('valor_inicial', 14, 2)->nullable()->after('data_vencimento');
            }
            if (!Schema::hasColumn('contracts', 'taxa_reajuste')) {
                $table->string('taxa_reajuste', 12)->nullable()->after('valor_inicial');
            }
            if (!Schema::hasColumn('contracts', 'pct_reajuste')) {
                $table->decimal('pct_reajuste', 7, 3)->nullable()->after('taxa_reajuste');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            foreach (['valor_inicial', 'taxa_reajuste', 'pct_reajuste'] as $col) {
                if (Schema::hasColumn('contracts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
