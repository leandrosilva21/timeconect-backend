<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movidesk_organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('movidesk_organizations', 'project_id')) {
                $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete()->after('customer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movidesk_organizations', function (Blueprint $table) {
            if (Schema::hasColumn('movidesk_organizations', 'project_id')) {
                $table->dropForeign(['project_id']);
                $table->dropColumn('project_id');
            }
        });
    }
};
