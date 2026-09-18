<?php

use App\Bot\Flow\Scripts\LeVoileScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent, for databases seeded before these were added:
 * appends the missing offline tracking hints to `order_status` (owner
 * keywords are kept) and inserts `script.handover_ack` when it is missing
 * (an existing row, possibly owner-edited, is never touched).
 */
return new class extends Migration
{
    private const HINTS = ['فين الاوردر', 'الاوردر فين', 'اوردري', 'طلبي', 'تتبع'];

    public function up(): void
    {
        if (Schema::hasTable('bot_intents')) {
            $row = DB::table('bot_intents')->where('key', 'order_status')->first();

            if ($row !== null) {
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
        }

        if (Schema::hasTable('bot_knowledge_entries') && ! DB::table('bot_knowledge_entries')->where('key', 'script.handover_ack')->exists()) {
            $s = LeVoileScripts::scripts()['handover_ack'];

            DB::table('bot_knowledge_entries')->insert([
                'key' => 'script.handover_ack',
                'title' => $s['title'],
                'body' => $s['body'],
                'is_active' => $s['active'],
                'is_template' => false,
                'sort' => 1500,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Data seed: left in place (the owner may have edited it).
    }
};
