<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stage_deliveries')) {
            return;
        }

        Schema::create('stage_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('project_stages')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('hours_planned', 8, 2)->default(0);
            $table->string('priority', 10)->default('medium')->comment('low | medium | high');
            $table->string('status', 20)->default('backlog')->index()
                ->comment('backlog | in_progress | waiting_client | review | done');
            $table->date('due_date')->nullable();
            $table->integer('order_index')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['stage_id', 'status']);
            $table->index(['stage_id', 'order_index']);
            $table->index('responsible_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_deliveries');
    }
};
