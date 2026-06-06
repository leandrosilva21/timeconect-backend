<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 do fechamento por e-mail: respostas recebidas (inbound) via Microsoft Graph.
 *
 * Cada resposta lida da caixa do noreply vira uma linha desta MESMA tabela
 * (direction='inbound'), encadeada à thread pelo in_reply_to. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_consultor_emails', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_consultor_emails', 'direction')) {
                // 'outbound' = enviado pelo Time Conect; 'inbound' = resposta do consultor lida via Graph.
                $table->string('direction', 16)->default('outbound')->after('year_month');
            }
            if (!Schema::hasColumn('fechamento_consultor_emails', 'from_email')) {
                // Remetente da resposta (inbound). Null nos outbound.
                $table->string('from_email')->nullable()->after('cc_email');
            }
            if (!Schema::hasColumn('fechamento_consultor_emails', 'received_at')) {
                // Quando a resposta foi recebida (inbound). Null nos outbound.
                $table->timestamp('received_at')->nullable()->after('sent_at');
            }
            if (!Schema::hasColumn('fechamento_consultor_emails', 'graph_message_id')) {
                // ID da mensagem na Graph API — chave de dedup do polling.
                $table->string('graph_message_id')->nullable()->after('message_id');
            }
        });

        // Índice único parcial p/ dedup do polling (só inbound tem graph_message_id).
        if (Schema::hasColumn('fechamento_consultor_emails', 'graph_message_id')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'pgsql') {
                \DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS fce_graph_message_id_unique ON fechamento_consultor_emails (graph_message_id) WHERE graph_message_id IS NOT NULL');
            } else {
                Schema::table('fechamento_consultor_emails', function (Blueprint $table) {
                    if (!$this->indexExists('fechamento_consultor_emails', 'fce_graph_message_id_unique')) {
                        $table->unique('graph_message_id', 'fce_graph_message_id_unique');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        \DB::statement('DROP INDEX IF EXISTS fce_graph_message_id_unique');
        Schema::table('fechamento_consultor_emails', function (Blueprint $table) {
            foreach (['direction', 'from_email', 'received_at', 'graph_message_id'] as $col) {
                if (Schema::hasColumn('fechamento_consultor_emails', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return collect(Schema::getConnection()->getDoctrineSchemaManager()->listTableIndexes($table))
                ->keys()->contains($index);
        } catch (\Throwable $e) {
            return false;
        }
    }
};
