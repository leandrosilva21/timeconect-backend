<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('service_types')->where('code', 'bizify')->exists();
        if ($exists) {
            return;
        }

        DB::table('service_types')->insert([
            'name'       => 'Bizify',
            'code'       => 'bizify',
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $serviceType = DB::table('service_types')->where('code', 'bizify')->first();
        if (!$serviceType) {
            return;
        }

        $projectsUsing  = DB::table('projects')->where('service_type_id', $serviceType->id)->count();
        $contractsUsing = DB::table('contracts')->where('service_type_id', $serviceType->id)->count();

        if ($projectsUsing > 0 || $contractsUsing > 0) {
            throw new \RuntimeException(
                "Abortado: service_type 'bizify' (id={$serviceType->id}) ainda tem " .
                "{$projectsUsing} projeto(s) e {$contractsUsing} contrato(s) vinculados."
            );
        }

        DB::table('service_types')->where('id', $serviceType->id)->delete();
    }
};
