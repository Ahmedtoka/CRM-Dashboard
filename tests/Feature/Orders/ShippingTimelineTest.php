<?php

use App\Enums\UserRole;
use App\Models\Fulfillment;
use App\Models\FulfillmentEvent;
use App\Models\Order;
use App\Models\User;
use App\Models\UserNotification;
use App\Shipping\DeliveryFailureAlert;
use App\Shopify\Sync\Mappers\MapResult;
use App\Shopify\Sync\Mappers\OrderMapper;
use Illuminate\Support\Facades\Event;

// Shipping and tracking from Shopify only (round 2026-10-24): every fulfillment/tracking update lands as a
// FulfillmentEvent, the order exposes a shipping timeline and a summary for the bot, and a failed delivery alerts
// the moderators.

beforeEach(function () {
    Event::fake();
    $this->travelTo('2026-10-24 10:00:00');
});

function shippingFixture(string $name): array
{
    return json_decode(file_get_contents(base_path("tests/Fixtures/shopify/{$name}.json")), true);
}

function fulfilledStoreOrder(): array
{
    app(OrderMapper::class)->upsert(shippingFixture('webhook_order_store'));
    $f = shippingFixture('webhook_fulfillment');
    app(OrderMapper::class)->applyFulfillment($f);

    return [Order::firstOrFail(), $f];
}

it('records each shipment status change from fulfillments/update as a timeline step', function () {
    [$order, $f] = fulfilledStoreOrder();

    // «confirmed» is only the label step: the timeline shows «created» with the carrier and tracking.
    $summary = $order->fresh()->shippingSummary();
    expect($summary['step'])->toBe('created')
        ->and($summary['carrier_updates'])->toBeFalse()
        ->and($summary['tracking']['company'])->toBe('Bosta')
        ->and($summary['tracking']['number'])->toBe('BST8812345670')
        ->and($summary['timeline'])->toHaveCount(1);

    $f['shipment_status'] = 'in_transit';
    $f['updated_at'] = '2026-09-12T08:00:00+03:00';
    app(OrderMapper::class)->applyFulfillment($f);

    $f['shipment_status'] = 'out_for_delivery';
    $f['updated_at'] = '2026-09-13T09:00:00+03:00';
    app(OrderMapper::class)->applyFulfillment($f);

    // The same status again adds nothing.
    $f['updated_at'] = '2026-09-13T09:30:00+03:00';
    app(OrderMapper::class)->applyFulfillment($f);

    $f['shipment_status'] = 'delivered';
    $f['updated_at'] = '2026-09-13T15:00:00+03:00';
    app(OrderMapper::class)->applyFulfillment($f);

    $order = $order->fresh();
    // The label step («confirmed») is kept as data; the timeline folds it into «created».
    expect(FulfillmentEvent::where('order_id', $order->id)->pluck('status')->all())->toBe(['confirmed', 'in_transit', 'out_for_delivery', 'delivered'])
        ->and(array_column($order->shippingTimeline(), 'step'))->toBe(['created', 'in_transit', 'out_for_delivery', 'delivered']);

    $summary = $order->shippingSummary();
    expect($summary['step'])->toBe('delivered')
        ->and($summary['carrier_updates'])->toBeTrue()
        ->and($summary['failed_attempt'])->toBeFalse()
        ->and($summary['delivered_at'])->not->toBeNull()
        ->and($order->delivered_at)->not->toBeNull()
        ->and($order->shipment_status)->toBe('delivered');
});

it('applies a fulfillment_events/create webhook, moves the fulfillment with it and alerts on a failed delivery', function () {
    $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
    $moderator = User::factory()->create(['role' => UserRole::Moderator]);
    [$order, $f] = fulfilledStoreOrder();

    $event = [
        'id' => 9001, 'fulfillment_id' => $f['id'], 'order_id' => $f['order_id'],
        'status' => 'failure', 'message' => 'Customer not reachable', 'happened_at' => '2026-09-13T12:00:00+03:00',
        'created_at' => '2026-09-13T12:00:05+03:00',
    ];

    expect(app(OrderMapper::class)->applyFulfillmentEvent($event))->toBe(MapResult::Created)
        ->and(app(OrderMapper::class)->applyFulfillmentEvent($event))->toBe(MapResult::Skipped);

    $order = $order->fresh();
    expect(Fulfillment::firstOrFail()->shipment_status)->toBe('failure')
        ->and($order->shipment_status)->toBe('failure')
        ->and($order->shippingSummary()['step'])->toBe('failed_attempt')
        ->and($order->shippingSummary()['failed_attempt'])->toBeTrue()
        ->and($order->shippingSummary()['timeline'][1]['message'])->toBe('Customer not reachable');

    // Supervisors are told once; a plain moderator not serving the chat is not.
    expect(UserNotification::where('type', DeliveryFailureAlert::TYPE)->where('user_id', $supervisor->id)->count())->toBe(1)
        ->and(UserNotification::where('type', DeliveryFailureAlert::TYPE)->where('user_id', $moderator->id)->count())->toBe(0);
    $data = UserNotification::where('type', DeliveryFailureAlert::TYPE)->first()->data;
    expect($data['order_id'])->toBe($order->id)->and($data['tracking_company'])->toBe('Bosta');

    // An event for a fulfillment we do not have is skipped, not an error.
    expect(app(OrderMapper::class)->applyFulfillmentEvent(['id' => 9002, 'fulfillment_id' => 1, 'status' => 'delivered']))->toBe(MapResult::Skipped);
});

it('keeps the fulfillment events the GraphQL sync carries and does not double a status the events already said', function () {
    app(OrderMapper::class)->upsert(shippingFixture('webhook_order_store'));
    $node = [
        'id' => 'gid://shopify/Fulfillment/77001',
        'order' => ['id' => 'gid://shopify/Order/'.shippingFixture('webhook_order_store')['id']],
        'status' => 'SUCCESS',
        'displayStatus' => 'IN_TRANSIT',
        'createdAt' => '2026-09-11T09:30:00Z',
        'updatedAt' => '2026-09-12T09:30:00Z',
        'deliveredAt' => null,
        'trackingInfo' => [['company' => 'Bosta', 'number' => 'B1', 'url' => 'https://bosta.co/t/B1']],
        'events' => ['nodes' => [
            ['id' => 'gid://shopify/FulfillmentEvent/1', 'status' => 'CONFIRMED', 'happenedAt' => '2026-09-11T09:31:00Z', 'message' => null],
            ['id' => 'gid://shopify/FulfillmentEvent/2', 'status' => 'IN_TRANSIT', 'happenedAt' => '2026-09-12T09:30:00Z', 'message' => 'Left the hub'],
        ]],
    ];

    expect(app(OrderMapper::class)->applyFulfillment($node))->toBe(MapResult::Created);

    $events = FulfillmentEvent::query()->orderBy('happened_at')->get();
    expect($events->pluck('status')->all())->toBe(['confirmed', 'in_transit'])
        ->and($events->pluck('source')->unique()->all())->toBe(['sync'])
        ->and(array_column(Order::firstOrFail()->shippingTimeline(), 'step'))->toBe(['created', 'in_transit']);
});

it('sends the shipping summary with the order resource', function () {
    [$order] = fulfilledStoreOrder();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->getJson("/orders/{$order->id}/refresh")->assertStatus(405);

    $this->actingAs($admin)->get("/orders/{$order->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->component('Orders/Show')
            ->where('order.shipping.step', 'created')
            ->where('order.shipping.tracking.company', 'Bosta')
            ->has('order.shipping.timeline', 1));
});

it('maps the fulfillment_events webhook route to its topic', function () {
    expect(config('crm.shopify.webhook_topics'))->toContain('fulfillment_events/create');
});
