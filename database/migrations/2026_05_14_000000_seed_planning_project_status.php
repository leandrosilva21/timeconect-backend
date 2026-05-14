<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adiciona o status `planning` em project_statuses.
 *
 * Significado operacional: projeto com coordenador alocado, em fase de
 * planejamento de escopo (etapas, equipe, horas previstas). Entre `backlog`
 * (autorizado, ainda não planejado) e `started` (executando de fato).
 *
 * Ver ADR 0002 — lifecycle único.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('project_statuses')->updateOrInsert(
            ['code' => 'planning'],
            [
                'code'        => 'planning',
                'name'        => 'Planejamento',
                'description' => 'Coordenador alocado, definindo escopo/etapas/equipe',
                'is_active'   => true,
                'sort_order'  => 17, // entre backlog (15) e started (20)
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('project_statuses')->where('code', 'planning')->delete();
    }
};
