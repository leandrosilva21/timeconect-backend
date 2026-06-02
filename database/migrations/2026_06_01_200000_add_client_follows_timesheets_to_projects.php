<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banco de Horas Fixo (projeto individual): define se o CLIENTE acompanha os apontamentos.
 * Quando false, na visão do cliente os apontamentos ficam ocultos e o saldo aparece como 0
 * (consumido = contratadas). Default true = comportamento atual (cliente acompanha).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'client_follows_timesheets')) {
                $table->boolean('client_follows_timesheets')->default(true)->after('allow_negative_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'client_follows_timesheets')) {
                $table->dropColumn('client_follows_timesheets');
            }
        });
    }
};
