<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (overnight refinement change 2): appends the
 * missing "placing an order" hints to how_to_order's keywords (owner
 * keywords, and any already-present hint, are kept). Same pattern as
 * 2026_09_15_100040_add_tracking_hints_and_handover_ack.php's order_status hints.
 */
return new class extends Migration
{
    private const HINTS = ['احجز', 'عايزة اطلب', 'عاوزه اطلب', 'اطلب'];

    public function up(): void
    {
        if (! Schema::hasTable('bot_intents')) {
            return;
        }

        $row = DB::table('bot_intents')->where('key', 'how_to_order')->first();

        if ($row === null) {
            return;
        }

        $keywords = json_decode((string) $row->keywords, true);
        $keywords = is_array($keywords) ? $keywords : [];
        $merged = array_values(array_unique(array_merge($keywords, self::HINTS)));

        if ($merged !== $keywords) {
            DB::table('bot_intents')->where('id', $row->id)->update([
                'keywords' => json_encode($merged, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Data fix: left in place (the owner may have edited it since).
    }
};
