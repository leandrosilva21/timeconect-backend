<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de reajustes de contrato (gravado a cada "Aplicar reajuste").
 * Mantém rastreabilidade: valor anterior/novo, percentual, índice (IGPM/IPCA),
 * período acumulado e usuário que aplicou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_value_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->decimal('valor_anterior', 14, 2);
            $table->decimal('valor_novo', 14, 2);
            $table->decimal('percentual', 8, 4);
            $table->string('indice', 12); // IGPM | IPCA
            $table->date('periodo_inicio')->nullable();
            $table->date('periodo_fim')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['contract_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_value_changes');
    }
};
