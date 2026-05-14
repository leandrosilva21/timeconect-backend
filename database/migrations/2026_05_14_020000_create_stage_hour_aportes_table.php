<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aportes operacionais de horas na etapa.
 *
 * Registro imutável (append-only). Toda mudança em stage.hours_planned via aporte
 * gera linha aqui com justificativa obrigatória. Não há DELETE — extorno futuro
 * seria novo registro com hours negativo.
 *
 * Ver ADR 0004 § Aportes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stage_hour_aportes')) {
            return;
        }

        Schema::create('stage_hour_aportes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('project_stages')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('hours', 8, 2)->comment('positivo = aporte; negativo (futuro) = extorno');
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['stage_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_hour_aportes');
    }
};
