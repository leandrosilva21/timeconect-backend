<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheets', 'stage_id')) {
                $table->foreignId('stage_id')->nullable()->after('project_id')
                    ->constrained('project_stages')->nullOnDelete();
            }
            if (!Schema::hasColumn('timesheets', 'stage_delivery_id')) {
                $table->foreignId('stage_delivery_id')->nullable()->after('stage_id')
                    ->constrained('stage_deliveries')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (Schema::hasColumn('timesheets', 'stage_delivery_id')) {
                $table->dropConstrainedForeignId('stage_delivery_id');
            }
            if (Schema::hasColumn('timesheets', 'stage_id')) {
                $table->dropConstrainedForeignId('stage_id');
            }
        });
    }
};
