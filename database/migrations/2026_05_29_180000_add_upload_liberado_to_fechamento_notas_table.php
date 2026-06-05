<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liberação de envio de nota fiscal PJ após o prazo (dia 15 do mês).
 * O envio fica bloqueado para o PJ depois do dia 15; o administrativo pode
 * liberar o envio daquele consultor/parceiro naquele mês, reabilitando o upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_notas', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_notas', 'upload_liberado')) {
                $table->boolean('upload_liberado')->default(false)->after('nota_debito_valor');
            }
            if (!Schema::hasColumn('fechamento_notas', 'liberado_por')) {
                $table->string('liberado_por')->nullable()->after('upload_liberado');
            }
            if (!Schema::hasColumn('fechamento_notas', 'liberado_em')) {
                $table->timestamp('liberado_em')->nullable()->after('liberado_por');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_notas', function (Blueprint $table) {
            if (Schema::hasColumn('fechamento_notas', 'liberado_em')) {
                $table->dropColumn('liberado_em');
            }
            if (Schema::hasColumn('fechamento_notas', 'liberado_por')) {
                $table->dropColumn('liberado_por');
            }
            if (Schema::hasColumn('fechamento_notas', 'upload_liberado')) {
                $table->dropColumn('upload_liberado');
            }
        });
    }
};
