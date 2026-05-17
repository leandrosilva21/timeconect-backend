<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendário operacional flexível por projeto (Fase 7).
 *
 * Cenários reais (go-live, virada fiscal, suporte crítico) trabalham fora do
 * calendário padrão. Sem mexer na tabela global de feriados — flags
 * contextuais do projeto que alteram apenas cálculo de `duration_business_days`
 * e skip do `BusinessCalendarService`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('allow_weekend_work')->default(false)->after('allow_negative_balance');
            $table->boolean('allow_holiday_work')->default(false)->after('allow_weekend_work');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['allow_weekend_work', 'allow_holiday_work']);
        });
    }
};
