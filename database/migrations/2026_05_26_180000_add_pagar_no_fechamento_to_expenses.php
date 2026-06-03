<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // Marca despesa de consultor comum para ser quitada no fechamento do
            // consultor (em vez de pagamento avulso). Parceiro já vai pro fechamento
            // automaticamente por partner_id; esta flag estende isso a qualquer consultor.
            if (!Schema::hasColumn('expenses', 'pagar_no_fechamento')) {
                $table->boolean('pagar_no_fechamento')->default(false)->after('is_paid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'pagar_no_fechamento')) {
                $table->dropColumn('pagar_no_fechamento');
            }
        });
    }
};
