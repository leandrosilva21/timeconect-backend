<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de contrato/vínculo trabalhista do usuário: cooperado | clt | pj.
 * Distinto de consultant_type (horista/banco_de_horas/fixo, que é o modelo de pagamento).
 * Para consultores vinculados a um parceiro, é herdado do parceiro (trava).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'contract_type')) {
                $table->string('contract_type')->nullable()->after('consultant_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'contract_type')) {
                $table->dropColumn('contract_type');
            }
        });
    }
};
