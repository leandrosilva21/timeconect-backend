<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movidesk_problem_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_id')->unique();
            $table->unsignedInteger('attempts')->default(1);
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('blacklisted_at')->nullable();
            $table->timestamps();

            $table->index('blacklisted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movidesk_problem_tickets');
    }
};
