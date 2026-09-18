<?php

use App\Bot\Flow\Scripts\LeVoileScripts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only and idempotent (bot reply flow v2, 2026-09-16): product questions answer with the
 * website link, product problems need an order detail plus a photo, ordering through us hands over
 * with its own message, and the first reply offers a human. New rows are inserted only when their
 * key is missing; existing rows change only while they still carry the original seed value.
 */
return new class extends Migration
{
    private const NEW_SCRIPTS = ['material_link', 'order_on_website', 'order_via_agent', 'offer_human'];

    /** intent key => [old script_keys, new script_keys] */
    private const SCRIPT_KEYS = [
        'price' => [['price', 'availability'], ['availability']],
        'material' => [['material'], ['material_link']],
        'how_to_order' => [['placing_order'], ['order_on_website']],
    ];

    /** intent key => old required_details (all move to NEW_DETAILS) */
    private const DETAILS = [
        'defect' => ['order_ref|invoice', 'photos'],
        'wrong_item' => ['order_ref|invoice', 'photos'],
        'missing_item' => ['order_ref|invoice', 'photos'],
        'exchange_return' => ['order_ref|invoice', 'product_photo', 'tag_photo'],
    ];

    private const NEW_DETAILS = ['order_ref|phone|email', 'photos'];

    public function up(): void
    {
        if (! Schema::hasTable('bot_intents') || ! Schema::hasTable('bot_knowledge_entries')) {
            return;
        }

        $now = now();
        $scripts = LeVoileScripts::scripts();
        $scriptSort = (int) DB::table('bot_knowledge_entries')->max('sort');

        foreach (self::NEW_SCRIPTS as $key) {
            if (DB::table('bot_knowledge_entries')->where('key', 'script.'.$key)->exists()) {
                continue;
            }

            $scriptSort += 10;
            DB::table('bot_knowledge_entries')->insert([
                'key' => 'script.'.$key,
                'title' => $scripts[$key]['title'],
                'body' => $scripts[$key]['body'],
                'is_active' => $scripts[$key]['active'],
                'is_template' => false,
                'sort' => $scriptSort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('bot_intents')->where('key', 'order_via_agent')->exists()) {
            $row = collect(LeVoileScripts::intents())->firstWhere('key', 'order_via_agent');

            DB::table('bot_intents')->insert([
                'key' => $row['key'],
                'group' => $row['group'],
                'label_ar' => $row['label_ar'],
                'label_en' => $row['label_en'],
                'route' => $row['route'],
                'priority' => $row['priority'],
                'queue' => $row['queue'],
                'script_keys' => json_encode($row['script_keys'], JSON_UNESCAPED_UNICODE),
                'required_details' => json_encode($row['required_details'], JSON_UNESCAPED_UNICODE),
                'keywords' => json_encode($row['keywords'], JSON_UNESCAPED_UNICODE),
                'sort' => (int) DB::table('bot_intents')->max('sort') + 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::SCRIPT_KEYS as $key => [$old, $new]) {
            $this->updateIfUnchanged($key, 'script_keys', $old, $new);
        }

        foreach (self::DETAILS as $key => $old) {
            $this->updateIfUnchanged($key, 'required_details', $old, self::NEW_DETAILS);
        }
    }

    private function updateIfUnchanged(string $key, string $column, array $old, array $new): void
    {
        $current = DB::table('bot_intents')->where('key', $key)->value($column);

        if ($current !== null && json_decode((string) $current, true) === $old) {
            DB::table('bot_intents')->where('key', $key)->update([$column => json_encode($new, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Data seed: left in place (the owner may have edited it).
    }
};
