<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'availability_status')) {
                $table->string('availability_status', 20)->nullable()->after('allocated_hours')
                    ->comment('integral | parcial | indisponivel');
            }
            if (!Schema::hasColumn('users', 'availability_start_date')) {
                $table->date('availability_start_date')->nullable()->after('availability_status');
            }
            if (!Schema::hasColumn('users', 'relevant_projects')) {
                $table->text('relevant_projects')->nullable();
            }
            if (!Schema::hasColumn('users', 'segments')) {
                $table->json('segments')->nullable()->comment('array de segmentos atuados');
            }
        });

        Schema::table('consultant_skills', function (Blueprint $table) {
            if (!Schema::hasColumn('consultant_skills', 'atuacao_types')) {
                $table->json('atuacao_types')->nullable()
                    ->comment('array de tipos: Implementação | Suporte | Manutenção | Desenvolvimento | Consultoria');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['availability_status', 'availability_start_date', 'relevant_projects', 'segments']);
        });
        Schema::table('consultant_skills', function (Blueprint $table) {
            $table->dropColumn('atuacao_types');
        });
    }
};
