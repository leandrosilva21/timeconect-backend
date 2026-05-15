<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * req_decided_at: momento em que requestPlanDecision foi acionado, marcando
 * o corte do chat para o cliente — mensagens posteriores ficam invisíveis
 * para ele.
 *
 * Backfill: pega o created_at do primeiro ContractRequestKanbanLog cujo
 * to_column é req_inicio_autorizado ou inicio_autorizado. Se não houver
 * log, usa updated_at da própria contract_request como fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_requests', function (Blueprint $t) {
            $t->timestamp('req_decided_at')->nullable()->after('req_decision');
        });

        DB::statement("
            UPDATE contract_requests cr
            SET req_decided_at = COALESCE(
                (
                    SELECT MIN(l.created_at)
                    FROM contract_request_kanban_logs l
                    WHERE l.contract_request_id = cr.id
                      AND l.to_column IN ('req_inicio_autorizado', 'inicio_autorizado')
                ),
                cr.updated_at
            )
            WHERE cr.req_decision IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::table('contract_requests', function (Blueprint $t) {
            $t->dropColumn('req_decided_at');
        });
    }
};
