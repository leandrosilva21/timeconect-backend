<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_stages')) {
            return;
        }

        Schema::create('project_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 100);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('hours_planned', 8, 2)->default(0);
            $table->string('status', 20)->default('active')->index()
                ->comment('active | paused | done');
            $table->integer('order_index')->default(0);
            $table->date('expected_end_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'order_index']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_stages');
    }
};
