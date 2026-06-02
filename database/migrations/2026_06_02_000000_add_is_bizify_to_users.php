<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca o funcionário como Bizify. No Fechamento de Consultores, quem for Bizify sai dos
 * cards/resultado da ERPSERV e aparece numa aba própria "Bizify" (mesmos campos/cálculo).
 * Nasce desativado (default false) = funcionário ERPSERV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_bizify')) {
                $table->boolean('is_bizify')->default(false)->after('is_executive');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_bizify')) {
                $table->dropColumn('is_bizify');
            }
        });
    }
};
