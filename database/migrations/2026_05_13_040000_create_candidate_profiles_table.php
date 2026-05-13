<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('new')->index()
                ->comment('new | screening | interview | approved | rejected | hired | allocated');
            $table->decimal('score_initial', 4, 3)->nullable();
            $table->string('interest_level', 10)->nullable()->comment('alto | medio | baixo');
            $table->decimal('expected_rate', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Backfill: cria perfis pros candidatos já existentes
        DB::statement("
            INSERT INTO candidate_profiles (user_id, status, created_at, updated_at)
            SELECT id, 'new', now(), now()
            FROM users
            WHERE consultant_type = 'candidate'
            ON CONFLICT (user_id) DO NOTHING
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_profiles');
    }
};
