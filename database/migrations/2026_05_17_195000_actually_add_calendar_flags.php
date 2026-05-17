<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('allow_weekend_work')->default(false)->after('allow_negative_balance');
            $table->boolean('allow_holiday_work')->default(false)->after('allow_weekend_work');
        });
    }
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['allow_weekend_work', 'allow_holiday_work']);
        });
    }
};
