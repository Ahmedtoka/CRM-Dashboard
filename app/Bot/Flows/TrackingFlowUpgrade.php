<?php

namespace App\Bot\Flows;

use App\Bot\Flows\Steps\StatusStep;

/**
 * The owner's order-tracking flow (2026-09-19, dictated in chat), published as a
 * new version of `order_tracking`:
 *
 *   order (number → last 4 digits of the mobile; mobile/email → shown directly;
 *          several open orders → one button each)
 *   → status card (StatusStep): [تمام شكرًا] [الأوردر اتأخر] [عايزة ألغي/أعدل] [كلم موظف],
 *     a delivered/cancelled order: [تمام شكرًا] [عايزة أرجع أو أبدل] [كلم موظف]
 *       تمام شكرًا       → thanks, end
 *       الأوردر اتأخر    → past the window (or a failed attempt, held, returned): a
 *                          `delivery_followup` case; else "لسه في معاده" + the window
 *       عايزة ألغي/أعدل  → flow:cancel_edit with the order carried (not asked again)
 *       عايزة أرجع أو أبدل → flow:return_exchange with the order carried
 *       كلم موظف         → a person
 *   not found twice → [كلم موظف] [القائمة الرئيسية].
 *
 * Nothing is recorded unless she says the order is late. Replaces the seed's
 * order → status, whose status step opened a follow-up case by itself.
 * Publishing follows ReturnFlowUpgrade (the live version and any draft archived,
 * idempotent).
 */
final class TrackingFlowUpgrade
{
    public const FLOW_KEY = 'order_tracking';

    public const NOTE = 'فلو متابعة الأوردر الجديد: كارت الحالة وميعاد التوصيل، ومتابعة التأخير بس لما العميلة تطلب';

    public const ASK_TEXT = 'ممكن رقم الأوردر أو رقم الموبايل اللي اتعمل بيه الأوردر؟ 🌸';

    public const THANKS_TEXT = 'العفو 🌸 لو احتجتي أي حاجة أنا موجودة';

    public const LATE_RECORDED_TEXT = 'سجلت طلب متابعة للأوردر #{order_number} 🌸 الفريق هيتابع مع شركة الشحن ويرد عليكي في أقرب وقت';

    public const ON_TIME_TEXT = 'الأوردر لسه في معاده 🌸 متوقع يوصل من {order_eta}، ولو اتأخر عن كده ابعتيلي وهتابعه فورًا';

    public const NOT_FOUND_TEXT = 'للأسف مش لاقية الأوردر 🙏 تحبي أحولك لحد من الفريق يتابعه معاكي؟';

    /** Every status key an order found by the `order` step carries (OrderSnapshot::$statusKey). */
    public const STATUS_KEYS = ['confirmed', 'prepared', 'shipped', 'on_the_way', 'delivered', 'cancelled', 'hold', 'returned'];

    public static function definition(): array
    {
        $thanks = ['value' => 'thanks', 'title' => 'تمام شكرًا', 'synonyms' => ['تمام', 'شكرا', 'متشكرة', 'ميرسي', 'اوك', 'ok'], 'next' => 'thanks'];
        $agent = ['value' => 'agent', 'title' => 'كلم موظف', 'synonyms' => ['موظف', 'حد يكلمني', 'خدمة العملاء', 'حوليني']];

        return ['start' => 'order', 'steps' => [
            'order' => ['type' => 'order', 'field' => 'order', 'text' => self::ASK_TEXT, 'verify_owner' => true, 'branches' => [
                ['field' => 'order_status_key', 'in' => self::STATUS_KEYS, 'next' => 'status'],
            ], 'next' => 'not_found'],
            'status' => ['type' => 'status', 'field' => 'tracking_choice', 'text' => StatusStep::CARD_TEXT, 'options' => [
                $thanks,
                ['value' => 'late', 'title' => 'الأوردر اتأخر', 'synonyms' => ['اتأخر', 'اتاخر', 'متأخر', 'متاخر', 'لسه موصلش', 'موصلش', 'مجاش', 'لسه مجاش'], 'when' => 'open'],
                ['value' => 'cancel_edit', 'title' => 'عايزة ألغي/أعدل', 'synonyms' => ['الغي', 'الغاء', 'الغيه', 'اعدل', 'تعديل', 'اغير'], 'when' => 'open', 'action' => 'flow:cancel_edit'],
                ['value' => 'return_exchange', 'title' => 'عايزة أرجع أو أبدل', 'synonyms' => ['ارجع', 'مرتجع', 'استرجاع', 'ابدل', 'استبدال', 'مشكلة'], 'when' => 'finished', 'action' => 'flow:return_exchange'],
                $agent + ['action' => 'handover'],
            ], 'branches' => [
                // «الأوردر اتأخر» has no step of its own: StatusStep set `order_late` on entry.
                ['field' => 'order_late', 'in' => ['overdue'], 'next' => 'late_record'],
                ['field' => 'order_late', 'in' => ['on_time'], 'next' => 'late_ok'],
            ], 'next' => 'end'],
            'thanks' => ['type' => 'script', 'text' => self::THANKS_TEXT, 'next' => 'end'],
            'late_record' => ['type' => 'record_case', 'case_type' => 'delivery_followup', 'text' => self::LATE_RECORDED_TEXT, 'next' => 'end'],
            'late_ok' => ['type' => 'choice', 'field' => 'late_choice', 'text' => self::ON_TIME_TEXT, 'options' => [
                $thanks,
                $agent + ['next' => 'agent'],
            ]],
            'not_found' => ['type' => 'choice', 'field' => 'not_found_choice', 'text' => self::NOT_FOUND_TEXT, 'options' => [
                $agent + ['next' => 'agent'],
            ]],
            'agent' => ['type' => 'handover'],
        ]];
    }

    /** The tracking definition as seeded on 2026-09-17. */
    public static function legacyDefinition(): array
    {
        return ['start' => 'order', 'steps' => [
            'order' => ['type' => 'order', 'field' => 'order', 'text' => self::ASK_TEXT, 'next' => 'status'],
            'status' => ['type' => 'status', 'next' => 'end'],
        ]];
    }

    /** @return 'published'|'skipped' */
    public static function run(): string
    {
        return ReturnFlowUpgrade::publish(self::FLOW_KEY, self::definition(), self::NOTE);
    }
}
