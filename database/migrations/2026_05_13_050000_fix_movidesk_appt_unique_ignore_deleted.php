<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sync do Movidesk soft-deletava timesheets (apontamento removido na API)
 * mas o índice unique antigo não filtrava por deleted_at — o slot do
 * movidesk_appointment_id ficava "preso". Próximas execuções do sync
 * falhavam com 23505 (duplicate key) ao tentar criar/atualizar com o mesmo
 * appt_id e o updateLastSync nunca era chamado, dando aparência de que a
 * integração "parou de rodar".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DROP INDEX IF EXISTS timesheets_movidesk_appt_id_unique");
        DB::statement(
            "CREATE UNIQUE INDEX timesheets_movidesk_appt_id_unique
             ON timesheets (movidesk_appointment_id)
             WHERE movidesk_appointment_id IS NOT NULL AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS timesheets_movidesk_appt_id_unique");
        DB::statement(
            "CREATE UNIQUE INDEX timesheets_movidesk_appt_id_unique
             ON timesheets (movidesk_appointment_id)
             WHERE movidesk_appointment_id IS NOT NULL"
        );
    }
};
