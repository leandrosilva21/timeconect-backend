<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Despesa de INVESTIMENTO: além do projeto (de investimento, onde é contabilizada),
 * registra o "projeto real". Mesma regra do apontamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('expenses', 'real_project_id')) {
                $table->foreignId('real_project_id')->nullable()->after('project_id')
                    ->constrained('projects')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'real_project_id')) {
                $table->dropConstrainedForeignId('real_project_id');
            }
        });
    }
};
