<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('timesheet_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timesheet_id')->constrained('timesheets')->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            // 'manual' (UI), 'movidesk_sync' (scheduler), 'system' (outros consoles)
            $table->string('source', 30);
            // 'updated' | 'deleted' | 'restored'
            $table->string('action', 20);
            // {field: {old, new}}
            $table->jsonb('changes');
            $table->timestamp('created_at')->useCurrent();

            $table->index('timesheet_id');
            $table->index('changed_by');
            $table->index('created_at');
            $table->index(['source', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_logs');
    }
};
