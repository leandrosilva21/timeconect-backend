<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ticket_initial_balances', function (Blueprint $table) {
            $table->id();
            $table->string('ticket', 50);
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('project_id')->constrained('projects');
            $table->integer('initial_minutes')->default(0);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'ticket']);
        });

        // Unique parcial: 1 saldo inicial vivo por par (ticket, customer_id).
        // PG aceita partial unique index, evita problema com soft-delete.
        DB::statement('CREATE UNIQUE INDEX ticket_initial_balances_ticket_customer_unique
            ON ticket_initial_balances (ticket, customer_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_initial_balances');
    }
};
