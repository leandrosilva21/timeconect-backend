<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('active_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->string('default_model', 80)->nullable();
            $table->decimal('temperature', 3, 2)->default(0.30);
            $table->string('frequency_cron', 40)->default('0 6 * * 1')
                ->comment('cron de execução do scan principal');
            $table->time('active_hours_start')->nullable();
            $table->time('active_hours_end')->nullable();
            $table->string('default_severity_threshold', 20)->default('high')
                ->comment('severidade mínima que vira notificação no inbox');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_configs');
    }
};
