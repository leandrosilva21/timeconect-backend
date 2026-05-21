<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hour_contributions', function (Blueprint $table) {
            if (!Schema::hasColumn('hour_contributions', 'motivo')) {
                // aporte | excedentes | absorvidas — default 'aporte' (legado nasce como aporte)
                $table->string('motivo', 20)->default('aporte')->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hour_contributions', function (Blueprint $table) {
            if (Schema::hasColumn('hour_contributions', 'motivo')) {
                $table->dropColumn('motivo');
            }
        });
    }
};
