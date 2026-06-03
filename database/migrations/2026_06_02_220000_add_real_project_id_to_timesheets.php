<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Apontamento de INVESTIMENTO: além do projeto (de investimento, onde é contabilizado),
 * registra o "projeto real" — o projeto verdadeiro daquela hora. O consumo continua no
 * projeto de investimento; o real serve de referência e define o coordenador que aprova.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (!Schema::hasColumn('timesheets', 'real_project_id')) {
                $table->foreignId('real_project_id')->nullable()->after('project_id')
                    ->constrained('projects')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (Schema::hasColumn('timesheets', 'real_project_id')) {
                $table->dropConstrainedForeignId('real_project_id');
            }
        });
    }
};
