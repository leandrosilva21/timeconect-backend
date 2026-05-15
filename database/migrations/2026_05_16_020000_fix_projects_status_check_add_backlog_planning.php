<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona `backlog` e `planning` ao CHECK constraint de projects.status.
 *
 * Os valores foram adicionados como STATUS_BACKLOG e STATUS_PLANNING no
 * Project model (constantes) e seeded em `project_statuses` (migrations
 * 2026_05_13_060000 e 2026_05_14_000000), mas o CHECK constraint na coluna
 * `projects.status` em DEV1 não foi atualizado — daí o erro 23514 ao criar
 * projeto.
 *
 * `liberado_para_testes` já foi adicionado anteriormente (migration
 * 2026_04_25_000001), incluído aqui pra garantir consistência.
 *
 * Idempotente: DROP IF EXISTS + ADD.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // PostgreSQL: drop e recria o CHECK
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check');

        DB::statement("
            ALTER TABLE projects ADD CONSTRAINT projects_status_check
            CHECK (status IN (
                'awaiting_start',
                'backlog',
                'planning',
                'started',
                'liberado_para_testes',
                'paused',
                'cancelled',
                'finished'
            ))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check');

        // Restaura versão sem backlog/planning
        DB::statement("
            ALTER TABLE projects ADD CONSTRAINT projects_status_check
            CHECK (status IN (
                'awaiting_start',
                'started',
                'liberado_para_testes',
                'paused',
                'cancelled',
                'finished'
            ))
        ");
    }
};
