<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('partner_hourly_rate_logs')) {
            Schema::create('partner_hourly_rate_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('old_hourly_rate', 10, 2)->nullable();
                $table->decimal('new_hourly_rate', 10, 2)->nullable();
                // Mês de vigência ESCOLHIDO ao alterar o valor hora do parceiro.
                $table->date('effective_from')->nullable();
                $table->text('reason')->nullable();
                $table->timestamps();
                $table->index('partner_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_hourly_rate_logs');
    }
};
