<?php

use App\Enums\OrderSource;
use App\Events\OrderUpdated;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;

function syncFieldsPayload(Order $order): array
{
    return (new OrderResource($order->fresh()))->resolve(Request::create('/'));
}

it('exposes the sync fields as ISO times on a Shopify order', function () {
    $order = Order::factory()->create([
        'source' => OrderSource::Store,
        'shopify_order_id' => '123',
        'last_synced_at' => '2026-10-01 10:00:00',
        'shopify_updated_at' => '2026-10-01 09:30:00',
        'placed_at' => '2026-09-30 08:00:00',
        'last_error' => 'old error',
    ]);

    $data = syncFieldsPayload($order);

    expect($data)->toHaveKeys(['last_synced_at', 'shopify_updated_at', 'placed_at', 'updated_at', 'on_shopify', 'last_error', 'note'])
        ->and($data['on_shopify'])->toBeTrue()
        ->and($data['last_synced_at'])->toBe($order->fresh()->last_synced_at->toIso8601String())
        ->and($data['shopify_updated_at'])->toBe($order->fresh()->shopify_updated_at->toIso8601String())
        ->and($data['placed_at'])->toBe($order->fresh()->placed_at->toIso8601String())
        ->and($data['updated_at'])->toBe($order->fresh()->updated_at->toIso8601String())
        ->and($data['last_synced_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/')
        // The last submission error only matters while the order is not on Shopify.
        ->and($data['last_error'])->toBeNull();
});

it('shows the last error and no sync times for an order not on Shopify yet', function () {
    $order = Order::factory()->create(['source' => OrderSource::Chat, 'shopify_order_id' => null, 'last_error' => 'Shopify timed out']);

    $data = syncFieldsPayload($order);

    expect($data['on_shopify'])->toBeFalse()
        ->and($data['last_error'])->toBe('Shopify timed out')
        ->and($data['last_synced_at'])->toBeNull()
        ->and($data['shopify_updated_at'])->toBeNull()
        ->and($data['placed_at'])->toBeNull()
        ->and($data['updated_at'])->toBeString();
});

it('marks final orders with the same rule as the openForSync scope and carries the Shopify name', function () {
    $open = Order::factory()->create(['shopify_order_id' => '1', 'shopify_order_name' => '#1381', 'financial_status' => 'paid', 'fulfillment_status' => null]);
    $cancelled = Order::factory()->create(['shopify_order_id' => '2', 'cancelled_at' => now()]);
    $refunded = Order::factory()->create(['shopify_order_id' => '3', 'financial_status' => 'refunded']);
    $delivered = Order::factory()->create(['shopify_order_id' => '4', 'fulfillment_status' => 'fulfilled', 'shipment_status' => 'delivered']);
    $fulfilledOnly = Order::factory()->create(['shopify_order_id' => '5', 'fulfillment_status' => 'fulfilled', 'shipment_status' => 'in_transit']);

    expect(syncFieldsPayload($open))->toMatchArray(['is_final' => false, 'shopify_order_name' => '#1381'])
        ->and(syncFieldsPayload($cancelled)['is_final'])->toBeTrue()
        ->and(syncFieldsPayload($refunded)['is_final'])->toBeTrue()
        ->and(syncFieldsPayload($delivered)['is_final'])->toBeTrue()
        ->and(syncFieldsPayload($fulfilledOnly)['is_final'])->toBeFalse();

    // The row rule and the scope agree on every order.
    $openIds = Order::query()->openForSync()->pluck('id')->all();
    foreach ([$open, $cancelled, $refunded, $delivered, $fulfilledOnly] as $order) {
        expect(in_array($order->id, $openIds, true))->toBe(! $order->fresh()->isFinalForSync());
    }
});

it('carries the name, final flag, mismatch and the resolved display on the OrderUpdated broadcast', function () {
    $order = Order::factory()->create([
        'shopify_order_id' => '9',
        'shopify_order_name' => '#1500',
        'financial_status' => 'refunded',
        'fulfillment_status' => 'fulfilled',
        'mismatch' => true,
        'mismatch_reason' => 'shopify_total_differs',
    ])->fresh();

    $payload = (new OrderUpdated($order))->broadcastWith();
    $resource = syncFieldsPayload($order);

    expect($payload)->toMatchArray([
        'shopify_order_name' => '#1500',
        'is_final' => true,
        'mismatch' => true,
        'mismatch_reason' => 'shopify_total_differs',
    ])
        // The same resolver output the list row was rendered from.
        ->and($payload['display'])->toBe($resource['display'])
        ->and($payload['display']['payment'])->toBe('refunded');
});

it('caps the order note in the OrderUpdated broadcast at 500 characters (Reverb frames are 10 KB)', function () {
    $long = str_repeat('ملاحظة طويلة ', 1000); // ~13k characters, ~24 KB of UTF-8
    $order = Order::factory()->create(['source' => OrderSource::Store, 'shopify_order_id' => '124', 'note' => $long]);

    $payload = (new OrderUpdated($order->fresh()))->broadcastWith();

    expect(mb_strlen($payload['note']))->toBeLessThanOrEqual(503) // 500 + the "..." marker
        ->and($payload['note'])->toStartWith(mb_substr($long, 0, 100))
        ->and(strlen(json_encode($payload)))->toBeLessThan(10 * 1024)
        ->and($order->fresh()->note)->toBe($long); // the stored note is untouched

    $short = Order::factory()->create(['source' => OrderSource::Store, 'note' => 'سيبيه عند البواب']);
    $none = Order::factory()->create(['source' => OrderSource::Store, 'note' => null]);
    expect((new OrderUpdated($short))->broadcastWith()['note'])->toBe('سيبيه عند البواب')
        ->and((new OrderUpdated($none))->broadcastWith()['note'])->toBeNull();
});
