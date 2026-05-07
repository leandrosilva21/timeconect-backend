<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')
            ->where('is_investimento_comercial', true)
            ->where('name', 'Investimento Comercial')
            ->whereNull('categoria_interna')
            ->update(['categoria_interna' => 'Comercial']);
    }

    public function down(): void
    {
        DB::table('projects')
            ->where('is_investimento_comercial', true)
            ->where('name', 'Investimento Comercial')
            ->where('categoria_interna', 'Comercial')
            ->update(['categoria_interna' => null]);
    }
};
