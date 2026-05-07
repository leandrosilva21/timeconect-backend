<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('contract_types')->where('code', 'cloud')->exists();
        if ($exists) {
            return;
        }

        DB::table('contract_types')->insert([
            'name'       => 'Cloud',
            'code'       => 'cloud',
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $contractType = DB::table('contract_types')->where('code', 'cloud')->first();
        if (!$contractType) {
            return;
        }

        $projectsUsing  = DB::table('projects')->where('contract_type_id', $contractType->id)->count();
        $contractsUsing = DB::table('contracts')->where('contract_type_id', $contractType->id)->count();

        if ($projectsUsing > 0 || $contractsUsing > 0) {
            throw new \RuntimeException(
                "Abortado: contract_type 'cloud' (id={$contractType->id}) ainda tem " .
                "{$projectsUsing} projeto(s) e {$contractsUsing} contrato(s) vinculados."
            );
        }

        DB::table('contract_types')->where('id', $contractType->id)->delete();
    }
};
