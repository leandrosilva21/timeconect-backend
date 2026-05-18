<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_envolvidos', function (Blueprint $t) {
            $t->id();
            $t->enum('card_type', ['contract_request', 'project']);
            $t->unsignedBigInteger('card_id');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('external_email')->nullable();
            $t->enum('side', ['internal', 'client']);
            $t->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['card_type', 'card_id']);
            $t->index('user_id');
        });

        // UNIQUE parcial (PG): mesmo user/email não pode duplicar no mesmo card ativo
        \DB::statement('CREATE UNIQUE INDEX card_envolvidos_user_unique ON card_envolvidos (card_type, card_id, user_id) WHERE deleted_at IS NULL AND user_id IS NOT NULL');
        \DB::statement('CREATE UNIQUE INDEX card_envolvidos_email_unique ON card_envolvidos (card_type, card_id, external_email) WHERE deleted_at IS NULL AND external_email IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('card_envolvidos');
    }
};
