<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_notification_rules', function (Blueprint $table) {
            // Filtro adicional por tipo de evento (FeedEventType slug). Null = todos.
            $table->string('event_type', 60)->nullable()->after('trigger_event');
            // Filtro adicional por skill que originou o feed. Null = todas.
            $table->string('skill_slug', 80)->nullable()->after('event_type');
            // Descrição livre pra contexto humano.
            $table->text('description')->nullable()->after('name');
            // Prioridade de avaliação (menor roda primeiro).
            $table->integer('priority')->default(100)->after('active');

            $table->index(['active', 'event_type']);
        });

        // Expandir comment de target_type (campo string já permite novos valores):
        //   user | role | group | all_admins | customer_team | all_users
        // Expandir comment de channel:
        //   inbox | bot_dm | group | email | teams (legacy)
    }

    public function down(): void
    {
        Schema::table('bot_notification_rules', function (Blueprint $table) {
            $table->dropIndex(['active', 'event_type']);
            $table->dropColumn(['event_type', 'skill_slug', 'description', 'priority']);
        });
    }
};
