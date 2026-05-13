<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 30)->nullable();
            }
            if (!Schema::hasColumn('users', 'linkedin_url')) {
                $table->string('linkedin_url', 255)->nullable();
            }
            if (!Schema::hasColumn('users', 'work_model')) {
                $table->string('work_model', 20)->nullable()
                    ->comment('PJ | CLT | Freelancer | Hibrido');
            }
            if (!Schema::hasColumn('users', 'city')) {
                $table->string('city', 100)->nullable();
            }
            if (!Schema::hasColumn('users', 'state')) {
                $table->string('state', 2)->nullable();
            }
            if (!Schema::hasColumn('users', 'protheus_years_experience')) {
                $table->unsignedSmallInteger('protheus_years_experience')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone','linkedin_url','work_model','city','state','protheus_years_experience']);
        });
    }
};
