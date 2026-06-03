<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de contrato do parceiro (cooperado | clt | pj). Fonte de verdade do vínculo:
 * propaga para TODOS os usuários do parceiro (users.contract_type) — "trava em todos".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (!Schema::hasColumn('partners', 'contract_type')) {
                $table->string('contract_type')->nullable()->after('pricing_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (Schema::hasColumn('partners', 'contract_type')) {
                $table->dropColumn('contract_type');
            }
        });
    }
};
