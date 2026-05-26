<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('trigger_event', 120)
                ->comment('classe do event: App\\Events\\OperationalFeedCreated');
            $table->string('severity_min', 20)->default('high');
            $table->string('target_type', 30)
                ->comment('user | role | customer_team | all_admins');
            $table->string('target_value', 120)->nullable()
                ->comment('id do user, slug do role, id do customer, etc.');
            $table->string('channel', 20)->default('inbox')
                ->comment('inbox | teams | email');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'trigger_event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_notification_rules');
    }
};
