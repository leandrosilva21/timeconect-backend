<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheets', 'manual_project_edit')) {
                $table->boolean('manual_project_edit')
                    ->default(false)
                    ->after('origin')
                    ->comment('Se true, projeto/cliente foram editados manualmente e não devem ser atualizados pelo reprocess do Movidesk');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropColumn('manual_project_edit');
        });
    }
};
