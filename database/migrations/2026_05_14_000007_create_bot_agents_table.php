<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_agents', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            $table->string('role_description', 200)->nullable();
            $table->text('system_prompt');
            $table->string('model_override', 80)->nullable();
            $table->decimal('temperature_override', 3, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->integer('priority')->default(10)
                ->comment('menor = roda primeiro');
            $table->string('min_severity', 20)->default('low')
                ->comment('só roda se severity >= esta');
            $table->json('trigger_conditions')->nullable()
                ->comment('quando rodar: {classification:[RISCO,ATENCAO], tickets_gt:0, ...}');
            $table->timestamps();

            $table->index(['active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_agents');
    }
};
