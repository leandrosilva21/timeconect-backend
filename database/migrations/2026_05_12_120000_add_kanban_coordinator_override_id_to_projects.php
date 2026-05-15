<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Override de coordenador: quando preenchido, projeto de sustentação é
            // gerenciado por esse coordenador. Card sai da fila de sustentação no
            // Kanban e migra pra coluna do coord. Some das abas Apontamentos/
            // Despesas/Aprovações do Portal de Sustentação. Demais lugares (incl.
            // Aprovações globais) permanecem normais. Só admin pode setar/limpar.
            $table->foreignId('kanban_coordinator_override_id')
                ->nullable()
                ->after('encerramento_date')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kanban_coordinator_override_id');
        });
    }
};
