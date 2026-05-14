<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona o status `backlog` em project_statuses.
 *
 * Faz parte da Fase 5 do roadmap de gestão operacional. Quando um contrato é
 * arrastado para coordenador no kanban de contratos, o projeto criado/atualizado
 * passa a entrar em `backlog` (em vez de `awaiting_start` ou `started`).
 *
 * `backlog` significa: coordenador alocado, projeto autorizado, mas execução
 * operacional ainda não começou.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('project_statuses')->updateOrInsert(
            ['code' => 'backlog'],
            [
                'code'        => 'backlog',
                'name'        => 'Backlog',
                'description' => 'Coordenador alocado, execução ainda não iniciada',
                'is_active'   => true,
                'sort_order'  => 15, // entre awaiting_start (10) e started (20)
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('project_statuses')->where('code', 'backlog')->delete();
    }
};
