<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos (cadastro) de e-mail dos fechamentos. Consultor e parceiro têm um
 * modelo por tipo de contrato (cooperado/clt/pj); cliente é único (contract_type
 * null). Só pode haver 1 ativo por (categoria, contract_type).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fechamento_email_templates')) {
            return;
        }
        Schema::create('fechamento_email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('categoria');                 // consultor | parceiro | cliente
            $table->string('contract_type')->nullable(); // cooperado | clt | pj (null p/ cliente)
            $table->string('nome')->nullable();          // rótulo do modelo
            $table->string('subject');
            $table->text('body');
            $table->boolean('active')->default(false);
            $table->timestamps();
            $table->index(['categoria', 'contract_type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_email_templates');
    }
};
