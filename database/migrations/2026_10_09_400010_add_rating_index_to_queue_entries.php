<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control room S4: RatingStats reads the answered ratings by time, test chats left out (/today, /reports/team,
 * the board). Guarded so it is safe to re-run on MariaDB 10.4; down() drops only this index.
 */
return new class extends Migration
{
    private const NAME = 'queue_entries_test_reviewed_idx';

    public function up(): void
    {
        if (! Schema::hasTable('queue_entries')
            || ! Schema::hasColumns('queue_entries', ['is_test', 'reviewed_at'])
            || Schema::hasIndex('queue_entries', self::NAME)
            || Schema::hasIndex('queue_entries', ['is_test', 'reviewed_at'])) {
            return;
        }

        Schema::table('queue_entries', fn (Blueprint $t) => $t->index(['is_test', 'reviewed_at'], self::NAME));
    }

    public function down(): void
    {
        if (Schema::hasTable('queue_entries') && Schema::hasIndex('queue_entries', self::NAME)) {
            Schema::table('queue_entries', fn (Blueprint $t) => $t->dropIndex(self::NAME));
        }
    }
};
