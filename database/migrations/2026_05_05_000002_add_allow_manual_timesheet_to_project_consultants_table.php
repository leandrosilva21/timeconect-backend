<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('project_consultants', 'allow_manual_timesheet')) {
            Schema::table('project_consultants', function (Blueprint $table) {
                $table->boolean('allow_manual_timesheet')->default(false)->after('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('project_consultants', function (Blueprint $table) {
            $table->dropColumn('allow_manual_timesheet');
        });
    }
};
