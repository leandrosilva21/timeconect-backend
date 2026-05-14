<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Última etapa usada" pelo consultor no projeto.
 * Sugestão de pré-seleção quando o consultor tem N alocações e abre apontamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_consultants', function (Blueprint $table) {
            if (!Schema::hasColumn('project_consultants', 'last_stage_id')) {
                $table->foreignId('last_stage_id')->nullable()->after('user_id')
                    ->constrained('project_stages')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_consultants', function (Blueprint $table) {
            if (Schema::hasColumn('project_consultants', 'last_stage_id')) {
                $table->dropConstrainedForeignId('last_stage_id');
            }
        });
    }
};
