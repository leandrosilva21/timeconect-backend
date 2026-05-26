<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── messages: status, snoozed_until, resolved_by ─────────────
        Schema::table('messages', function (Blueprint $table) {
            $table->string('status', 20)->default('unread')->after('metadata')
                ->comment('unread | read | resolved | archived | snoozed');
            $table->timestamp('snoozed_until')->nullable()->after('status');
            $table->foreignId('resolved_by')->nullable()->after('snoozed_until')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');

            $table->index(['conversation_id', 'status', 'created_at'], 'idx_messages_conv_status_created');
            $table->index(['status', 'snoozed_until'], 'idx_messages_status_snooze');
        });

        // ── bot_agents: cooldown_minutes + max_per_day ───────────────
        Schema::table('bot_agents', function (Blueprint $table) {
            $table->integer('cooldown_minutes')->default(60)->after('priority')
                ->comment('intervalo mínimo entre execuções por customer (anti-ruído)');
            $table->integer('max_per_day')->default(100)->after('cooldown_minutes')
                ->comment('limite de execuções por dia (proteção de custo)');
        });

        // ── bot_configs: anti-ruído global ───────────────────────────
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->integer('cooldown_minutes')->default(30)->after('default_severity_threshold')
                ->comment('cooldown global por evento idêntico');
            $table->integer('dedupe_window_minutes')->default(60)->after('cooldown_minutes')
                ->comment('janela em que duplicações são suprimidas');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('idx_messages_conv_status_created');
            $table->dropIndex('idx_messages_status_snooze');
            $table->dropForeign(['resolved_by']);
            $table->dropColumn(['status', 'snoozed_until', 'resolved_by', 'resolved_at']);
        });

        Schema::table('bot_agents', function (Blueprint $table) {
            $table->dropColumn(['cooldown_minutes', 'max_per_day']);
        });

        Schema::table('bot_configs', function (Blueprint $table) {
            $table->dropColumn(['cooldown_minutes', 'dedupe_window_minutes']);
        });
    }
};
