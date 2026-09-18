<?php

use App\Bot\RuleEngine;
use App\Enums\Platform;
use App\Models\BotRule;

it('matches by priority, scope and platform', function () {
    BotRule::query()->delete(); // isolate from the default knowledge rules seeded by migration
    BotRule::factory()->create(['name' => 'price', 'priority' => 10, 'scope' => 'both', 'platforms' => [], 'match_type' => 'any_keyword', 'keywords' => ['بكام', 'سعر'], 'is_active' => true]);
    BotRule::factory()->create(['name' => 'ig-only', 'priority' => 20, 'scope' => 'comment', 'platforms' => ['instagram'], 'match_type' => 'any_keyword', 'keywords' => ['بكام'], 'is_active' => true]);
    $e = app(RuleEngine::class);
    expect($e->match('بِكام ده؟', 'comment', Platform::Instagram)->name)->toBe('ig-only')
        ->and($e->match('بكام ده؟', 'message', Platform::Instagram)->name)->toBe('price')
        ->and($e->match('صباح الخير', 'message', Platform::Facebook))->toBeNull();
});
