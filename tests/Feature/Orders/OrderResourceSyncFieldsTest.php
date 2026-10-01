<?php

use App\Enums\OrderSource;
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
