<?php

namespace App\Bot\Knowledge;

use App\Bot\Flow\Scripts\LeVoileScripts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the seeded placeholder knowledge with Le Voile's real policies
 * (KnowledgeDefaults) on an existing database. Idempotent and owner-safe: a row
 * is only rewritten while it is still the untouched sample, i.e. it is still
 * flagged is_template (the settings page clears the flag on every title/body
 * save) AND its body is still the old placeholder (or already the new default).
 * Every other row is left exactly as the owner left it.
 *
 * Also fills the ❓ placeholder script.payment_info (menu "طرق الدفع") and adds
 * the shipping_cost intent + script.shipping_fee when missing.
 * Run by the 2026_09_18_500010 data migration.
 */
final class RealKnowledgeApplier
{
    /** @return list<string> the keys that were updated or inserted */
    public function apply(): array
    {
        if (! Schema::hasTable('bot_knowledge_entries')) {
            return [];
        }

        $now = now();
        $changed = [];

        foreach (KnowledgeDefaults::entries() as $entry) {
            $row = DB::table('bot_knowledge_entries')->where('key', $entry['key'])->first();

            if ($row === null) {
                // Core entries cannot be deleted from the UI; a missing one has nothing to overwrite.
                DB::table('bot_knowledge_entries')->insert($entry + ['is_active' => true, 'is_template' => false, 'created_at' => $now, 'updated_at' => $now]);
                $changed[] = $entry['key'];

                continue;
            }

            $body = trim((string) $row->body);
            $untouched = (bool) $row->is_template
                && in_array($body, [trim(KnowledgeDefaults::LEGACY_SAMPLES[$entry['key']] ?? ''), trim($entry['body'])], true);

            if (! $untouched) {
                continue;
            }

            DB::table('bot_knowledge_entries')->where('id', $row->id)->update(['body' => $entry['body'], 'is_template' => false, 'updated_at' => $now]);
            $changed[] = $entry['key'];
        }

        $payment = DB::table('bot_knowledge_entries')->where('key', 'script.payment_info')->first();

        if ($payment !== null && trim((string) $payment->body) === LeVoileScripts::PAYMENT_PLACEHOLDER) {
            DB::table('bot_knowledge_entries')->where('id', $payment->id)->update(['body' => LeVoileScripts::PAYMENT_TEXT, 'is_active' => true, 'updated_at' => $now]);
            $changed[] = 'script.payment_info';
        }

        if (! DB::table('bot_knowledge_entries')->where('key', 'script.shipping_fee')->exists()) {
            $script = LeVoileScripts::scripts()['shipping_fee'];
            DB::table('bot_knowledge_entries')->insert([
                'key' => 'script.shipping_fee',
                'title' => $script['title'],
                'body' => $script['body'],
                'is_active' => $script['active'],
                'is_template' => false,
                'sort' => (int) DB::table('bot_knowledge_entries')->max('sort') + 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $changed[] = 'script.shipping_fee';
        }

        if (Schema::hasTable('bot_intents') && ! DB::table('bot_intents')->where('key', 'shipping_cost')->exists()) {
            $row = collect(LeVoileScripts::intents())->firstWhere('key', 'shipping_cost');
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
                // Right after delivery_time in the settings list.
                'sort' => (int) (DB::table('bot_intents')->where('key', 'delivery_time')->value('sort') ?? DB::table('bot_intents')->max('sort')) + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $changed[] = 'intent.shipping_cost';
        }

        return $changed;
    }
}
