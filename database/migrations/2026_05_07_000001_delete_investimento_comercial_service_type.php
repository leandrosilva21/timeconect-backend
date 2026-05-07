<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $serviceType = DB::table('service_types')
            ->where('code', 'investimento_comercial')
            ->first();

        if (!$serviceType) {
            return;
        }

        $projectsUsing  = DB::table('projects')->where('service_type_id', $serviceType->id)->count();
        $contractsUsing = DB::table('contracts')->where('service_type_id', $serviceType->id)->count();

        if ($projectsUsing > 0 || $contractsUsing > 0) {
            throw new \RuntimeException(
                "Abortado: service_type 'investimento_comercial' (id={$serviceType->id}) ainda tem " .
                "{$projectsUsing} projeto(s) e {$contractsUsing} contrato(s) vinculados."
            );
        }

        DB::table('service_types')->where('id', $serviceType->id)->delete();
    }

    public function down(): void
    {
        // Não-reversível por segurança. Se precisar restaurar, recriar manualmente:
        // INSERT INTO service_types (name, code, active, created_at, updated_at)
        //   VALUES ('Investimento Comercial', 'investimento_comercial', true, now(), now());
    }
};
