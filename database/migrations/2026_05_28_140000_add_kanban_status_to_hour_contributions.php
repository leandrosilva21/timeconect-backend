<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aporte v2 — coluna kanban do aporte (Novo Contrato → Aporte).
 *
 * Default 'novo_contrato' para que aportes nasçam na coluna "Novo Contrato"
 * do Kanban Contratos (governança comercial: revisar/aprovar antes de mover
 * pra coluna final "Aporte"). Legados (anteriores ao kanban-aporte) ganham
 * 'aporte' direto para aparecerem no estado final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hour_contributions', function (Blueprint $table) {
            if (!Schema::hasColumn('hour_contributions', 'kanban_status')) {
                $table->string('kanban_status', 20)->default('novo_contrato')->after('proposta_original_name');
            }
        });

        // Aportes legados que existiam ANTES do kanban-aporte ficam direto em 'aporte'
        // (foram criados via modal de gestão, já confirmados). A regra: tudo que tem
        // contributed_at > NOW() é "novo"; o resto vai pra coluna final.
        \DB::statement("UPDATE hour_contributions SET kanban_status = 'aporte' WHERE kanban_status = 'novo_contrato' AND created_at < '2026-05-28'");
    }

    public function down(): void
    {
        Schema::table('hour_contributions', function (Blueprint $table) {
            if (Schema::hasColumn('hour_contributions', 'kanban_status')) {
                $table->dropColumn('kanban_status');
            }
        });
    }
};
