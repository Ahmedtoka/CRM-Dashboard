<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speeds up the repeat-spam scan (ConversationPriorityClassifier::isRepeated),
 * which joins messages to conversations and filters by direction/created_at
 * for every inbound message. Guarded so the migration is safe to re-run.
 */
return new class extends Migration
{
    private const INDEX = 'messages_conversation_id_direction_created_at_index';

    public function up(): void
    {
        if (Schema::hasIndex('messages', self::INDEX) || Schema::hasIndex('messages', ['conversation_id', 'direction', 'created_at'])) {
            return;
        }

        Schema::table('messages', fn (Blueprint $t) => $t->index(['conversation_id', 'direction', 'created_at'], self::INDEX));
    }

    public function down(): void
    {
        if (Schema::hasIndex('messages', self::INDEX)) {
            Schema::table('messages', fn (Blueprint $t) => $t->dropIndex(self::INDEX));
        }
    }
};
