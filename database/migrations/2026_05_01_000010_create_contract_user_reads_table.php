<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_user_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_read_at')->useCurrent();
            $table->primary(['user_id', 'contract_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_user_reads');
    }
};
