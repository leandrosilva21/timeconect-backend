<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultant_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->foreignId('level_id')->constrained('skill_levels');
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->date('last_used_at')->nullable();
            $table->string('source', 20)->default('user_input')
                ->comment('forms_import | user_input | validated');
            $table->string('confidence', 10)->default('medium')
                ->comment('low | medium | high');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['consultant_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_skills');
    }
};
