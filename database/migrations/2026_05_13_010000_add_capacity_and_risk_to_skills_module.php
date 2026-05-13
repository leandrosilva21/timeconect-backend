<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacidade/alocação no users + risk_* em project_consultants pra alimentar
 * o cálculo de disponibilidade e o tracking de alocações de risco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'capacity_hours')) {
                $table->unsignedSmallInteger('capacity_hours')->default(160)->after('daily_hours')
                    ->comment('Capacidade mensal padrão (h) — base pra cálculo de disponibilidade');
            }
            if (!Schema::hasColumn('users', 'allocated_hours')) {
                $table->unsignedSmallInteger('allocated_hours')->default(0)->after('capacity_hours')
                    ->comment('Horas já alocadas (atualizadas externamente; valor de referência)');
            }
        });

        Schema::table('project_consultants', function (Blueprint $table) {
            if (!Schema::hasColumn('project_consultants', 'risk_flag')) {
                $table->boolean('risk_flag')->default(false)->after('allow_manual_timesheet')
                    ->comment('true se alocação foi feita com ressalva (score < 0.9)');
            }
            if (!Schema::hasColumn('project_consultants', 'risk_reason')) {
                $table->text('risk_reason')->nullable()->after('risk_flag');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_consultants', function (Blueprint $table) {
            $table->dropColumn(['risk_flag', 'risk_reason']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['capacity_hours', 'allocated_hours']);
        });
    }
};
