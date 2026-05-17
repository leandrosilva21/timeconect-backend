<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->string('alias', 120);
            $table->timestamps();
        });

        // Index funcional em LOWER(alias) pra suportar busca case-insensitive otimizada
        DB::statement('CREATE INDEX idx_skill_alias_alias ON skill_aliases (LOWER(alias))');

        // Unique parcial: evita duplicar (skill_id, lower(alias))
        DB::statement('CREATE UNIQUE INDEX uniq_skill_alias ON skill_aliases (skill_id, LOWER(alias))');

        // Seed inicial idempotente — roda já no deploy (Dockerfile chama migrate, não db:seed)
        $this->seedInitialAliases();
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_aliases');
    }

    /**
     * Seed idempotente: ON CONFLICT DO NOTHING via INSERT direto.
     * Pra cada SIGA*, mapeia o skill_id se existir + insere aliases.
     */
    private function seedInitialAliases(): void
    {
        $mapping = [
            'SIGAFAT' => ['faturamento', 'nota fiscal', 'nf-e', 'emissão de nota', 'faturar'],
            'SIGAFIN' => ['financeiro', 'contas a pagar', 'contas a receber', 'tesouraria', 'fluxo de caixa'],
            'SIGACTB' => ['contábil', 'contabilidade', 'balancete', 'razão contábil'],
            'SIGACOM' => ['compras', 'pedido de compra', 'cotação', 'fornecedor'],
            'SIGAEST' => ['estoque', 'almoxarifado', 'inventário', 'movimentação estoque'],
        ];

        $now = now();
        foreach ($mapping as $skillName => $aliases) {
            $skillId = DB::table('skills')->where('name', $skillName)->value('id');
            if (!$skillId) continue; // skill ainda não cadastrada — pula
            foreach ($aliases as $alias) {
                DB::table('skill_aliases')->insertOrIgnore([
                    'skill_id'   => $skillId,
                    'alias'      => $alias,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
