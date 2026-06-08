<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('type', 20)->nullable()->after('partner_id');
        });

        // Backfill: deriva type a partir dos roles Spatie existentes.
        // Pula se as tabelas Spatie não existem (instalação nova sem spatie/laravel-permission).
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('roles')) {
            return;
        }

        // Prioridade: admin > coordenador > cliente > parceiro_admin > consultor (default).
        DB::statement("
            UPDATE users u
            SET type = CASE
                WHEN EXISTS (
                    SELECT 1 FROM model_has_roles mhr
                    JOIN roles r ON r.id = mhr.role_id
                    WHERE mhr.model_id = u.id
                      AND mhr.model_type = 'App\\\\Models\\\\User'
                      AND r.name = 'Administrator'
                ) THEN 'admin'
                WHEN EXISTS (
                    SELECT 1 FROM model_has_roles mhr
                    JOIN roles r ON r.id = mhr.role_id
                    WHERE mhr.model_id = u.id
                      AND mhr.model_type = 'App\\\\Models\\\\User'
                      AND r.name = 'Coordenador'
                ) THEN 'coordenador'
                WHEN EXISTS (
                    SELECT 1 FROM model_has_roles mhr
                    JOIN roles r ON r.id = mhr.role_id
                    WHERE mhr.model_id = u.id
                      AND mhr.model_type = 'App\\\\Models\\\\User'
                      AND r.name = 'Cliente'
                ) THEN 'cliente'
                WHEN EXISTS (
                    SELECT 1 FROM model_has_roles mhr
                    JOIN roles r ON r.id = mhr.role_id
                    WHERE mhr.model_id = u.id
                      AND mhr.model_type = 'App\\\\Models\\\\User'
                      AND r.name = 'Parceiro ADM'
                ) THEN 'parceiro_admin'
                ELSE 'consultor'
            END
        ");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
