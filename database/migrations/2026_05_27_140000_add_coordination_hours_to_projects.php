<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Banco de horas de coordenação: cota fixa, opt-in, consumida só pelos
     * apontamentos do coordenador. Independente de sold_hours e do percentual
     * coordinator_hours (que segue intacto). Null = projeto não usa a regra.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'coordination_hours')) {
                $table->decimal('coordination_hours', 10, 2)->nullable()->after('coordinator_percentage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'coordination_hours')) {
                $table->dropColumn('coordination_hours');
            }
        });
    }
};
