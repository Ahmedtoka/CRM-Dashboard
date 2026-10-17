<?php

namespace App\Bot\Agent;

use App\Bot\OrderFactGuard;
use App\Bot\PriceGuard;
use App\Bot\PromiseGuard;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;

/**
 * Every model reply passes these before it is sent (bot-hybrid.md §3.6): every price-like number,
 * promise, link and order fact must come from a tool result, the owner's knowledge or the store
 * link (her own words count for an order number she wrote). Returns the failed guard, or null.
 */
class ReplyGuards
{
    public function __construct(
        private readonly PriceGuard $prices,
        private readonly PromiseGuard $promises,
        private readonly OrderFactGuard $orders,
    ) {}

    /** @param  list<string>  $customerTexts */
    public function failure(AgentOutcome $o, array $customerTexts = []): ?string
    {
        if ($o->reply === '') {
            return null;
        }

        $knowledge = BotKnowledgeEntry::query()->where('is_active', true)->pluck('body')->all();
        $evidence = [...$o->evidence, ...$knowledge, BotSetting::current()->storeUrl()];

        if (! $this->prices->isSafe($o->reply, $evidence)) {
            return 'price';
        }

        if (! $this->promises->isSafe($o->reply, $evidence)) {
            return 'promise';
        }

        preg_match_all('~https?://[^\s<>"«»]+~u', $o->reply, $m);
        $haystack = implode("\n", $evidence);

        foreach ($m[0] as $url) {
            if (! str_contains($haystack, rtrim($url, '.,،؛)'))) {
                return 'link';
            }
        }

        if (! $this->orders->isSafe($o->reply, $evidence, $customerTexts, $o->toolsUsed)) {
            return 'order_fact';
        }

        return null;
    }
}
