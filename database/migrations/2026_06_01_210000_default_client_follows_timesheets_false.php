<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Banco de Horas Fixo (projeto individual) sempre nasce desabilitado: o cliente NÃO
 * acompanha apontamentos por padrão. Novos projetos pegam o default da coluna (a criação
 * via contrato->generateProject não seta o campo), então mudamos o default para false.
 * Linhas existentes não são afetadas (backfill já tratou os 30 individuais; os 7 com filhos
 * permanecem true).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('projects', 'client_follows_timesheets')) {
            DB::statement('ALTER TABLE projects ALTER COLUMN client_follows_timesheets SET DEFAULT false');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'client_follows_timesheets')) {
            DB::statement('ALTER TABLE projects ALTER COLUMN client_follows_timesheets SET DEFAULT true');
        }
    }
};
