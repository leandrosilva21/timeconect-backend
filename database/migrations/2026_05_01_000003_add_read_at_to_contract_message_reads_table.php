<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_message_reads', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('contract_message_reads', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
};
