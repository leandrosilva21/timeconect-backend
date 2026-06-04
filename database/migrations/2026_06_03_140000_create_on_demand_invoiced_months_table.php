<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag manual "faturado / NFS-e enviada" por PROJETO On Demand (pai) × mês.
 * A existência da linha = mês faturado. Usado para sinalizar (informativo) na
 * Gestão de Projetos os meses encerrados ainda NÃO faturados de On Demand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('on_demand_invoiced_months')) {
            return;
        }
        Schema::create('on_demand_invoiced_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('year_month', 7); // YYYY-MM
            $table->timestamp('invoiced_at')->nullable();
            $table->foreignId('invoiced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('on_demand_invoiced_months');
    }
};
