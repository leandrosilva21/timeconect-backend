<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empresa (erpserv|bizify) — eixo independente do consultor (User.is_bizify),
 * combinado com o contract_type. Parceiro/cliente são sempre 'erpserv'.
 * "1 ativo por (categoria, contract_type, empresa)".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_email_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_email_templates', 'empresa')) {
                $table->string('empresa', 20)->default('erpserv')->after('contract_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_email_templates', function (Blueprint $table) {
            if (Schema::hasColumn('fechamento_email_templates', 'empresa')) {
                $table->dropColumn('empresa');
            }
        });
    }
};
