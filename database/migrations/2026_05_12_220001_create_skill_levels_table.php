<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->unsignedTinyInteger('weight')->unique();
        });

        DB::table('skill_levels')->insert([
            ['name' => 'Básico',        'weight' => 1],
            ['name' => 'Intermediário', 'weight' => 2],
            ['name' => 'Avançado',      'weight' => 3],
            ['name' => 'Especialista',  'weight' => 4],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_levels');
    }
};
