<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's flows 5–7 (2026-09-19):
 * - `messages.cards`: rich outbound cards (the branches carousel, the store-link button) the flow
 *   engine attaches to a bot message; each channel sends them its own way.
 * - `conversations.handover_topic`: what the customer said she needs when she asked for a person
 *   («موضوع التحويل»), shown in the inbox as the handover reason.
 * - `bot_settings.store_url`: the store link of the «🛍️ تسوقي من الموقع» button.
 * Guarded: re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages') && ! Schema::hasColumn('messages', 'cards')) {
            Schema::table('messages', fn (Blueprint $t) => $t->json('cards')->nullable()->after('buttons'));
        }

        if (Schema::hasTable('conversations') && ! Schema::hasColumn('conversations', 'handover_topic')) {
            Schema::table('conversations', fn (Blueprint $t) => $t->string('handover_topic', 255)->nullable()->after('handover_category'));
        }

        if (Schema::hasTable('bot_settings') && ! Schema::hasColumn('bot_settings', 'store_url')) {
            Schema::table('bot_settings', fn (Blueprint $t) => $t->string('store_url', 255)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['messages' => 'cards', 'conversations' => 'handover_topic', 'bot_settings' => 'store_url'] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
