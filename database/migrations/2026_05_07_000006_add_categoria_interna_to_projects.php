<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('categoria_interna', 30)->nullable()->after('is_investimento_comercial');
        });

        // Popular categoria_interna nos projetos auto-criados existentes.
        DB::table('projects')
            ->where('is_investimento_comercial', true)
            ->where('name', 'Investimento Suporte')
            ->update(['categoria_interna' => 'Suporte']);

        DB::table('projects')
            ->where('is_investimento_comercial', true)
            ->where('name', 'Investimento Projetos')
            ->update(['categoria_interna' => 'Projeto']);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('categoria_interna');
        });
    }
};
