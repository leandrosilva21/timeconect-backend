<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_flow_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('version')->default(1)->after('category');
            $table->unsignedBigInteger('inconsistency_count')->default(0)->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('contract_flow_snapshots', function (Blueprint $table) {
            $table->dropColumn(['version', 'inconsistency_count']);
        });
    }
};
