<?php

use App\Bot\Flows\OwnerFlowsUpgrade;
use App\Bot\Flows\Steps\OrderItemsStep;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderItem;

function ppOrder(): Order
{
    $order = Order::factory()->create(['order_number' => '1047', 'shopify_order_name' => '#1047']);
    foreach ([['فستان ليلى', 850, 'https://cdn.shopify.com/s/files/a.webp'], ['عباية كتان', 1200, 'https://cdn.shopify.com/s/files/b.webp'], ['جيبة', 400, null]] as [$title, $price, $image]) {
        OrderItem::factory()->for($order)->create(['title' => $title, 'qty' => 1, 'price' => $price, 'image_url' => $image, 'variant_title' => 'أسود / M']);
    }

    return $order->load('items');
}

function ppState(Order $order, array $data = [], string $flow = 'return_exchange', string $step = 'return_items'): array
{
    return ['key' => $flow, 'step' => $step, 'retries' => 0, 'detours' => 0, 'data' => ['order_verified' => true, 'order_id' => $order->id, 'order_number' => '#1047'] + $data];
}

it('shows the pieces as picture cards with «رجّع القطعة دي», then the question with only «أرجع كله» (owner, 2026-09-26)', function () {
    $order = ppOrder();
    $c = Conversation::factory()->create(['channel_account_id' => ChannelAccount::factory()->create(['platform' => Platform::Facebook])->id, 'platform' => Platform::Facebook]);

    $outcome = app(OrderItemsStep::class)->enter($c, ppState($order, ['request_kind' => 'return']), ['type' => 'order_items', 'text' => 'اختاري القطعة اللي عايزة ترجعيها 👇']);

    expect($outcome->messages)->toHaveCount(2);
    [$cards, $question] = $outcome->messages;
    expect($cards['cards']['label'])->toBe('اختاري القطعة اللي عايزة ترجعيها 👇')
        ->and(count($cards['cards']['cards']))->toBe(3) // a piece without a photo still gets a card
        ->and($cards['cards']['cards'][0]['title'])->toBe('1. فستان ليلى')
        ->and($cards['cards']['cards'][0]['image_url'])->toContain('format=jpg')
        ->and($cards['cards']['cards'][0]['buttons'])->toBe([['type' => 'postback', 'title' => 'رجّع القطعة دي', 'payload' => 'step:return_exchange:return_items:item:'.$order->items[0]->id]])
        ->and(collect($cards['cards']['cards'])->pluck('title')->all())->not->toContain('🛍️ أرجع الأوردر كله')
        ->and($question['text'])->toBe('اختاري القطعة اللي عايزة ترجعيها — '.OrderItemsStep::PICTURES_HINT)
        ->and(array_column($question['buttons'], 'title'))->toBe(['أرجع كله']);
});

it('says «بدّل القطعة دي» for an exchange and «الشكوى عن القطعة دي» with «كل الأوردر» for a complaint', function () {
    $order = ppOrder();
    $c = Conversation::factory()->create(['channel_account_id' => ChannelAccount::factory()->create(['platform' => Platform::Facebook])->id, 'platform' => Platform::Facebook]);

    $exchange = app(OrderItemsStep::class)->enter($c, ppState($order, ['request_kind' => 'exchange'], 'return_exchange', 'exchange_items'), ['type' => 'order_items', 'text' => 'اختاري القطعة اللي عايزة تبدليها 👇']);
    expect($exchange->messages[0]['cards']['cards'][1]['buttons'][0]['title'])->toBe('بدّل القطعة دي')
        ->and(array_column($exchange->messages[1]['buttons'], 'title'))->toBe(['أبدل كله']);

    $complaint = app(OrderItemsStep::class)->enter($c, ppState($order, [], 'complaint', 'complaint_items'), ['type' => 'order_items', 'text' => OwnerFlowsUpgrade::COMPLAINT_ITEMS_TEXT, 'return_rules' => false, 'optional' => true, 'pick_button' => OwnerFlowsUpgrade::COMPLAINT_PICK_BUTTON]);
    expect($complaint->messages[0]['cards']['cards'][0]['buttons'][0]['title'])->toBe('الشكوى عن القطعة دي')
        ->and($complaint->messages[1]['text'])->toBe('الشكوى بخصوص أنهي قطعة؟ — '.OrderItemsStep::PICTURES_HINT)
        ->and(array_column($complaint->messages[1]['buttons'], 'title'))->toBe(['كل الأوردر']);
});

it('keeps the numbered text list with name buttons when no piece has a photo', function () {
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create(['title' => 'فستان', 'qty' => 1, 'price' => 850, 'image_url' => null]);
    OrderItem::factory()->for($order)->create(['title' => 'جيبة', 'qty' => 1, 'price' => 400, 'image_url' => null]);
    $c = Conversation::factory()->create(['channel_account_id' => ChannelAccount::factory()->create(['platform' => Platform::Facebook])->id, 'platform' => Platform::Facebook]);

    $outcome = app(OrderItemsStep::class)->enter($c, ppState($order->load('items'), ['request_kind' => 'return']), ['type' => 'order_items', 'text' => 'اختاري القطعة اللي عايزة ترجعيها 👇']);

    expect($outcome->messages)->toHaveCount(1)
        ->and($outcome->messages[0]['text'])->toContain('1. فستان')
        ->and(array_column($outcome->messages[0]['buttons'], 'title'))->toBe(['فستان', 'جيبة', 'أرجع كله']);
});
