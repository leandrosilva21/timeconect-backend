<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status de envio do fechamento (consultor | cliente | parceiro).
 *
 * Uma linha por (tipo, entidade, ano-mês) com o ÚLTIMO envio: quando e por quem.
 * Os 3 controllers de fechamento gravam aqui no envio bem-sucedido; "limpar" só
 * apaga esta linha (o histórico/thread do consultor vive em outra tabela e fica intacto).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('fechamento_send_status')) {
            Schema::create('fechamento_send_status', function (Blueprint $table) {
                $table->id();
                $table->string('tipo', 20);              // consultor | cliente | parceiro
                $table->unsignedBigInteger('entity_id'); // user_id | customer_id | partner_id
                $table->string('year_month', 7);         // YYYY-MM
                $table->timestamp('sent_at')->nullable();
                $table->unsignedBigInteger('sent_by')->nullable();
                $table->timestamps();

                $table->unique(['tipo', 'entity_id', 'year_month'], 'fechamento_send_status_unq');
                $table->foreign('sent_by')->references('id')->on('users')->nullOnDelete();
            });
        }

        // Backfill do consultor: último envio "enviado" (outbound) por consultor/mês,
        // a partir do log já existente em fechamento_consultor_emails.
        if (Schema::hasTable('fechamento_consultor_emails')) {
            $rows = DB::table('fechamento_consultor_emails')
                ->where('status', 'enviado')
                ->whereNotNull('sent_at')
                ->where(function ($q) {
                    $q->whereNull('direction')->orWhere('direction', 'outbound');
                })
                ->orderBy('sent_at')
                ->get(['consultant_user_id', 'year_month', 'sender_user_id', 'sent_at']);

            $latest = [];
            foreach ($rows as $r) {
                // ordenado asc por sent_at → o último visto por chave é o mais recente
                $latest[$r->consultant_user_id . '|' . $r->year_month] = $r;
            }

            foreach ($latest as $r) {
                DB::table('fechamento_send_status')->updateOrInsert(
                    ['tipo' => 'consultor', 'entity_id' => $r->consultant_user_id, 'year_month' => $r->year_month],
                    ['sent_at' => $r->sent_at, 'sent_by' => $r->sender_user_id, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_send_status');
    }
};
