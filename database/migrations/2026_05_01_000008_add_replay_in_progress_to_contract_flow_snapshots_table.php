<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_flow_snapshots', function (Blueprint $table) {
            $table->boolean('replay_in_progress')->default(false)->after('last_sequence');
        });
    }

    public function down(): void
    {
        Schema::table('contract_flow_snapshots', function (Blueprint $table) {
            $table->dropColumn('replay_in_progress');
        });
    }
};
