<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('timesheets', 'date_locked')) {
            Schema::table('timesheets', function (Blueprint $table) {
                // Data travada manualmente na aprovação de atraso — o reprocesso da
                // integração Movidesk NÃO sobrescreve a data quando true.
                $table->boolean('date_locked')->default(false)->after('date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('timesheets', 'date_locked')) {
            Schema::table('timesheets', function (Blueprint $table) {
                $table->dropColumn('date_locked');
            });
        }
    }
};
