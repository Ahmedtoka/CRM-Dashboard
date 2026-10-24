<?php

namespace App\Cases\Returns;

/**
 * What the customer is told at each step of a return/exchange (round 2026-10-24). Seeded as
 * `bot_knowledge_entries` rows keyed `script.<key>` like every FlowScripts entry, so the owner rewords or
 * switches any of them off from Settings → معرفة البوت. Egyptian Arabic, no emoji, and no promise of a time
 * the team does not control: the only date in them is the pickup day the moderator herself picked.
 *
 * Placeholders: {pickup_date} (Arabic day and month), {amount} (whole pounds), {order_number}, {tracking}
 * (a whole line, or empty), {reason}, {case_id}.
 */
final class ReturnScripts
{
    /** @return array<string, array{title:string, body:string}> */
    public static function all(): array
    {
        return [
            'return_pickup_scheduled' => ['title' => 'مرتجع: اتحدد يوم الاستلام', 'body' => "تم تحديد يوم {pickup_date} لاستلام القطعة من حضرتك، والمندوب هيتواصل مع حضرتك قبل ما يوصل.\nمن فضلك خلي القطعة بحالتها الأصلية ومعاها الفاتورة لو موجودة."],
            'return_picked_up' => ['title' => 'مرتجع: المندوب استلم القطعة ورد الفلوس', 'body' => 'المندوب استلم القطعة من حضرتك وتم رد مبلغ {amount} جنيه كاش مع المندوب. شكراً لتعاملك معانا.'],
            'exchange_picked_up' => ['title' => 'استبدال: المندوب استلم القطعة', 'body' => 'المندوب استلم القطعة من حضرتك، وأول ما القطعة الجديدة تتشحن هبعت لحضرتك إشعار هنا.'],
            'exchange_shipped' => ['title' => 'استبدال: القطعة الجديدة اتشحنت', 'body' => "القطعة الجديدة اتشحنت لحضرتك بأوردر رقم {order_number}.\n{tracking}"],
            'return_cancelled' => ['title' => 'مرتجع: الطلب اتلغى', 'body' => 'تم إلغاء طلب المرتجع رقم {case_id}، السبب: {reason}. لو عندك أي استفسار ابعتي لنا هنا.'],
        ];
    }

    /** The line that fills {tracking} when the exchange order has a carrier link, else nothing. */
    public static function trackingLine(?string $url): string
    {
        return filled($url) && preg_match('#^https?://#i', (string) $url) ? 'تقدري تتابعيها من هنا: '.$url : '';
    }
}
