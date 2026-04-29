<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheets', 'is_internal_action')) {
                $table->boolean('is_internal_action')->default(false)->after('is_billable_only');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropColumn('is_internal_action');
        });
    }
};
