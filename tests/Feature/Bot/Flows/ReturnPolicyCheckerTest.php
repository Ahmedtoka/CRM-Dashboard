<?php

use App\Bot\Flows\ReturnPolicyChecker;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;

it('notes an order placed more than 14 days plus delivery ago', function () {
    $o = Order::factory()->create(['placed_at' => now()->subDays(30)]);
    OrderItem::factory()->for($o)->create(['title' => 'فستان', 'discount' => 0]);

    $notes = app(ReturnPolicyChecker::class)->notes(['order_id' => $o->id, 'request' => 'exchange']);

    expect($notes)->toBe(['غالبًا عدى 14 يوم من الاستلام (الأوردر بتاريخ '.$o->placed_at->timezone('Africa/Cairo')->format('d/m').')']);
});

it('does not note a recent order', function () {
    $o = Order::factory()->create(['placed_at' => now()->subDays(10)]);
    OrderItem::factory()->for($o)->create(['title' => 'فستان', 'discount' => 0]);

    expect(app(ReturnPolicyChecker::class)->notes(['order_id' => $o->id, 'request' => 'refund']))->toBe([]);
});

it('notes an excluded item', function () {
    $o = Order::factory()->create(['placed_at' => now()->subDays(2)]);
    OrderItem::factory()->for($o)->create(['title' => 'بونيه قطن', 'discount' => 0]);
    OrderItem::factory()->for($o)->create(['title' => 'Portable Isdal', 'discount' => 0]);
    OrderItem::factory()->for($o)->create(['title' => 'فستان', 'discount' => 0]);

    expect(app(ReturnPolicyChecker::class)->notes(['order_id' => $o->id]))->toBe([
        'فيه منتج غالبًا غير قابل للاستبدال أو الاسترجاع: بونيه قطن',
        'فيه منتج غالبًا غير قابل للاستبدال أو الاسترجاع: Portable Isdal',
    ]);
});

it('notes a discounted item only for a refund request', function () {
    $o = Order::factory()->create(['placed_at' => now()->subDays(2)]);
    OrderItem::factory()->for($o)->create(['title' => 'فستان', 'price' => 500, 'discount' => 100]);

    expect(app(ReturnPolicyChecker::class)->notes(['order_id' => $o->id, 'request' => 'refund']))->toBe(['القطعة عليها خصم: متاح استبدال فقط'])
        ->and(app(ReturnPolicyChecker::class)->notes(['order_id' => $o->id, 'request' => 'exchange']))->toBe([]);
});

it('returns no notes without an order', function () {
    expect(app(ReturnPolicyChecker::class)->notes(['order_ref_text' => 'مش فاكرة']))->toBe([])
        ->and(app(ReturnPolicyChecker::class)->notes(['order_id' => 999999]))->toBe([]);
});

it('treats an item sold under its variant compare-at price as discounted', function () {
    $o = Order::factory()->create(['placed_at' => now()->subDays(2)]);
    $variant = ProductVariant::factory()->create(['price' => 400, 'compare_at_price' => 600]);
    OrderItem::factory()->for($o)->create(['title' => 'فستان', 'price' => 400, 'discount' => 0, 'variant_id' => $variant->id]);

    expect(app(ReturnPolicyChecker::class)->notes(['order_id' => $o->id, 'request' => 'refund']))->toBe(['القطعة عليها خصم: متاح استبدال فقط']);
});
