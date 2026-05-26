<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Informações Administrativas do contrato:
 *  - data_assinatura: data de assinatura do contrato (data-base do reajuste)
 *  - data_vencimento: próximo aniversário/vencimento (dispara o reajuste anual)
 * Fonte de carga inicial: planilha "ANIVERSÁRIO - CLIENTES.xlsx" (aba Planilha1),
 * via `php artisan contracts:import-aniversario`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'data_assinatura')) {
                $table->date('data_assinatura')->nullable()->after('expectativa_inicio');
            }
            if (!Schema::hasColumn('contracts', 'data_vencimento')) {
                $table->date('data_vencimento')->nullable()->after('data_assinatura');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            foreach (['data_assinatura', 'data_vencimento'] as $col) {
                if (Schema::hasColumn('contracts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
