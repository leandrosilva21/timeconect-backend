<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log de envios do fechamento de consultor por e-mail.
 *
 * Grava um registro por TENTATIVA (status=enviado|falhou), com os paths dos
 * anexos gerados (PDF + XLSX) e a resposta do provedor em caso de erro.
 * Permite auditoria de "quem enviou o quê pra quem e quando".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fechamento_consultor_emails')) {
            return;
        }

        Schema::create('fechamento_consultor_emails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender_user_id')->nullable();
            $table->unsignedBigInteger('consultant_user_id')->nullable();
            $table->string('year_month', 7); // ex.: 2026-05
            $table->string('to_email');
            $table->string('cc_email')->nullable();
            $table->string('subject');
            $table->decimal('total_value', 12, 2)->default(0);
            $table->string('pdf_path')->nullable();
            $table->string('xlsx_path')->nullable();
            $table->string('status', 20); // enviado | falhou
            $table->text('provider_response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('sender_user_id');
            $table->index('consultant_user_id');
            $table->index('year_month');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_consultor_emails');
    }
};
