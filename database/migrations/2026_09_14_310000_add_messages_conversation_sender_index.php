<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bounds the "last human reply" lookup behind the handling indicator
 * (final fix wave M5): `where conversation_id = ? and sender_type = 'user'
 * order by id desc limit 1` becomes a single index seek. Guarded so it is safe
 * to re-run on MariaDB and SQLite alike.
 */
return new class extends Migration
{
    private const INDEX = 'messages_conversation_id_sender_type_id_index';

    public function up(): void
    {
        if (Schema::hasIndex('messages', self::INDEX) || Schema::hasIndex('messages', ['conversation_id', 'sender_type', 'id'])) {
            return;
        }

        Schema::table('messages', fn (Blueprint $t) => $t->index(['conversation_id', 'sender_type', 'id'], self::INDEX));
    }

    public function down(): void
    {
        if (Schema::hasIndex('messages', self::INDEX)) {
            Schema::table('messages', fn (Blueprint $t) => $t->dropIndex(self::INDEX));
        }
    }
};
