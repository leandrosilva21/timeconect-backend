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
            DB::statement("ALTER TABLE timesheets DROP CONSTRAINT IF EXISTS timesheets_status_check");
            DB::statement("ALTER TABLE timesheets ADD CONSTRAINT timesheets_status_check CHECK (status IN ('pending','approved','rejected','conflicted','adjustment_requested','internal','released'))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE timesheets MODIFY COLUMN status ENUM('pending','approved','rejected','conflicted','adjustment_requested','internal','released') DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        DB::table('timesheets')->whereIn('status', ['internal', 'released'])->update(['status' => 'pending']);
        DB::statement("ALTER TABLE timesheets DROP CONSTRAINT IF EXISTS timesheets_status_check");
        DB::statement("ALTER TABLE timesheets ADD CONSTRAINT timesheets_status_check CHECK (status IN ('pending','approved','rejected','conflicted','adjustment_requested'))");
    }
};
