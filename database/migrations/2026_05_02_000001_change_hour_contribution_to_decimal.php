<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Alterar hour_contribution e exceeded_hour_contribution para decimal nos projetos
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('hour_contribution', 10, 2)->nullable()->change();
            $table->decimal('exceeded_hour_contribution', 10, 2)->nullable()->change();
        });

        // Alterar contributed_hours para decimal em hour_contributions
        Schema::table('hour_contributions', function (Blueprint $table) {
            $table->decimal('contributed_hours', 10, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('hour_contribution')->nullable()->change();
            $table->integer('exceeded_hour_contribution')->nullable()->change();
        });

        Schema::table('hour_contributions', function (Blueprint $table) {
            $table->integer('contributed_hours')->change();
        });
    }
};
