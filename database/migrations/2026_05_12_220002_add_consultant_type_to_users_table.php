<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Defensivo: o ambiente DEV/homolog/prod já tinha `consultant_type`
     * adicionado por `2026_04_12_100003_add_consultant_type_to_users_table`.
     * Esta migration é mantida pra ambientes novos onde a outra não rodou
     * (e pra documentar a dependência do módulo de skills).
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'consultant_type')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->string('consultant_type', 20)->nullable()->after('type')
                ->comment('internal | candidate — só relevante para users.type=consultor');
        });
    }

    public function down(): void
    {
        // No-op: a coluna pode ser usada por outras migrations (ex: 2026_04_12_100003).
        // Reverter aqui apagaria dados de outros módulos.
    }
};
