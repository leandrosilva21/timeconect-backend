<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('critical_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->foreignId('min_level_id')->constrained('skill_levels');
            $table->string('context', 120)->nullable()
                ->comment('Contexto opcional: ex. "Faturamento", "Implementação Protheus"');
            $table->timestamps();

            $table->unique(['skill_id', 'context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('critical_skills');
    }
};
