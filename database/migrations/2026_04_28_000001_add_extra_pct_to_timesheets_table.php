<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->decimal('client_extra_pct',     5, 2)->nullable()->after('is_billable_only');
            $table->decimal('consultant_extra_pct', 5, 2)->nullable()->after('client_extra_pct');
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropColumn(['client_extra_pct', 'consultant_extra_pct']);
        });
    }
};
