<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // A coluna `status` é VARCHAR + CHECK constraint (o type enum nativo
        // `timesheets_status` existe mas está órfão). A migração anterior detectou o
        // enum e tomou o branch ALTER TYPE, deixando o CHECK sem 'late'. Corrige aqui —
        // idempotente, roda em qualquer ambiente.
        DB::statement("ALTER TABLE timesheets DROP CONSTRAINT IF EXISTS timesheets_status_check");
        DB::statement("ALTER TABLE timesheets ADD CONSTRAINT timesheets_status_check CHECK (status IN ('pending','approved','rejected','conflicted','adjustment_requested','internal','released','late'))");
    }

    public function down(): void
    {
        // Mantém o constraint sem 'late' apenas se não houver linhas late.
    }
};
