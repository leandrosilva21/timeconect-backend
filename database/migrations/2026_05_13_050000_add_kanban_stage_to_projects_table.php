<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'kanban_stage')) {
                $table->string('kanban_stage', 20)->nullable()->default('backlog')->index()
                    ->after('status')
                    ->comment('backlog | planning | execution | homologation | closed — desacoplado do status técnico');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'kanban_stage')) {
                $table->dropColumn('kanban_stage');
            }
        });
    }
};
