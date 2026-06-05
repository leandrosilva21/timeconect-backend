<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CNPJs secundários de faturamento do cliente (lista). Alguns clientes são
 * faturados em mais de um CNPJ; usado para UNIR os recebimentos do Keruak
 * (rentabilidade por cliente) sob o cliente principal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'secondary_cgcs')) {
                $table->json('secondary_cgcs')->nullable()->after('cgc');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'secondary_cgcs')) {
                $table->dropColumn('secondary_cgcs');
            }
        });
    }
};
