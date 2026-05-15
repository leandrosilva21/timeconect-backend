<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'movidesk_integration_enabled')) {
                $table->boolean('movidesk_integration_enabled')->default(false)->after('manual_project_edit');
            }
        });

        // Backfill: pra cada cliente que TEM exatamente um projeto de sustentação,
        // ativa o flag nele. Preserva comportamento atual do extractProjectId
        // (que pegava o primeiro sustentação encontrado). Clientes com múltiplos
        // sustentação ficam todos false — o admin escolhe qual ativar via UI.
        DB::statement(<<<'SQL'
            UPDATE projects
               SET movidesk_integration_enabled = true
             WHERE id IN (
                SELECT p.id
                  FROM projects p
                  JOIN service_types st ON st.id = p.service_type_id
                 WHERE (st.code = 'sustentacao' OR st.name ILIKE '%sustenta%')
                   AND p.deleted_at IS NULL
                   AND p.customer_id IN (
                       SELECT customer_id
                         FROM projects p2
                         JOIN service_types st2 ON st2.id = p2.service_type_id
                        WHERE (st2.code = 'sustentacao' OR st2.name ILIKE '%sustenta%')
                          AND p2.deleted_at IS NULL
                     GROUP BY customer_id
                       HAVING COUNT(*) = 1
                   )
             )
        SQL);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'movidesk_integration_enabled')) {
                $table->dropColumn('movidesk_integration_enabled');
            }
        });
    }
};
