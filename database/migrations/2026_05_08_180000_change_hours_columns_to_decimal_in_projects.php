<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Converte colunas de horas em projects de integer para decimal(10,2),
        // permitindo valores fracionários (ex: 4295.5 horas).
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('sold_hours',             10, 2)->nullable()->change();
            $table->decimal('accumulated_sold_hours', 10, 2)->nullable()->change();
            $table->decimal('consultant_hours',       10, 2)->nullable()->change();
            $table->decimal('coordinator_hours',      10, 2)->nullable()->change();
        });

        // Histórico de sold_hours também precisa aceitar decimal
        if (Schema::hasTable('project_sold_hours_history')) {
            Schema::table('project_sold_hours_history', function (Blueprint $table) {
                $table->decimal('sold_hours', 10, 2)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('sold_hours')->nullable()->change();
            $table->integer('accumulated_sold_hours')->nullable()->change();
            $table->integer('consultant_hours')->nullable()->change();
            $table->integer('coordinator_hours')->nullable()->change();
        });

        if (Schema::hasTable('project_sold_hours_history')) {
            Schema::table('project_sold_hours_history', function (Blueprint $table) {
                $table->integer('sold_hours')->change();
            });
        }
    }
};
