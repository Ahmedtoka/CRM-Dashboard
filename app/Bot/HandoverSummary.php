<?php

namespace App\Bot;

use App\Bot\Grounding\BotContext;
use App\Bot\Grounding\GovernorateMatcher;
use App\Enums\BotIntent;
use App\Models\Conversation;
use App\Models\ConversationNote;
use Illuminate\Support\Str;

/**
 * The internal note posted on every bot handover (spec §4.1): why, what the
 * customer wanted, and which products/sizes/colours/governorate were
 * mentioned — so the agent doesn't have to re-read the thread. Task 4 adds
 * the routing category/priority and the flow's own extra lines (collected
 * details, order snapshot line(s), cancel/edit window) so nothing structured
 * has to ride inside the free customer text anymore.
 */
final class HandoverSummary
{
    /** Categories from TurnRunner/IntentRouter that are not an intent key (spec §2.1/§4). */
    private const CATEGORY_LABELS = [
        'unclear' => 'مش واضح', 'repeated' => 'سؤال متكرر', 'angry_or_urgent' => 'غضب/إلحاح',
        'no_script' => 'لا يوجد رد جاهز', 'ai_error' => 'خطأ في البوت', 'window_closed' => 'نافذة الرد مقفولة',
        'order_not_found' => 'الأوردر مش موجود', 'delayed_order' => 'الأوردر متأخر', 'order_hold' => 'الأوردر متوقف للمراجعة',
        'order_returned' => 'الأوردر مرتجع', 'failed_delivery_attempt' => 'محاولة توصيل فشلت',
        'order_details_missing' => 'بيانات الأوردر ناقصة', 'new_order' => 'طلب أوردر جديد',
    ];

    private const PRIORITY_LABELS = ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية'];

    public function __construct(private readonly GovernorateMatcher $governorates) {}

    public static function reasonLabel(string $reason): string
    {
        return [
            'purchase' => 'العميلة عايزة تطلب', 'contact_details' => 'العميلة بعتت عنوان أو رقم تليفون', 'size_recommendation' => 'العميلة بتسأل عن مقاسها',
            'complaint' => 'شكوى', 'negative_sentiment' => 'العميلة متضايقة', 'order_status' => 'سؤال عن أوردر قائم', 'ai_low_confidence' => 'البوت مش متأكد من الرد',
            'keyword' => 'طلبت تكلم موظف', 'max_turns' => 'البوت رد كتير من غير ما يخلص', 'ai_guard' => 'الرد كان فيه أرقام مش موجودة في البيانات',
            'ai_error' => 'خطأ في البوت', 'window_closed' => 'نافذة الرد مقفولة', 'no_rule' => 'مفيش رد مناسب', 'rule' => 'قاعدة تحويل',
            'no_product_match' => 'المنتج مش لاقيه في الكتالوج',
            // IntentRouter/TurnRunner hand over with reason "intent"; the category line names which one.
            'intent' => 'طلب العميل',
        ][$reason] ?? $reason;
    }

    /**
     * The handover category label (an intent key, or one of TurnRunner's own categories).
     * The single labels source for the handover note, the inbox list/thread chip
     * (ConversationResource) and the ConversationUpdated broadcast.
     */
    public static function categoryLabel(string $category): string
    {
        if (isset(self::CATEGORY_LABELS[$category])) {
            return self::CATEGORY_LABELS[$category];
        }

        $label = self::intentLabels()[$category] ?? null;

        return $label !== null && $label !== '' ? $label : self::reasonLabel($category);
    }

    /**
     * key => label_ar for every intent, loaded once per request/job so a list of
     * conversations never runs a query per row. Labels are seeded, not editable
     * from the settings page, so the memo cannot serve a stale edit.
     *
     * @return array<string, string>
     */
    private static function intentLabels(): array
    {
        return once(fn () => \App\Models\BotIntent::query()->pluck('label_ar', 'key')->all());
    }

    public static function priorityLabel(string $priority): string
    {
        return self::PRIORITY_LABELS[$priority] ?? $priority;
    }

    /**
     * @param  list<string>  $summaryExtra  extra lines already formatted by the caller
     *                                      (collected details, order snapshot line(s), cancel/edit window)
     */
    public function note(Conversation $c, string $reason, string $customerText, ?BotIntent $intent, ?BotContext $ctx, array $summaryExtra = []): ConversationNote
    {
        $lines = ['🤖 تحويل من البوت', 'السبب: '.self::reasonLabel($reason)];

        if ($c->handover_category !== null && $c->handover_category !== '') {
            $lines[] = 'التصنيف: '.self::categoryLabel((string) $c->handover_category);
        }
        if ($c->priority_level !== null && $c->priority_level !== '') {
            $lines[] = 'الأولوية: '.self::priorityLabel((string) $c->priority_level);
        }
        if ($intent !== null) {
            $lines[] = 'النية: '.$intent->label();
        }
        if ($ctx?->products) {
            $lines[] = 'المنتجات: '.implode('، ', $ctx->products);
        }
        if ($ctx?->sizes) {
            $lines[] = 'المقاسات: '.implode('، ', $ctx->sizes);
        }
        if ($ctx?->colors) {
            $lines[] = 'الألوان: '.implode('، ', $ctx->colors);
        }
        if ($ctx?->governorate) {
            $lines[] = 'المحافظة: '.$this->governorates->name($ctx->governorate);
        }

        foreach ($summaryExtra as $extra) {
            if (trim($extra) !== '') {
                $lines[] = $extra;
            }
        }

        $lines[] = 'آخر رسالة: «'.Str::limit($customerText, 200).'»';

        return $c->notes()->create(['user_id' => null, 'body' => implode("\n", $lines)]);
    }
}
