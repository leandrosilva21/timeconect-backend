<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-mails administrativos do cliente (lista). Lista única usada TANTO pelo
 * fechamento de cliente QUANTO pelo comunicado de reajuste. Backfill a partir
 * do `fechamento_email` atual (que pode conter vários separados por , ; ou espaço).
 * O `fechamento_email` é mantido (= 1º e-mail) p/ compatibilidade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'emails_administrativos')) {
                $table->json('emails_administrativos')->nullable()->after('fechamento_email');
            }
        });

        // Backfill: separa o fechamento_email existente em lista.
        foreach (DB::table('customers')->whereNotNull('fechamento_email')->where('fechamento_email', '!=', '')->get(['id', 'fechamento_email']) as $c) {
            $emails = collect(preg_split('/[,;\s]+/', (string) $c->fechamento_email))
                ->map(fn ($e) => trim($e))
                ->filter(fn ($e) => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL))
                ->values()
                ->all();
            if ($emails) {
                DB::table('customers')->where('id', $c->id)->update(['emails_administrativos' => json_encode($emails)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'emails_administrativos')) {
                $table->dropColumn('emails_administrativos');
            }
        });
    }
};
