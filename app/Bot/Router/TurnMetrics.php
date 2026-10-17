<?php

namespace App\Bot\Router;

/**
 * Where the time of one bot turn went (bot-hybrid.md §7 P0), copied onto the turn's bot run by
 * BotRun::creating. A scoped singleton: queue workers get a fresh one per job, and RunBotTurn
 * resets it at the start of every turn anyway.
 */
final class TurnMetrics
{
    public ?string $tier = null;

    public ?int $burstWaitMs = null;

    public ?int $plannerMs = null;

    public ?int $llmMs = null;

    public ?int $toolsMs = null;

    public ?int $rounds = null;

    public ?int $cacheReadTokens = null;

    public ?int $cacheWriteTokens = null;

    /** Unix ms when the turn started processing (the «ثانية واحدة» notice measures from here). */
    public ?int $startedAtMs = null;

    /** The running turn's id (BotHoldNotice::turnKey holds it while the turn runs). */
    public ?string $turn = null;

    /** The newest message id when the turn started: anything after it answered her. */
    public ?int $lastMessageId = null;

    /** «ثانية واحدة» is armed at most once per turn. */
    public bool $holdArmed = false;

    public function reset(): void
    {
        foreach (get_object_vars($this) as $key => $_) {
            $this->{$key} = $key === 'holdArmed' ? false : null;
        }
    }

    public function addPlanner(int $ms): void
    {
        $this->plannerMs = ($this->plannerMs ?? 0) + $ms;
    }

    /** One model round: its latency and the cache part of its `usage`. */
    public function addCall(int $ms, array $usage): void
    {
        $this->llmMs = ($this->llmMs ?? 0) + $ms;
        $this->rounds = ($this->rounds ?? 0) + 1;
        $this->cacheReadTokens = ($this->cacheReadTokens ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0);
        $this->cacheWriteTokens = ($this->cacheWriteTokens ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0);
    }

    public function addTools(int $ms): void
    {
        $this->toolsMs = ($this->toolsMs ?? 0) + $ms;
    }

    /** @return array<string, int|string> only what was measured */
    public function columns(): array
    {
        return array_filter([
            'tier' => $this->tier,
            'burst_wait_ms' => $this->burstWaitMs,
            'planner_ms' => $this->plannerMs,
            'llm_ms' => $this->llmMs,
            'tools_ms' => $this->toolsMs,
            'rounds' => $this->rounds,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
        ], fn ($v) => $v !== null);
    }
}
