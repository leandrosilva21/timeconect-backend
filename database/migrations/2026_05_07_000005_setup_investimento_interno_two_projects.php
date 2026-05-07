<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Reverter rename: Investimento Interno -> Investimento Comercial
        //    nos projetos auto-criados (is_investimento_comercial=true).
        DB::table('projects')
            ->where('is_investimento_comercial', true)
            ->where('name', 'Investimento Interno')
            ->update(['name' => 'Investimento Comercial']);

        // 2. Migrar code: IC-{id} -> IC-{prefix} para projetos auto principais.
        //    Cuidado: IC-{id}-N (manuais ERPSERV) recebem migração separada abaixo.
        $projects = DB::table('projects')
            ->select('projects.id', 'projects.code', 'projects.customer_id', 'customers.code_prefix')
            ->join('customers', 'customers.id', '=', 'projects.customer_id')
            ->where('projects.is_investimento_comercial', true)
            ->whereNull('projects.deleted_at')
            ->get();

        foreach ($projects as $p) {
            if (!$p->code_prefix) {
                continue; // Sem prefix → mantém IC-{id} (fallback)
            }

            // Caso "IC-{id}" exato → "IC-{prefix}"
            if ($p->code === "IC-{$p->customer_id}") {
                $newCode = "IC-{$p->code_prefix}";
                if (!DB::table('projects')->where('code', $newCode)->where('id', '!=', $p->id)->exists()) {
                    DB::table('projects')->where('id', $p->id)->update(['code' => $newCode]);
                }
                continue;
            }

            // Caso "IC-{id}-N" (manuais ERPSERV) → "IC-{prefix}-N"
            if (preg_match('/^IC-' . $p->customer_id . '-(\d+)$/', $p->code, $m)) {
                $newCode = "IC-{$p->code_prefix}-{$m[1]}";
                if (!DB::table('projects')->where('code', $newCode)->where('id', '!=', $p->id)->exists()) {
                    DB::table('projects')->where('id', $p->id)->update(['code' => $newCode]);
                }
            }
        }

        // 3. Criar Investimento Suporte para todos os clientes que ainda não têm.
        $serviceTypeId  = DB::table('service_types')->where('code', 'projeto')->value('id');
        $contractTypeId = DB::table('contract_types')->where('code', 'on_demand')->value('id');

        if (!$serviceTypeId || !$contractTypeId) {
            return;
        }

        $customers = DB::table('customers')->whereNull('deleted_at')->get();

        foreach ($customers as $c) {
            $codeKey  = $c->code_prefix ?: (string) $c->id;
            $codeIS   = "IS-{$codeKey}";

            $alreadyHas = DB::table('projects')
                ->where('customer_id', $c->id)
                ->where('is_investimento_comercial', true)
                ->where('name', 'Investimento Suporte')
                ->exists();
            if ($alreadyHas) continue;

            $codeConflict = DB::table('projects')->where('code', $codeIS)->exists();
            if ($codeConflict) continue;

            DB::table('projects')->insert([
                'name'                      => 'Investimento Suporte',
                'code'                      => $codeIS,
                'customer_id'               => $c->id,
                'service_type_id'           => $serviceTypeId,
                'contract_type_id'          => $contractTypeId,
                'status'                    => 'started',
                'is_investimento_comercial' => true,
                'is_manual_code'            => true,
                'created_at'                => now(),
                'updated_at'                => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Não-reversível por segurança (envolve UPDATE/INSERT em massa).
        // Para reverter manualmente: deletar projetos com name='Investimento Suporte' e
        // is_investimento_comercial=true criados por esta migration; reverter codes IC-{prefix}
        // para IC-{id}; renomear Investimento Comercial -> Investimento Interno.
    }
};
