<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índice composto pra query agregada de stage_allocations.
 *
 *   SELECT ... FROM stage_allocations a
 *   LEFT JOIN timesheets t ON t.stage_id = a.stage_id
 *                          AND t.user_id = a.user_id
 *                          AND t.status IN ('approved','released')
 *
 * Vai acontecer toda vez que abrir uma etapa — vale o índice.
 * CONCURRENTLY pra não travar a tabela em produção (ver CLAUDE.md backend).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS timesheets_stage_user_status_idx ON timesheets (stage_id, user_id, status) WHERE stage_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS timesheets_stage_user_status_idx');
    }
};
