<?php

namespace App\Bot\Agent;

use App\Bot\Ai\ModelPricing;
use App\Models\Product;
use Illuminate\Support\Collection;

/** What one agent turn decided: the reply, and the side effects its tools asked for. */
final class AgentOutcome
{
    /** @var Collection<int, Product> products the customer should see as picture cards */
    public Collection $products;

    /** Branch cards value (OutboundCards generic) when branches were looked up. */
    public ?array $branchCards = null;

    /** The guided flow to start after the reply. */
    public ?string $flow = null;

    /** @var array{reason:string, summary:string}|null */
    public ?array $handover = null;

    /** @var list<string> everything the tools returned: the only evidence a number or a link may come from */
    public array $evidence = [];

    /** @var list<string> tool names in call order (recorded on the bot run) */
    public array $toolsUsed = [];

    public string $reply = '';

    public string $model = '';

    public int $inputTokens = 0;

    /** Input tokens weighted by what they cost (cache reads 0.1x, cache writes 1.25x). */
    public float $billedInputTokens = 0;

    public int $outputTokens = 0;

    /** Bot hybrid: the tier that answered ('1', '2' or 'legacy'). */
    public string $tier = 'legacy';

    /** The reason when tier 1 asked for tier 2 (the `escalate` tool, E5). */
    public ?string $escalate = null;

    /** stop_reason was `refusal` (nothing may be sent). */
    public bool $refused = false;

    /** stop_reason was `max_tokens` (the answer was cut off; nothing may be sent). */
    public bool $truncated = false;

    /** USD of every round, cache reads and writes at their own price. */
    public float $costUsd = 0;

    /** Adds one round's `usage` (also keeps the older token counts used by the bot run). */
    public function addUsage(string $model, array $usage): void
    {
        $read = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $write = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $this->inputTokens += (int) ($usage['input_tokens'] ?? 0) + $read + $write;
        $this->billedInputTokens += (int) ($usage['input_tokens'] ?? 0) + 0.1 * $read + 1.25 * $write;
        $this->outputTokens += (int) ($usage['output_tokens'] ?? 0);
        $this->costUsd += ModelPricing::ofUsage($model, $usage);
    }

    public function __construct()
    {
        $this->products = collect();
    }
}
