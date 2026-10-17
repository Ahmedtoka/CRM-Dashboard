<?php

namespace App\Bot\Agent;

use App\Bot\Ai\SonnetBudget;

/**
 * How the store agent runs for one tier (bot-hybrid.md §3.4–3.5): the model, its effort, the
 * output cap (thinking counts against it), how many tool rounds, the per-call timeout and the
 * turn's whole budget. Thinking is always `adaptive` (never `disabled`: a 400 on Sonnet 5.5).
 *
 *   tier 1  Haiku 5.5, effort low, 3 rounds, 6 s per call, 8 s per turn
 *   tier 2  Sonnet 5.5, effort medium, 4 rounds, 12 s per call, 15 s per turn
 *   legacy  the store agent as configured (crm.bot.agent.*), Sonnet 5.5 by default
 *
 * A Sonnet profile past the day's spend cap (owner B3) becomes tier 1.
 */
final class TierProfile
{
    public function __construct(
        public readonly string $tier,
        public readonly string $model,
        public readonly string $effort,
        public readonly int $maxTokens,
        public readonly int $rounds,
        public readonly int $callTimeout,
        public readonly int $budgetSeconds,
    ) {}

    public static function tier(string $tier): self
    {
        $c = (array) config('crm.bot.router.tiers.'.$tier, []);
        $profile = new self(
            $tier,
            (string) ($c['model'] ?? 'claude-haiku-5-5'),
            (string) ($c['effort'] ?? 'low'),
            (int) ($c['max_tokens'] ?? 1500),
            (int) ($c['rounds'] ?? 3),
            (int) ($c['timeout'] ?? 6),
            (int) ($c['budget_seconds'] ?? 8),
        );

        return $profile->withinBudget();
    }

    public static function legacy(): self
    {
        return (new self(
            'legacy',
            (string) config('crm.bot.agent.model', 'claude-sonnet-5-5'),
            (string) config('crm.bot.agent.effort', 'medium'),
            1500,
            StoreAgent::MAX_ROUNDS,
            (int) config('crm.bot.agent.timeout', 25),
            (int) config('crm.bot.agent.budget_seconds', 40),
        ))->withinBudget();
    }

    public function isSonnet(): bool
    {
        return str_starts_with($this->model, 'claude-sonnet');
    }

    /** Owner B3: once the day's Sonnet spend is used up, everything runs on tier 1 (Haiku). */
    private function withinBudget(): self
    {
        if (! $this->isSonnet() || ! app(SonnetBudget::class)->exceeded()) {
            return $this;
        }

        return $this->tier === '1' ? $this : self::tier('1');
    }
}
