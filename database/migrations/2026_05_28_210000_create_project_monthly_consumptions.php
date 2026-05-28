<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumo mensal manual do banco de horas de projetos BH Mensal.
 *
 * Para meses anteriores ao corte (< 2026-05) o consumo é manual/editável e
 * persistido aqui; de 2026-05 em diante o consumo vem dos apontamentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_monthly_consumptions')) {
            Schema::create('project_monthly_consumptions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('project_id')->index();
                $table->string('year_month', 7); // 'YYYY-MM'
                $table->integer('consumed_minutes')->default(0);
                $table->timestamps();

                $table->unique(['project_id', 'year_month']);
                $table->foreign('project_id')
                    ->references('id')->on('projects')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_monthly_consumptions');
    }
};
