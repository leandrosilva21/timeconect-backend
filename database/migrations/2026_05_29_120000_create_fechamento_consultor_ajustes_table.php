<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fechamento_consultor_ajustes')) {
            return;
        }

        Schema::create('fechamento_consultor_ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('year_month', 7);
            $table->decimal('desconto', 12, 2)->default(0);
            $table->text('desconto_desc')->nullable();
            $table->decimal('adiantamento', 12, 2)->default(0);
            $table->decimal('adicional', 12, 2)->default(0);
            $table->text('adicional_desc')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->unique(['user_id', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_consultor_ajustes');
    }
};
