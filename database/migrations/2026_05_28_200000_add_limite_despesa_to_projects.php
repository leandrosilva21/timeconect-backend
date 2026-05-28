<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propaga `limite_despesa` do contrato pro projeto.
 * Já existia em `contracts` mas não em `projects` — visualização do projeto
 * não conseguia exibir o limite definido no contrato original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'limite_despesa')) {
                $table->decimal('limite_despesa', 15, 2)->nullable()->after('cobra_despesa_cliente');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'limite_despesa')) {
                $table->dropColumn('limite_despesa');
            }
        });
    }
};
