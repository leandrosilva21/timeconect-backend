<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('projects', 'extrato_visivel_cliente')) {
            return;
        }

        // Novo default: false — o cliente NÃO vê o Extrato a menos que o admin ligue.
        DB::statement('ALTER TABLE projects ALTER COLUMN extrato_visivel_cliente SET DEFAULT false');

        // Backfill: desliga em todos os projetos; liga só nos da CONCRESERV (por nome
        // do cliente, p/ valer em qualquer ambiente — id pode diferir).
        DB::table('projects')->update(['extrato_visivel_cliente' => false]);
        DB::table('projects')
            ->whereIn('customer_id', function ($q) {
                $q->select('id')->from('customers')->where('name', 'like', '%CONCRESERV%');
            })
            ->update(['extrato_visivel_cliente' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'extrato_visivel_cliente')) {
            DB::statement('ALTER TABLE projects ALTER COLUMN extrato_visivel_cliente SET DEFAULT true');
        }
    }
};
