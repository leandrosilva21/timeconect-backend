<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espelho de project_message_mentions pra chats que ainda não tinham parser
 * de @-mention: requisição e contrato. Habilita sininho global agregar das 3.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('contract_request_message_mentions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained('contract_request_messages')->cascadeOnDelete();
            $t->foreignId('mentioned_user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['message_id', 'mentioned_user_id'], 'crmm_msg_user_unique');
            $t->index('mentioned_user_id', 'crmm_user_idx');
        });

        Schema::create('contract_message_mentions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('message_id')->constrained('contract_messages')->cascadeOnDelete();
            $t->foreignId('mentioned_user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['message_id', 'mentioned_user_id'], 'cmm_msg_user_unique');
            $t->index('mentioned_user_id', 'cmm_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_message_mentions');
        Schema::dropIfExists('contract_request_message_mentions');
    }
};
