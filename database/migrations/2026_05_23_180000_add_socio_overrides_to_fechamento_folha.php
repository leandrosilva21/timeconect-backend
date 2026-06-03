<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suporte a linhas-sócio (manuais, totalmente editáveis) na folha:
 *  - user_id passa a ser opcional (sócio pode não ter usuário);
 *  - socio_key identifica a linha-sócio fixa;
 *  - overrides dos campos que normalmente são automáticos (cpf/nome/status/valor_hora/producao).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE fechamento_folha ALTER COLUMN user_id DROP NOT NULL');

        Schema::table('fechamento_folha', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_folha', 'socio_key')) {
                $table->string('socio_key', 60)->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('fechamento_folha', 'cpf')) {
                $table->string('cpf', 20)->nullable()->after('socio_key');
            }
            if (!Schema::hasColumn('fechamento_folha', 'matricula')) {
                $table->string('matricula', 30)->nullable()->after('cpf');
            }
            if (!Schema::hasColumn('fechamento_folha', 'nome')) {
                $table->string('nome')->nullable()->after('matricula');
            }
            if (!Schema::hasColumn('fechamento_folha', 'status')) {
                $table->string('status', 40)->nullable()->after('nome');
            }
            if (!Schema::hasColumn('fechamento_folha', 'valor_hora')) {
                $table->decimal('valor_hora', 10, 4)->nullable()->after('horas_trabalhadas');
            }
            if (!Schema::hasColumn('fechamento_folha', 'producao')) {
                $table->decimal('producao', 12, 2)->nullable()->after('valor_hora');
            }
            $table->unique(['socio_key', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_folha', function (Blueprint $table) {
            $table->dropUnique(['socio_key', 'year_month']);
            foreach (['socio_key', 'cpf', 'matricula', 'nome', 'status', 'valor_hora', 'producao'] as $col) {
                if (Schema::hasColumn('fechamento_folha', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
