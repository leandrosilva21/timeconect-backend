<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_feed', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()
                ->constrained('contracts')->nullOnDelete();
            $table->foreignId('project_id')->nullable()
                ->constrained('projects')->nullOnDelete();

            $table->string('source', 40)
                ->comment('Origem do evento: system, ai, movidesk, manual, health_engine, cs_engine, finance, training');
            $table->string('event_type', 60)
                ->comment('Tipo do evento: hour_overrun, churn_risk, ai_suggestion, etc.');
            $table->string('severity', 20)
                ->comment('Severidade: info, low, medium, high, critical');

            $table->string('title', 180);
            $table->text('message');
            $table->json('metadata')->nullable()
                ->comment('Payload livre: dedupe_key, provider, context, percent, source_id externo, etc.');

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete()
                ->comment('Usuário criador; null = evento de sistema/IA');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'created_at'], 'idx_feed_customer_created');
            $table->index(['contract_id', 'created_at'], 'idx_feed_contract_created');
            $table->index(['project_id', 'created_at'], 'idx_feed_project_created');
            $table->index('severity');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_feed');
    }
};
