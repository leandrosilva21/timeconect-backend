<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envolvimento opcional do cliente numa atividade.
 *
 * - `client_user_id` (FK users nullable): cliente já cadastrado com login.
 *   Cliente com este id pode acessar SÓ esta atividade (exceção pontual ao
 *   middleware BlockCliente).
 * - `client_email` (string nullable): envolvimento externo simples — sem
 *   conta no sistema. Usado apenas para notificações outbound.
 * - `client_involved` (bool default false): toggle explícito; quando false,
 *   nenhum dos dois campos acima é considerado (mesmo se preenchido).
 *
 * Não criamos tabela `activity_clients` — o brief é "envolvimento opcional",
 * 1 cliente por atividade resolve o caso real (homologação, validação, etc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('stage_deliveries', 'client_user_id')) {
                $table->foreignId('client_user_id')
                    ->nullable()
                    ->after('dependency_type')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->index('client_user_id');
            }
            if (!Schema::hasColumn('stage_deliveries', 'client_email')) {
                $table->string('client_email', 180)->nullable()->after('client_user_id');
            }
            if (!Schema::hasColumn('stage_deliveries', 'client_involved')) {
                $table->boolean('client_involved')->default(false)->after('client_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stage_deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('stage_deliveries', 'client_involved')) {
                $table->dropColumn('client_involved');
            }
            if (Schema::hasColumn('stage_deliveries', 'client_email')) {
                $table->dropColumn('client_email');
            }
            if (Schema::hasColumn('stage_deliveries', 'client_user_id')) {
                $table->dropForeign(['client_user_id']);
                $table->dropIndex(['client_user_id']);
                $table->dropColumn('client_user_id');
            }
        });
    }
};
