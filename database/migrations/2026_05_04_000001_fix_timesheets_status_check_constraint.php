<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrations anteriores (conflicted, adjustment_requested, internal, released)
 * detectavam erroneamente o pg_type 'timesheets_status' e adicionavam valores
 * ao ENUM type sem atualizar a CHECK constraint — que é o que o Laravel realmente
 * usa nessa coluna (VARCHAR + CHECK). Este fix corrige a constraint de uma vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;

        DB::statement("ALTER TABLE timesheets DROP CONSTRAINT IF EXISTS timesheets_status_check");
        DB::statement("ALTER TABLE timesheets ADD CONSTRAINT timesheets_status_check CHECK (status IN (
            'pending','approved','rejected','conflicted','adjustment_requested','internal','released'
        ))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;

        DB::statement("ALTER TABLE timesheets DROP CONSTRAINT IF EXISTS timesheets_status_check");
        DB::statement("ALTER TABLE timesheets ADD CONSTRAINT timesheets_status_check CHECK (status IN (
            'pending','approved','rejected'
        ))");
    }
};
