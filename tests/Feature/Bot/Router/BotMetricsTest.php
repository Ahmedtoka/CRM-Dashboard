<?php

use App\Bot\Ai\ModelPricing;
use App\Bot\Router\TurnMetrics;
use App\Models\BotRun;

it('prices the current models from the claude-api price table, cache reads and writes included', function () {
    // Sonnet 5 was configured at $3/$15; the real price is $2/$10.
    expect(ModelPricing::cost('claude-sonnet-5', 1_000_000, 1_000_000))->toBe(12.0)
        ->and(ModelPricing::cost('claude-sonnet-5-5', 1_000_000, 0, cacheRead: 1_000_000, cacheWrite: 1_000_000))->toBe(4.7)
        ->and(ModelPricing::cost('claude-haiku-5-5', 1_000_000, 1_000_000, cacheRead: 1_000_000, cacheWrite: 1_000_000))->toBe(0.735)
        ->and(ModelPricing::cost('unknown-model', 1000, 1000))->toBe(0.0);
});

it('stores the turn timings and cache counts on the bot run of the turn', function () {
    $m = app(TurnMetrics::class);
    $m->reset();
    $m->burstWaitMs = 3100;
    $m->tier = '1';
    $m->addPlanner(800);
    $m->addCall(1200, ['input_tokens' => 10, 'cache_read_input_tokens' => 4000, 'cache_creation_input_tokens' => 50]);
    $m->addCall(900, ['cache_read_input_tokens' => 4100]);
    $m->addTools(150);

    $run = BotRun::create(['engine' => 'agent', 'decision' => 'reply']);

    expect($run->refresh()->only(['tier', 'planner_ms', 'llm_ms', 'tools_ms', 'rounds', 'cache_read_tokens', 'cache_write_tokens', 'burst_wait_ms']))
        ->toBe(['tier' => '1', 'planner_ms' => 800, 'llm_ms' => 2100, 'tools_ms' => 150, 'rounds' => 2, 'cache_read_tokens' => 8100, 'cache_write_tokens' => 50, 'burst_wait_ms' => 3100]);
});

it('keeps a tiny Haiku cost instead of rounding it to zero', function () {
    $run = BotRun::create(['engine' => 'agent', 'decision' => 'reply', 'cost_usd' => 0.000172]);

    expect((float) $run->refresh()->cost_usd)->toBe(0.000172);
});

it('defaults the burst wait to 3 s and the max to 12 s', function () {
    $source = file_get_contents(base_path('config/crm.php'));

    expect($source)->toContain("env('CRM_BOT_BURST_WAIT_SECONDS', 3)")
        ->and($source)->toContain("env('CRM_BOT_MAX_WAIT_SECONDS', 12)");
});
