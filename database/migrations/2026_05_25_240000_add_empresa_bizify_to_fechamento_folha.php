<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separa a folha por EMPRESA (aba ERPSERV x Bizify). Linhas existentes => 'erpserv'.
 * Bizify (lançamentos manuais, importados de planilha) usa as colunas extras
 * aj_custo (ajuda de custo, crédito) e adto (adiantamento de crédito).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_folha', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_folha', 'empresa')) {
                $table->string('empresa', 20)->default('erpserv')->index();
            }
            if (!Schema::hasColumn('fechamento_folha', 'aj_custo')) {
                $table->decimal('aj_custo', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('fechamento_folha', 'adto')) {
                $table->decimal('adto', 12, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_folha', function (Blueprint $table) {
            $table->dropColumn(['empresa', 'aj_custo', 'adto']);
        });
    }
};
