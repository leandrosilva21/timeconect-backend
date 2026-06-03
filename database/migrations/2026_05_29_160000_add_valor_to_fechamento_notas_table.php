<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valor declarado da nota fiscal PJ (NFS-e e Nota de débito) no fechamento.
 * O valor informado pelo PJ no upload deve bater com o recebimento do fechamento
 * daquele mês; é persistido aqui para o administrativo conferir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_notas', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_notas', 'nfse_valor')) {
                $table->decimal('nfse_valor', 12, 2)->nullable()->after('nfse_decided_at');
            }
            if (!Schema::hasColumn('fechamento_notas', 'nota_debito_valor')) {
                $table->decimal('nota_debito_valor', 12, 2)->nullable()->after('nota_debito_decided_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_notas', function (Blueprint $table) {
            if (Schema::hasColumn('fechamento_notas', 'nfse_valor')) {
                $table->dropColumn('nfse_valor');
            }
            if (Schema::hasColumn('fechamento_notas', 'nota_debito_valor')) {
                $table->dropColumn('nota_debito_valor');
            }
        });
    }
};
