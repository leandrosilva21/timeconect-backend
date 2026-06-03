<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linha cancelada/oculta da folha (vai para a aba "Canceladas"). Usado quando o
 * cooperado é desativado/excluído ou o admin opta por cancelar a linha do mês.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_folha', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_folha', 'cancelado')) {
                $table->boolean('cancelado')->default(false)->after('horista_mensalista');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_folha', function (Blueprint $table) {
            if (Schema::hasColumn('fechamento_folha', 'cancelado')) {
                $table->dropColumn('cancelado');
            }
        });
    }
};
