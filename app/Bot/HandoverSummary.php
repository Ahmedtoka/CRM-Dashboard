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
    private const CATEGORY_KEYS = [
        'unclear', 'repeated', 'angry_or_urgent', 'no_script', 'ai_error', 'window_closed',
        'order_not_found', 'delayed_order', 'order_hold', 'order_returned', 'failed_delivery_attempt',
        'order_details_missing', 'new_order', 'order_verification_failed',
    ];

    private const PRIORITY_KEYS = ['low', 'medium', 'high'];

    /**
     * Handover reasons. IntentRouter/TurnRunner hand over with reason "intent";
     * the category line names which one.
     */
    private const REASON_KEYS = [
        'purchase', 'contact_details', 'size_recommendation', 'complaint', 'negative_sentiment',
        'order_status', 'ai_low_confidence', 'keyword', 'max_turns', 'ai_guard', 'ai_error',
        'window_closed', 'no_rule', 'rule', 'no_product_match', 'intent',
    ];

    public function __construct(private readonly GovernorateMatcher $governorates) {}

    public static function reasonLabel(string $reason): string
    {
        return in_array($reason, self::REASON_KEYS, true) ? __('cases.handover.reasons.'.$reason) : $reason;
    }

    /**
     * The handover category label (an intent key, or one of TurnRunner's own categories).
     * The single labels source for the handover note, the inbox list/thread chip
     * (ConversationResource) and the ConversationUpdated broadcast.
     */
    public static function categoryLabel(string $category): string
    {
        if (in_array($category, self::CATEGORY_KEYS, true)) {
            return __('cases.handover.categories.'.$category);
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
        return in_array($priority, self::PRIORITY_KEYS, true) ? __('cases.priority.'.$priority) : $priority;
    }

    /**
     * @param  list<string>  $summaryExtra  extra lines already formatted by the caller
     *                                      (collected details, order snapshot line(s), cancel/edit window)
     */
    public function note(Conversation $c, string $reason, string $customerText, ?BotIntent $intent, ?BotContext $ctx, array $summaryExtra = []): ConversationNote
    {
        // The note is a historical record the bot writes once, so its labels stay Arabic
        // whatever the request locale is; the resource path uses the same accessors translated.
        $locale = app()->getLocale();
        app()->setLocale('ar');

        try {
            $lines = ['🤖 تحويل من البوت', 'السبب: '.self::reasonLabel($reason)];

            if ($c->handover_category !== null && $c->handover_category !== '') {
                $lines[] = 'التصنيف: '.self::categoryLabel((string) $c->handover_category);
            }
            if ($c->priority_level !== null && $c->priority_level !== '') {
                $lines[] = 'الأولوية: '.self::priorityLabel((string) $c->priority_level);
            }
        } finally {
            app()->setLocale($locale);
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
