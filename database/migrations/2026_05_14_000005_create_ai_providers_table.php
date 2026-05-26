<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique()->comment('anthropic | openai | gemini | ...');
            $table->string('name', 80);
            $table->string('api_key_env', 80)
                ->comment('NOME da env var que guarda a key (ex.: ANTHROPIC_API_KEY). A key NUNCA é salva no DB.');
            $table->string('base_url', 200)->nullable();
            $table->string('default_model', 80);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
