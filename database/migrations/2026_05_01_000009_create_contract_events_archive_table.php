<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_events_archive', function (Blueprint $table) {
            // Sem FK constraints — arquivo histórico sobrevive à deleção de contratos
            $table->unsignedBigInteger('id')->unique();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('sequence_number')->default(0);
            $table->string('event_type');
            $table->string('field')->nullable();
            $table->text('from_value')->nullable();
            $table->text('to_value')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['contract_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_events_archive');
    }
};
