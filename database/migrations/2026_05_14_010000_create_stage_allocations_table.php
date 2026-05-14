<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alocação operacional de consultoria por etapa.
 * Apenas planned_hours é persistido — actual_hours e remaining_hours são derivados.
 * Ver ADR 0003.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stage_allocations')) {
            return;
        }

        Schema::create('stage_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('project_stages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('planned_hours', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['stage_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_allocations');
    }
};
