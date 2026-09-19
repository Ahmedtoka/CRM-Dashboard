<?php

namespace App\Bot\Flows;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the live `return_exchange` flow to the order-aware version (spec
 * 2026-09-19 §3): `order` (verify_owner) → `order_items` → the rest as it was.
 *
 * - Still exactly the seeded definition: the flow and a new published version
 *   get FlowDefinitions' new definition (the old version is archived, so it can
 *   be restored).
 * - Edited by the owner: nothing live changes; the change is added to her
 *   draft (or a new draft) for her to review and publish.
 * - Already has an `order_items` step, or no `order` step: nothing to do.
 */
final class ReturnFlowUpgrade
{
    public const FLOW_KEY = 'return_exchange';

    public const NOTE = 'اختيار القطع من الأوردر والتأكد إن الأوردر بتاعها';

    public const ITEMS_TEXT = 'اختاري القطعة اللي عايزة ترجعيها أو تبدليها 👇';

    /** The return/exchange definition as seeded on 2026-09-17 (before this change). */
    public static function legacyDefinition(): array
    {
        return ['start' => 'policy', 'steps' => [
            'policy' => ['type' => 'script', 'script' => 'flow_return_policy_short', 'next' => 'order'],
            'order' => ['type' => 'order', 'field' => 'order', 'text' => 'ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟ 🌸', 'next' => 'reason'],
            'reason' => ['type' => 'choice', 'field' => 'reason', 'text' => 'إيه سبب المرتجع؟', 'options' => [
                ['value' => 'defective', 'title' => 'بايظ / فيه عيب', 'synonyms' => ['بايظ', 'عيب', 'مقطوع', 'ديفوه', 'تالف', 'شايط']],
                ['value' => 'wrong_item', 'title' => 'غلط في الأوردر', 'synonyms' => ['غلط', 'مش اللي طلبته', 'لون تاني']],
                ['value' => 'missing_item', 'title' => 'قطعة ناقصة', 'synonyms' => ['ناقص', 'ناقصة', 'ناقصه']],
                ['value' => 'size', 'title' => 'المقاس مش مظبوط', 'synonyms' => ['مقاس', 'كبير', 'صغير', 'واسع', 'ضيق']],
                ['value' => 'not_liked', 'title' => 'مش عاجبني', 'synonyms' => ['مش عاجبني', 'معجبنيش', 'مش حلو']],
            ], 'next' => 'request'],
            'request' => ['type' => 'choice', 'field' => 'request', 'text' => 'حضرتك عايزة استرجاع المبلغ ولا استبدال؟', 'options' => [
                ['value' => 'refund', 'title' => 'استرجاع المبلغ', 'synonyms' => ['استرجاع', 'فلوس', 'مبلغ', 'refund']],
                ['value' => 'exchange', 'title' => 'استبدال', 'synonyms' => ['استبدال', 'ابدل', 'تبديل', 'exchange']],
            ], 'next' => 'product_photo'],
            'product_photo' => ['type' => 'photo', 'field' => 'product_photo', 'text' => 'ممكن صورة واضحة للمنتج؟ 📸', 'next' => 'after_photo'],
            'after_photo' => ['type' => 'script', 'script' => 'flow_photo_received', 'branches' => [['field' => 'reason', 'in' => ['defective'], 'next' => 'defect_photo']], 'next' => 'summary'],
            'defect_photo' => ['type' => 'photo', 'field' => 'defect_photo', 'text' => 'وممكن صورة توضح العيب اللي في المنتج؟ 📸', 'next' => 'summary'],
            'summary' => ['type' => 'summary', 'text' => 'ده ملخص طلب حضرتك:', 'next' => 'record'],
            'record' => ['type' => 'record_case', 'case_type' => 'return_exchange', 'script' => 'flow_return_recorded', 'next' => 'end'],
        ]];
    }

    /**
     * The definition with ownership proof on its order step and an `order_items` step right after
     * it; null when it already has one or has no order step.
     */
    public static function transform(array $def): ?array
    {
        $steps = $def['steps'] ?? null;

        if (! is_array($steps) || collect($steps)->contains(fn ($s) => is_array($s) && ($s['type'] ?? null) === 'order_items')) {
            return null;
        }

        $orderKey = is_array($steps['order'] ?? null) && ($steps['order']['type'] ?? null) === 'order'
            ? 'order'
            : collect($steps)->search(fn ($s) => is_array($s) && ($s['type'] ?? null) === 'order');

        if (! is_string($orderKey) || $orderKey === '') {
            return null;
        }

        $itemsKey = 'order_items';
        for ($n = 2; array_key_exists($itemsKey, $steps); $n++) {
            $itemsKey = "order_items_{$n}";
        }

        $order = $steps[$orderKey];
        $after = is_string($order['next'] ?? null) && $order['next'] !== '' ? $order['next'] : 'end';
        unset($order['next']);
        $order['verify_owner'] = true;
        $order['next'] = $itemsKey;

        $out = [];
        foreach ($steps as $key => $step) {
            $out[$key] = $key === $orderKey ? $order : $step;

            if ($key === $orderKey) {
                $out[$itemsKey] = ['type' => 'order_items', 'text' => self::ITEMS_TEXT, 'next' => $after];
            }
        }

        $def['steps'] = $out;

        if (is_array($def['layout'] ?? null) && is_array($def['layout'][$orderKey] ?? null)) {
            $pos = $def['layout'][$orderKey];
            $def['layout'][$itemsKey] = ['x' => (float) ($pos['x'] ?? 0) + 300, 'y' => (float) ($pos['y'] ?? 0)];
        }

        return $def;
    }

    /** Same definition regardless of JSON key order. */
    public static function same(mixed $a, mixed $b): bool
    {
        return json_encode(self::canonical($a), JSON_UNESCAPED_UNICODE) === json_encode(self::canonical($b), JSON_UNESCAPED_UNICODE);
    }

    /** @return 'updated'|'draft'|'skipped' */
    public static function run(): string
    {
        if (! Schema::hasTable('bot_flows')) {
            return 'skipped';
        }

        $flow = DB::table('bot_flows')->where('key', self::FLOW_KEY)->first();
        $current = $flow !== null ? json_decode((string) $flow->definition, true) : null;

        if (! is_array($current)) {
            return 'skipped';
        }

        $now = now();
        $versions = Schema::hasTable('bot_flow_versions');

        if (self::same($current, self::legacyDefinition())) {
            $new = FlowDefinitions::all()[self::FLOW_KEY]['definition'];
            $json = json_encode($new, JSON_UNESCAPED_UNICODE);

            DB::transaction(function () use ($flow, $json, $now, $versions) {
                DB::table('bot_flows')->where('id', $flow->id)->update(['definition' => $json, 'updated_at' => $now]);

                if (! $versions) {
                    return;
                }

                DB::table('bot_flow_versions')->where('bot_flow_id', $flow->id)->where('status', 'published')->update(['status' => 'archived', 'updated_at' => $now]);
                DB::table('bot_flow_versions')->insert([
                    'bot_flow_id' => $flow->id,
                    'version' => ((int) DB::table('bot_flow_versions')->where('bot_flow_id', $flow->id)->max('version')) + 1,
                    'status' => 'published',
                    'definition' => $json,
                    'note' => self::NOTE,
                    'created_by_id' => null,
                    'published_by_id' => null,
                    'published_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

            return 'updated';
        }

        // Already order-aware (or no order step at all): never touch the live flow or her draft.
        if (! $versions || self::transform($current) === null) {
            return 'skipped';
        }

        $draft = DB::table('bot_flow_versions')->where('bot_flow_id', $flow->id)->where('status', 'draft')->first();
        $base = $draft !== null ? json_decode((string) $draft->definition, true) : $current;
        $changed = is_array($base) ? self::transform($base) : null;

        if ($changed === null) {
            return 'skipped';
        }

        $json = json_encode($changed, JSON_UNESCAPED_UNICODE);

        if ($draft !== null) {
            DB::table('bot_flow_versions')->where('id', $draft->id)->update(['definition' => $json, 'updated_at' => $now]);
        } else {
            DB::table('bot_flow_versions')->insert([
                'bot_flow_id' => $flow->id,
                'version' => ((int) DB::table('bot_flow_versions')->where('bot_flow_id', $flow->id)->max('version')) + 1,
                'status' => 'draft',
                'definition' => $json,
                'note' => self::NOTE,
                'created_by_id' => null,
                'published_by_id' => null,
                'published_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return 'draft';
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
