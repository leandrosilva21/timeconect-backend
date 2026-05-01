<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_sequences', function (Blueprint $table) {
            $table->foreignId('contract_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_sequence')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_sequences');
    }
};
