<?php

use App\Bot\Flow\IntentCatalog;
use App\Bot\Flow\IntentRouter;
use App\Bot\Flow\Understanding;
use App\Models\BotIntent;

function cat(): IntentCatalog
{
    return IntentCatalog::fromCollection(collect([
        new BotIntent(['key' => 'price', 'route' => 'answer', 'priority' => 'low', 'script_keys' => ['price']]),
        new BotIntent(['key' => 'delivery_time', 'route' => 'answer', 'priority' => 'low', 'script_keys' => ['delivery_time']]),
        new BotIntent(['key' => 'order_status', 'route' => 'lookup', 'priority' => 'medium', 'queue' => 'agents', 'script_keys' => ['tracking_order'], 'required_details' => ['order_ref|phone|email']]),
        new BotIntent(['key' => 'cancel_order', 'route' => 'collect_then_handover', 'priority' => 'medium', 'queue' => 'agents', 'script_keys' => ['cancel_order'], 'required_details' => ['order_ref|phone|email']]),
        new BotIntent(['key' => 'store_complaint', 'route' => 'collect_then_handover', 'priority' => 'high', 'queue' => 'senior', 'script_keys' => ['branch_complaint']]),
        new BotIntent(['key' => 'delivery_problem', 'route' => 'handover', 'priority' => 'high', 'queue' => 'agents']),
    ]));
}

function u(array $keys, array $extra = []): Understanding
{
    return new Understanding(array_map(fn ($k) => ['key' => $k, 'confidence' => $extra['confidence'] ?? 0.9], $keys), $extra['entities'] ?? [], $extra['sentiment'] ?? 'neutral', $extra['urgent'] ?? false, $extra['unclear'] ?? false, 'ar');
}

it('drops lookup intents when a collect intent already gathers the order details', function () {
    $plan = (new IntentRouter)->plan(u(['cancel_order', 'order_status', 'delivery_time']), cat(), [], 0.6);

    expect($plan->collectIntent?->key)->toBe('cancel_order')
        ->and($plan->lookupIntents)->toBe([])
        ->and(array_map(fn ($i) => $i->key, $plan->answerIntents))->toBe(['delivery_time']);
});

it('answers several low intents in one plan', function () {
    $p = (new IntentRouter)->plan(u(['price', 'delivery_time']), cat(), [], 0.6);
    expect(collect($p->answerIntents)->pluck('key')->all())->toBe(['price', 'delivery_time'])->and($p->handover)->toBeNull();
});

it('hands over with the highest priority and still answers the rest', function () {
    $p = (new IntentRouter)->plan(u(['price', 'delivery_problem']), cat(), [], 0.6);
    expect($p->handover)->toMatchArray(['priority' => 'high', 'queue' => 'agents', 'category' => 'delivery_problem'])
        ->and(collect($p->answerIntents)->pluck('key')->all())->toBe(['price']);
});

it('collects before handing over and routes store complaints to senior', function () {
    $p = (new IntentRouter)->plan(u(['store_complaint']), cat(), [], 0.6);
    expect($p->collectIntent->key)->toBe('store_complaint')->and($p->collectIntent->queue)->toBe('senior')->and($p->handover)->toBeNull();
});

it('keeps the higher-priority collect intent', function () {
    $p = (new IntentRouter)->plan(u(['cancel_order', 'store_complaint']), cat(), [], 0.6);
    expect($p->collectIntent->key)->toBe('store_complaint');
});

it('escalates angry or urgent customers to high', function () {
    $p = (new IntentRouter)->plan(u(['price'], ['sentiment' => 'negative', 'urgent' => true]), cat(), [], 0.6);
    expect($p->handover['priority'])->toBe('high')->and($p->handover['category'])->toBe('angry_or_urgent')
        ->and(collect($p->answerIntents)->pluck('key')->all())->toBe(['price']);
});

it('clarifies twice when unclear, then hands over', function () {
    $first = (new IntentRouter)->plan(u([], ['unclear' => true]), cat(), [], 0.6);
    $second = (new IntentRouter)->plan(u([], ['unclear' => true]), cat(), ['clarify_count' => 1], 0.6);
    $third = (new IntentRouter)->plan(u([], ['unclear' => true]), cat(), ['clarify_count' => 2], 0.6);
    $legacy = (new IntentRouter)->plan(u([], ['unclear' => true]), cat(), ['clarified' => true], 0.6);

    expect($first->clarify)->toBeTrue()->and($first->handover)->toBeNull()
        ->and($second->clarify)->toBeTrue()->and($second->handover)->toBeNull()
        ->and($third->handover)->toMatchArray(['priority' => 'medium', 'category' => 'unclear'])
        ->and($legacy->clarify)->toBeTrue();
});

it('ignores unknown keys and low-confidence intents', function () {
    $p = (new IntentRouter)->plan(u(['nope', 'price'], ['confidence' => 0.3]), cat(), [], 0.6);
    expect($p->answerIntents)->toBe([])->and($p->clarify)->toBeTrue();
});

it('plans a lookup for order status', function () {
    $p = (new IntentRouter)->plan(u(['order_status'], ['entities' => ['order_ref' => '1234']]), cat(), [], 0.6);
    expect(collect($p->lookupIntents)->pluck('key')->all())->toBe(['order_status']);
});

it('hands over when the same intents keep repeating', function () {
    $p = (new IntentRouter)->plan(u(['price']), cat(), ['last_intents' => ['price'], 'repeat_count' => 2], 0.6);
    expect($p->handover)->toMatchArray(['priority' => 'medium', 'queue' => 'agents', 'category' => 'repeated']);
});
