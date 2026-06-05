<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas fiscais do fechamento para entidades PJ (consultor PJ ou parceiro PJ):
 * NFS-e e Nota de débito, um par por entidade por mês (year_month), cada documento
 * com seu próprio fluxo de aceite/recusa (admin) + motivo. Polimórfico: `notable`
 * pode ser App\Models\User (consultor) ou App\Models\Partner (parceiro).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fechamento_notas')) {
            return;
        }

        Schema::create('fechamento_notas', function (Blueprint $table) {
            $table->id();
            $table->string('notable_type');
            $table->unsignedBigInteger('notable_id');
            $table->string('year_month', 7); // ex.: 2026-05

            // NFS-e
            $table->string('nfse_path')->nullable();
            $table->string('nfse_original_name')->nullable();
            $table->string('nfse_status')->default('pending'); // pending | accepted | rejected
            $table->text('nfse_reject_reason')->nullable();
            $table->foreignId('nfse_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('nfse_decided_at')->nullable();

            // Nota de débito
            $table->string('nota_debito_path')->nullable();
            $table->string('nota_debito_original_name')->nullable();
            $table->string('nota_debito_status')->default('pending');
            $table->text('nota_debito_reject_reason')->nullable();
            $table->foreignId('nota_debito_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('nota_debito_decided_at')->nullable();

            $table->timestamps();

            $table->unique(['notable_type', 'notable_id', 'year_month'], 'fechamento_notas_owner_month_unique');
            $table->index(['notable_type', 'notable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_notas');
    }
};
