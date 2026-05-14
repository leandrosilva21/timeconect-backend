<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueio contextual da etapa (Bloco B do plano).
 *
 * `blocked_reason` é texto livre opcional preenchido pelo coordenador
 * quando a etapa fica em estado bloqueada. Aparece no card central do
 * kanban e no drilldown. Editado via PATCH /stages/{id} normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_stages', function (Blueprint $table) {
            if (!Schema::hasColumn('project_stages', 'blocked_reason')) {
                $table->text('blocked_reason')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_stages', function (Blueprint $table) {
            if (Schema::hasColumn('project_stages', 'blocked_reason')) {
                $table->dropColumn('blocked_reason');
            }
        });
    }
};
