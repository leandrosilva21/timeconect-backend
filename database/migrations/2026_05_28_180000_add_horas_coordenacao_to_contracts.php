<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banco de Horas de Coordenação no Contrato.
 *
 * Permite preencher a "cota" do coordenador já na criação do contrato
 * (Kanban Contratos). Quando o contrato vira projeto, o valor é copiado
 * para `projects.coordination_hours`. Opcional aqui — o projeto pode
 * preencher depois (lá é obrigatório quando não é On Demand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'horas_coordenacao')) {
                $table->decimal('horas_coordenacao', 10, 2)->nullable()->after('pct_horas_coordenador');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'horas_coordenacao')) {
                $table->dropColumn('horas_coordenacao');
            }
        });
    }
};
