<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('user_hourly_rate_logs', 'effective_from')) {
            Schema::table('user_hourly_rate_logs', function (Blueprint $table) {
                // Mês de vigência ESCOLHIDO ao alterar o valor hora do consultor. Null = logs
                // legados (caem no comportamento antigo: vale a partir do mês seguinte à troca).
                $table->date('effective_from')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_hourly_rate_logs', 'effective_from')) {
            Schema::table('user_hourly_rate_logs', function (Blueprint $table) {
                $table->dropColumn('effective_from');
            });
        }
    }
};
