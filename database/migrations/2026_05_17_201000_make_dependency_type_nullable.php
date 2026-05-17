<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Fix Fase 9: dependency_type precisa ser nullable porque, quando soft-delete
 * de delivery limpa FK dos dependentes, precisa zerar ambos (id + type) — caso
 * contrário viola NOT NULL constraint na coluna `dependency_type`.
 *
 * Coerência: se depends_on_delivery_id é nullable, dependency_type também deve ser.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            $table->string('dependency_type', 8)->nullable()->default(null)->change();
        });
    }
    public function down(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            $table->string('dependency_type', 8)->default('FS')->nullable(false)->change();
        });
    }
};
