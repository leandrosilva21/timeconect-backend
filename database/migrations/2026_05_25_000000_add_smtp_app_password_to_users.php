<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App Password de SMTP do usuário (O365), guardado criptografado em repouso.
 * Permite que o usuário logado envie os fechamentos COM o próprio e-mail no From
 * (Send As self via SMTP AUTH). Vazio = mantém o comportamento atual (remetente padrão).
 *
 * `text` porque o valor criptografado (cast 'encrypted', AES via APP_KEY) é longo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'smtp_app_password')) {
                $table->text('smtp_app_password')->nullable()->after('profile_photo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'smtp_app_password')) {
                $table->dropColumn('smtp_app_password');
            }
        });
    }
};
