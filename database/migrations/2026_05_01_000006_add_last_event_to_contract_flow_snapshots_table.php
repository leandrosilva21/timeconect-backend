<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_flow_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('last_event_id')->nullable()->after('inconsistency_count');
            $table->unsignedBigInteger('last_sequence')->default(0)->after('last_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('contract_flow_snapshots', function (Blueprint $table) {
            $table->dropColumn(['last_event_id', 'last_sequence']);
        });
    }
};
