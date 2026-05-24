<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valores editáveis da folha de pagamento por consultor/mês (planilha de importação).
 * Campos auto (CPF, valor hora, produção, apontamentos) NÃO ficam aqui — vêm do
 * usuário / fechamento / apontamentos em tempo de geração. Aqui só o que o admin define
 * na tela e quer persistir por mês.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fechamento_folha', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('year_month', 7); // YYYY-MM
            $table->decimal('dias_trabalhados', 6, 2)->default(0);
            $table->decimal('horas_trabalhadas', 8, 2)->nullable(); // override; null = usa apontamentos
            $table->decimal('variavel', 12, 2)->default(0);
            $table->decimal('reemb', 12, 2)->default(0);
            $table->decimal('descontos_diversos', 12, 2)->default(0);
            $table->decimal('adiantamento', 12, 2)->default(0);
            $table->string('horista_mensalista', 20)->nullable(); // 'Horista' | 'Mensalista'
            $table->timestamps();
            $table->unique(['user_id', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_folha');
    }
};
