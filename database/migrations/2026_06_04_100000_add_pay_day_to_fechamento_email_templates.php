<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dia do mês usado pela variável {data} do modelo (ex.: cooperado recebe dia 19,
 * PJ envia NF dia 20). A {data} resolve esse dia no MÊS SEGUINTE ao fechamento,
 * ajustado pro dia útil anterior se cair em fds/feriado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_email_templates', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_email_templates', 'pay_day')) {
                $table->unsignedTinyInteger('pay_day')->nullable()->after('body');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_email_templates', function (Blueprint $table) {
            if (Schema::hasColumn('fechamento_email_templates', 'pay_day')) {
                $table->dropColumn('pay_day');
            }
        });
    }
};
