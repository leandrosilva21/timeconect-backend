<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_events', function (Blueprint $table) {
            $table->unsignedBigInteger('sequence_number')->default(0)->after('contract_id');
            $table->index(['contract_id', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::table('contract_events', function (Blueprint $table) {
            $table->dropIndex(['contract_id', 'sequence_number']);
            $table->dropColumn('sequence_number');
        });
    }
};
