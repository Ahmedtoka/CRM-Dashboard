<?php

use App\Bot\Flows\FlowDefinitionSource;
use App\Bot\Flows\FlowPrompter;
use App\Bot\Flows\PublishedFlowDefinitions;
use App\Models\BotFlow;

/**
 * Counts calls per flow key so a test can assert a definition source is
 * consulted at most once per key for a given FlowPrompter instance,
 * regardless of how many times visibleMenuOptions() runs against it.
 */
final class CountingFlowDefinitionSource implements FlowDefinitionSource
{
    /** @var array<string, int> */
    public array $calls = [];

    public function __construct(private readonly FlowDefinitionSource $inner) {}

    public function definition(string $flowKey): ?array
    {
        $this->calls[$flowKey] = ($this->calls[$flowKey] ?? 0) + 1;

        return $this->inner->definition($flowKey);
    }
}

it('memoizes flow/menu target definitions per instance instead of re-resolving them every call', function () {
    $step = BotFlow::active('main_menu')->definition['steps']['menu'];

    $targets = collect($step['options'])
        ->map(fn ($o) => (string) ($o['action'] ?? ''))
        ->filter(fn ($a) => str_starts_with($a, 'flow:') || str_starts_with($a, 'menu:'))
        ->map(fn ($a) => substr($a, (int) strpos($a, ':') + 1))
        ->values();

    // main_menu's return_exchange/order_tracking/complaint/branches/products/cancel_edit options.
    expect($targets)->toHaveCount(6);

    $counting = new CountingFlowDefinitionSource(new PublishedFlowDefinitions);
    app()->instance(FlowDefinitionSource::class, $counting);

    /** @var FlowPrompter $prompter */
    $prompter = app(FlowPrompter::class);

    // A single live menu turn calls visibleMenuOptions() several times (the prompt
    // itself, the AI-interpreter fallback, matching a typed synonym, applying a
    // button/typed answer) - simulate that here against the same instance.
    for ($i = 0; $i < 4; $i++) {
        // 7 options total: the 6 flow:/menu: targets plus the always-visible "كلم موظف" (handover).
        expect($prompter->visibleMenuOptions($step))->toHaveCount(7);
    }

    foreach ($targets as $key) {
        expect($counting->calls[$key] ?? 0)->toBe(1);
    }
});

it('still reflects a flow\'s current active state, since each request/turn resolves a fresh prompter', function () {
    $step = BotFlow::active('main_menu')->definition['steps']['menu'];

    BotFlow::where('key', 'complaint')->update(['is_active' => false]);

    // A new instance (as a new request/sandbox run would resolve) picks up the change;
    // the memoization added above is per-instance only, never shared across requests.
    $prompter = app(FlowPrompter::class);
    expect(collect($prompter->visibleMenuOptions($step))->pluck('action'))->not->toContain('flow:complaint');
});
