<?php

namespace App\Bot\Agent;

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

    public function __construct()
    {
        $this->products = collect();
    }
}
