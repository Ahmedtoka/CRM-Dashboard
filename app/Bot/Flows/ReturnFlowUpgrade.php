<?php

namespace App\Bot\Flows;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's return/exchange flow (2026-09-19, dictated in chat), published as
 * a new version of `return_exchange`:
 *
 *   policy → order (ownership proof) → greeting "أهلاً يا {name} … ترجعي ولا تبدلي؟"
 *     إرجاع:   return_items → return_reason → return_photo → record_return (case `return`)
 *     استبدال: exchange_items → exchange_reason → exchange_product (store link) → record_exchange (case `exchange`)
 *   past the 14 days → the policy and a person; order not found → the same question without the greeting.
 *
 * `run()` publishes it whatever the live flow is (the owner asked for this exact
 * flow): the published version is archived, a draft is archived too (both stay
 * restorable from the designer's history) and cleared, and a new published
 * version is added. Idempotent: nothing happens once the live flow is this one.
 *
 * Supersedes the first order-aware upgrade (same day), whose migration is now a no-op.
 */
final class ReturnFlowUpgrade
{
    public const FLOW_KEY = 'return_exchange';

    public const NOTE = 'فلو المرتجع والاستبدال الجديد: ترجعي ولا تبدلي، صورة للمرتجع، ولينك المنتج للاستبدال';

    public const GREETING_TEXT = 'أهلاً يا {customer_first_name} 🌸 لقيت أوردر #{order_number} — تحبي ترجعي ولا تبدلي؟';

    public const RETURN_DONE_TEXT = 'تمام ✅ تم تقديم طلب المرتجع بنجاح، ورقم طلبك هو نفس رقم الأوردر #{order_number}. هنتواصل معاكي أول ما المندوب يتحرك لاستلام المرتجع 🌸';

    public const EXCHANGE_DONE_TEXT = 'تمام ✅ تم تسجيل طلب الاستبدال بـ «{exchange_product_title}». هنتواصل معاكي لتأكيد الاستبدال والإرسال 🌸';

    public const LATE_TEXT = "الأوردر ده عدّى على استلامه أكتر من 14 يوم 🙏 والمرتجع والاستبدال عندنا خلال 14 يوم من الاستلام بس.\nلو تحبي، أحوّلك لحد من الفريق يساعدك.";

    /** The owner's flow (the seeded `return_exchange` definition since 2026-09-19). */
    public static function definition(): array
    {
        $kindOptions = [
            ['value' => 'return', 'title' => 'إرجاع', 'synonyms' => ['ارجاع', 'ارجع', 'مرتجع', 'استرجاع', 'رجوع', 'return', 'refund'], 'next' => 'return_items'],
            ['value' => 'exchange', 'title' => 'استبدال', 'synonyms' => ['استبدال', 'ابدل', 'تبديل', 'بدل', 'exchange'], 'next' => 'exchange_items'],
        ];

        return ['start' => 'policy', 'steps' => [
            'policy' => ['type' => 'script', 'script' => 'flow_return_policy_short', 'next' => 'order'],
            'order' => ['type' => 'order', 'field' => 'order', 'text' => 'ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟ 🌸', 'verify_owner' => true, 'branches' => [
                ['field' => 'order_window', 'in' => ['closed'], 'next' => 'late'],
                ['field' => 'order_window', 'in' => ['open'], 'next' => 'kind'],
            ], 'next' => 'kind_unknown'],
            'kind' => ['type' => 'choice', 'field' => 'request_kind', 'text' => self::GREETING_TEXT, 'options' => $kindOptions],
            'kind_unknown' => ['type' => 'choice', 'field' => 'request_kind', 'text' => 'تحبي ترجعي ولا تبدلي؟ 🌸', 'options' => $kindOptions],
            'late' => ['type' => 'choice', 'field' => 'late_choice', 'text' => self::LATE_TEXT, 'options' => [
                ['value' => 'agent', 'title' => 'كلم موظف', 'synonyms' => ['موظف', 'ايوه', 'اه', 'حوليني'], 'next' => 'agent'],
            ]],
            'agent' => ['type' => 'handover'],

            'return_items' => ['type' => 'order_items', 'text' => 'اختاري القطعة اللي عايزة ترجعيها 👇', 'branches' => [
                // A discounted piece can only be exchanged: "أبدلها بدل كده" turns the request into an exchange.
                ['field' => 'request_kind', 'in' => ['exchange'], 'next' => 'exchange_reason'],
            ], 'next' => 'return_reason'],
            'return_reason' => ['type' => 'choice', 'field' => 'reason', 'text' => 'إيه سبب المرتجع؟', 'options' => [
                ['value' => 'defective', 'title' => 'بايظ / فيه عيب', 'synonyms' => ['بايظ', 'عيب', 'مقطوع', 'ديفوه', 'تالف', 'شايط']],
                ['value' => 'wrong_item', 'title' => 'غلط في الأوردر', 'synonyms' => ['غلط', 'مش اللي طلبته', 'لون تاني']],
                ['value' => 'missing_item', 'title' => 'قطعة ناقصة', 'synonyms' => ['ناقص', 'ناقصة', 'ناقصه']],
                ['value' => 'size', 'title' => 'المقاس مش مظبوط', 'synonyms' => ['مقاس', 'كبير', 'صغير', 'واسع', 'ضيق']],
                ['value' => 'not_liked', 'title' => 'مش عاجبني', 'synonyms' => ['مش عاجبني', 'معجبنيش', 'مش حلو']],
            ], 'next' => 'return_photo'],
            'return_photo' => ['type' => 'photo', 'field' => 'product_photo', 'text' => 'ابعتيلي صورة للقطعة 📸', 'next' => 'record_return'],
            'record_return' => ['type' => 'record_case', 'case_type' => 'return', 'text' => self::RETURN_DONE_TEXT, 'next' => 'end'],

            'exchange_items' => ['type' => 'order_items', 'text' => 'اختاري القطعة اللي عايزة تبدليها 👇', 'next' => 'exchange_reason'],
            'exchange_reason' => ['type' => 'choice', 'field' => 'reason', 'text' => 'إيه سبب الاستبدال؟', 'options' => [
                ['value' => 'size', 'title' => 'المقاس', 'synonyms' => ['مقاس', 'كبير', 'صغير', 'واسع', 'ضيق']],
                ['value' => 'color', 'title' => 'اللون', 'synonyms' => ['لون', 'اللون']],
                ['value' => 'style', 'title' => 'الموديل', 'synonyms' => ['موديل', 'الموديل', 'الشكل', 'ستايل']],
                ['value' => 'defective', 'title' => 'فيه عيب', 'synonyms' => ['عيب', 'بايظ', 'مقطوع', 'تالف', 'ديفوه']],
                ['value' => 'other', 'title' => 'حاجة تانية', 'synonyms' => ['حاجة تانية', 'تاني', 'غير كده']],
            ], 'next' => 'exchange_product'],
            'exchange_product' => ['type' => 'product_link', 'field' => 'exchange_product', 'text' => 'ابعتيلي لينك المنتج اللي عايزة تبدلي بيه من الموقع 🔗 (من levoilestores.com)', 'next' => 'record_exchange'],
            'record_exchange' => ['type' => 'record_case', 'case_type' => 'exchange', 'text' => self::EXCHANGE_DONE_TEXT, 'next' => 'end'],
        ]];
    }

    /** The first order-aware version (commit 009704c): order → order_items → reason → request → photos → summary. */
    public static function orderAwareDefinition(): array
    {
        $def = self::legacyDefinition();
        $order = $def['steps']['order'];
        $order['verify_owner'] = true;
        $order['next'] = 'order_items';

        $steps = ['policy' => $def['steps']['policy'], 'order' => $order, 'order_items' => ['type' => 'order_items', 'text' => 'اختاري القطعة اللي عايزة ترجعيها أو تبدليها 👇', 'next' => 'reason']];

        return ['start' => 'policy', 'steps' => $steps + array_diff_key($def['steps'], $steps)];
    }

    /** The return/exchange definition as seeded on 2026-09-17. */
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

    /** Same definition regardless of JSON key order. */
    public static function same(mixed $a, mixed $b): bool
    {
        return json_encode(self::canonical($a), JSON_UNESCAPED_UNICODE) === json_encode(self::canonical($b), JSON_UNESCAPED_UNICODE);
    }

    /** @return 'published'|'skipped' */
    public static function run(): string
    {
        return self::publish(self::FLOW_KEY, self::definition(), self::NOTE);
    }

    /**
     * Publishes $new as the next version of flow $flowKey (also used by TrackingFlowUpgrade):
     * the live published version and any draft are archived (restorable), nothing happens
     * when the live flow already is $new.
     *
     * @return 'published'|'skipped'
     */
    public static function publish(string $flowKey, array $new, string $note): string
    {
        if (! Schema::hasTable('bot_flows')) {
            return 'skipped';
        }

        $flow = DB::table('bot_flows')->where('key', $flowKey)->first();

        if ($flow === null) {
            return 'skipped';
        }

        $current = json_decode((string) $flow->definition, true);

        if (self::same($current, $new)) {
            return 'skipped';
        }

        $json = json_encode($new, JSON_UNESCAPED_UNICODE);
        $now = now();
        $versions = Schema::hasTable('bot_flow_versions');

        DB::transaction(function () use ($flow, $json, $now, $versions, $note) {
            DB::table('bot_flows')->where('id', $flow->id)->update(['definition' => $json, 'updated_at' => $now]);

            if (! $versions) {
                return;
            }

            // The previous published version and any unpublished draft stay in the history (restorable).
            DB::table('bot_flow_versions')->where('bot_flow_id', $flow->id)->whereIn('status', ['published', 'draft'])
                ->update(['status' => 'archived', 'updated_at' => $now]);

            DB::table('bot_flow_versions')->insert([
                'bot_flow_id' => $flow->id,
                'version' => ((int) DB::table('bot_flow_versions')->where('bot_flow_id', $flow->id)->max('version')) + 1,
                'status' => 'published',
                'definition' => $json,
                'note' => $note,
                'created_by_id' => null,
                'published_by_id' => null,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return 'published';
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
