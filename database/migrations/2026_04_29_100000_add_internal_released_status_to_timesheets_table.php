<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Verifica se é ENUM nativo ou VARCHAR+CHECK
            $isEnum = DB::selectOne("
                SELECT 1 FROM pg_type t
                JOIN pg_enum e ON e.enumtypid = t.oid
                WHERE t.typname = 'timesheets_status'
                LIMIT 1
            ");

            if ($isEnum) {
                // ENUM nativo: adiciona valores ao type
                DB::statement("ALTER TYPE timesheets_status ADD VALUE IF NOT EXISTS 'internal'");
                DB::statement("ALTER TYPE timesheets_status ADD VALUE IF NOT EXISTS 'released'");
            } else {
                // VARCHAR + CHECK constraint
                DB::statement("ALTER TABLE timesheets DROP CONSTRAINT IF EXISTS timesheets_status_check");
                DB::statement("ALTER TABLE timesheets ADD CONSTRAINT timesheets_status_check CHECK (status IN ('pending','approved','rejected','conflicted','adjustment_requested','internal','released'))");
            }
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE timesheets MODIFY COLUMN status ENUM('pending','approved','rejected','conflicted','adjustment_requested','internal','released') DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        // Não é possível remover valores de ENUM nativo no PostgreSQL sem recriar o tipo
        DB::table('timesheets')->whereIn('status', ['internal', 'released'])->update(['status' => 'pending']);
    }
};
