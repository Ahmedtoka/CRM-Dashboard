<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (final fix wave C1): the refund intent was seeded with
 * script.refund_request, which tells the customer the refund is already done, as
 * its ask. Points it at script.tracking_order ("send the order number") — only
 * while the row still carries the original seed value, so an owner edit is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_intents')) {
            return;
        }

        $row = DB::table('bot_intents')->where('key', 'refund')->first();

        if ($row === null || json_decode((string) $row->script_keys, true) !== ['refund_request']) {
            return;
        }

        DB::table('bot_intents')->where('id', $row->id)->update([
            'script_keys' => json_encode(['tracking_order']),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Data fix: left in place (the owner may have edited the intent since).
    }
};
