<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de folha de pagamento no usuário (usados na planilha de importação):
 *   cpf, matricula, payroll_status (Contratado|Em Afastamento|...), full_name (Nome Completo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'cpf')) {
                $table->string('cpf', 20)->nullable()->after('email');
            }
            if (!Schema::hasColumn('users', 'matricula')) {
                $table->string('matricula', 30)->nullable()->after('cpf');
            }
            if (!Schema::hasColumn('users', 'payroll_status')) {
                $table->string('payroll_status', 40)->nullable()->after('matricula');
            }
            if (!Schema::hasColumn('users', 'full_name')) {
                $table->string('full_name')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['cpf', 'matricula', 'payroll_status', 'full_name'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
