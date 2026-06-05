<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'extrato_visivel_cliente')) {
                // true = o cliente vê o Extrato (Situação do Contrato mês a mês) no perfil dele.
                // Default true preserva o comportamento atual; o admin desliga por projeto.
                $table->boolean('extrato_visivel_cliente')->default(true)->after('client_follows_timesheets');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'extrato_visivel_cliente')) {
                $table->dropColumn('extrato_visivel_cliente');
            }
        });
    }
};
