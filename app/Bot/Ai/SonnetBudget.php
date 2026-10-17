<?php

namespace App\Bot\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * The daily Sonnet spend cap (owner B3, 2026-10-17: $5 to start). Every Sonnet call adds its cost
 * (ModelPricing) to a per-day counter (Cairo day, in millionths of a dollar so the cache's atomic
 * increment works); once the day's spend reaches `crm.bot.router.daily_sonnet_cap_usd`, the
 * router and the store agent run on Haiku until midnight.
 */
class SonnetBudget
{
    public function charge(string $model, array $usage): void
    {
        if (! str_starts_with($model, 'claude-sonnet')) {
            return;
        }

        $micro = (int) round(ModelPricing::ofUsage($model, $usage) * 1_000_000);

        if ($micro <= 0) {
            return;
        }

        $key = $this->key();
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key, $micro);
    }

    public function spentToday(): float
    {
        return ((int) Cache::get($this->key(), 0)) / 1_000_000;
    }

    public function exceeded(): bool
    {
        $cap = (float) config('crm.bot.router.daily_sonnet_cap_usd', 5);

        return $cap >= 0 && $this->spentToday() >= $cap;
    }

    public function key(): string
    {
        return 'bot:sonnet-spend:'.now('Africa/Cairo')->toDateString();
    }
}
