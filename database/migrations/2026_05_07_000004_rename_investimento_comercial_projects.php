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
            ->update(['name' => 'Investimento Interno']);
    }

    public function down(): void
    {
        DB::table('projects')
            ->where('is_investimento_comercial', true)
            ->where('name', 'Investimento Interno')
            ->update(['name' => 'Investimento Comercial']);
    }
};
