<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail(s) de fechamento por parceiro — destinatário(s) fixo(s) do envio do fechamento
 * (separados por vírgula). O parceiro_admin sempre recebe (CC); estes são os To "da empresa".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (!Schema::hasColumn('partners', 'fechamento_email')) {
                $table->text('fechamento_email')->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (Schema::hasColumn('partners', 'fechamento_email')) {
                $table->dropColumn('fechamento_email');
            }
        });
    }
};
