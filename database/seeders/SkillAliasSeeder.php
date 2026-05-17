<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Idempotente: pode ser re-rodado sem duplicar (unique index em (skill_id, LOWER(alias))).
 * Uso:
 *   php artisan db:seed --class=SkillAliasSeeder
 *
 * Já é chamado também dentro da migration 2026_05_14_010000 — pra dev/CI onde Docker CMD só roda migrate.
 */
class SkillAliasSeeder extends Seeder
{
    public function run(): void
    {
        $mapping = [
            'SIGAFAT' => ['faturamento', 'nota fiscal', 'nf-e', 'emissão de nota', 'faturar'],
            'SIGAFIN' => ['financeiro', 'contas a pagar', 'contas a receber', 'tesouraria', 'fluxo de caixa'],
            'SIGACTB' => ['contábil', 'contabilidade', 'balancete', 'razão contábil'],
            'SIGACOM' => ['compras', 'pedido de compra', 'cotação', 'fornecedor'],
            'SIGAEST' => ['estoque', 'almoxarifado', 'inventário', 'movimentação estoque'],
        ];

        $now = now();
        $inserted = 0;
        foreach ($mapping as $skillName => $aliases) {
            $skillId = DB::table('skills')->where('name', $skillName)->value('id');
            if (!$skillId) {
                $this->command?->warn("Skill {$skillName} não encontrada — pulando aliases.");
                continue;
            }
            foreach ($aliases as $alias) {
                $r = DB::table('skill_aliases')->insertOrIgnore([
                    'skill_id'   => $skillId,
                    'alias'      => $alias,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted += $r;
            }
        }
        $this->command?->info("SkillAliasSeeder: {$inserted} aliases inseridos (existentes ignorados).");
    }
}
