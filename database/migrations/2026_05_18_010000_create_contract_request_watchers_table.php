<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_request_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_request_id')
                ->constrained('contract_requests')
                ->cascadeOnDelete();
            // Cliente em cópia: pode ser usuário cadastrado (user_id) ou apenas e-mail
            // (não cadastrado — fica apenas como registro/auditoria, sem acesso ao sistema).
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('email');
            $table->timestamps();

            $table->unique(['contract_request_id', 'email']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_request_watchers');
    }
};
