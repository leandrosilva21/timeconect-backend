<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estende fechamento_consultor_emails para virar uma THREAD de mensagens
 * (Fase 1 = conversa só de saída / outbound).
 *
 * Uma "thread" = todas as linhas com o mesmo (consultant_user_id, year_month).
 * - message_id     : Message-ID que setamos no e-mail (próprio desta mensagem)
 * - in_reply_to    : Message-ID da mensagem-pai (a 1ª mensagem da thread)
 * - body           : texto livre do corpo (usado nas continuações)
 * - is_continuation : true quando é uma resposta/continuação (não o envio original)
 *
 * Idempotente: só adiciona colunas que ainda não existem.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fechamento_consultor_emails')) {
            return;
        }

        Schema::table('fechamento_consultor_emails', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_consultor_emails', 'message_id')) {
                $table->string('message_id')->nullable()->after('subject');
            }
            if (!Schema::hasColumn('fechamento_consultor_emails', 'in_reply_to')) {
                $table->string('in_reply_to')->nullable()->after('message_id');
            }
            if (!Schema::hasColumn('fechamento_consultor_emails', 'body')) {
                $table->text('body')->nullable()->after('in_reply_to');
            }
            if (!Schema::hasColumn('fechamento_consultor_emails', 'is_continuation')) {
                $table->boolean('is_continuation')->default(false)->after('body');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_consultor_emails', function (Blueprint $table) {
            foreach (['is_continuation', 'body', 'in_reply_to', 'message_id'] as $col) {
                if (Schema::hasColumn('fechamento_consultor_emails', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
