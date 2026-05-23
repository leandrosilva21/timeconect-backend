<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail(s) de fechamento por cliente — destinatário(s) do envio do fechamento do cliente
 * (separados por vírgula). Cadastrados manualmente pelo admin; clientes não têm "admin" auto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'fechamento_email')) {
                $table->text('fechamento_email')->nullable()->after('code_prefix');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'fechamento_email')) {
                $table->dropColumn('fechamento_email');
            }
        });
    }
};
