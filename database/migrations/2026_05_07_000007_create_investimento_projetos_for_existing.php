<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $serviceTypeId  = DB::table('service_types')->where('code', 'projeto')->value('id');
        $contractTypeId = DB::table('contract_types')->where('code', 'on_demand')->value('id');

        if (!$serviceTypeId || !$contractTypeId) {
            return;
        }

        $customers = DB::table('customers')->whereNull('deleted_at')->get();

        foreach ($customers as $c) {
            $codeKey = $c->code_prefix ?: (string) $c->id;
            $code    = "IP-{$codeKey}";

            $alreadyHas = DB::table('projects')
                ->where('customer_id', $c->id)
                ->where('is_investimento_comercial', true)
                ->where('name', 'Investimento Projetos')
                ->exists();
            if ($alreadyHas) continue;

            $codeConflict = DB::table('projects')->where('code', $code)->exists();
            if ($codeConflict) continue;

            DB::table('projects')->insert([
                'name'                      => 'Investimento Projetos',
                'code'                      => $code,
                'customer_id'               => $c->id,
                'service_type_id'           => $serviceTypeId,
                'contract_type_id'          => $contractTypeId,
                'status'                    => 'started',
                'is_investimento_comercial' => true,
                'is_manual_code'            => true,
                'categoria_interna'         => 'Projeto',
                'created_at'                => now(),
                'updated_at'                => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Não-reversível por segurança.
    }
};
