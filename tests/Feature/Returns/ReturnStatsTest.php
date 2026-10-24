<?php

use App\Cases\Returns\ReturnLifecycle;
use App\Cases\Returns\SendReturnUpdate;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SupportCase;
use App\Models\User;
use App\Orders\Reports\ReturnStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

// Return KPIs for Reports (round 2026-10-24): rate per period and per product, from the lifecycle's own data.

beforeEach(function () {
    Queue::fake([SendReturnUpdate::class]);
    $this->travelTo('2026-10-24 10:00:00');
    $this->moderator = User::factory()->create(['role' => UserRole::Supervisor]);
    $account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected']);
    $this->conversation = Conversation::factory()->create(['platform' => Platform::WhatsApp, 'channel_account_id' => $account->id, 'customer_id' => Customer::factory()->create()->id]);
});

function statsOrder(string $title, int $qty, ?string $placed = null): Order
{
    $order = Order::factory()->create(['status' => OrderStatus::Confirmed, 'placed_at' => $placed ?? now()->subDay()]);
    OrderItem::factory()->create(['order_id' => $order->id, 'title' => $title, 'qty' => $qty]);

    return $order;
}

function statsCase(Order $order, string $type, array $items): SupportCase
{
    return SupportCase::factory()->create([
        'conversation_id' => test()->conversation->id, 'platform' => 'whatsapp', 'type' => $type, 'order_id' => $order->id,
        'data' => ['reason' => 'size', 'selected_items' => $items],
    ]);
}

it('counts the period\'s returns and exchanges against its real orders, cash refunded and days to pickup', function () {
    // 10 real orders of a dress, one cancelled order that never counts.
    foreach (range(1, 10) as $i) {
        statsOrder('فستان شيفون', 1);
    }
    Order::factory()->create(['status' => OrderStatus::Cancelled, 'placed_at' => now()->subDay()]);
    // Before the period: neither the order nor its return count.
    $old = statsOrder('فستان شيفون', 1, now()->subMonths(2));
    statsCase($old, 'return', [['title' => 'فستان شيفون', 'qty' => 1]])->forceFill(['created_at' => now()->subMonths(2)])->save();

    $orders = Order::query()->where('status', OrderStatus::Confirmed)->orderBy('id')->get();
    $return = statsCase($orders[0], 'return', [['title' => 'فستان شيفون', 'qty' => 1]]);
    $exchange = statsCase($orders[1], 'exchange', [['title' => 'فستان شيفون', 'qty' => 1]]);
    $withdrawn = statsCase($orders[2], 'return', [['title' => 'فستان شيفون', 'qty' => 2]]);

    $lifecycle = app(ReturnLifecycle::class);
    $this->travelTo('2026-10-25 10:00:00');
    $return = $lifecycle->advance($return, 'pickup_scheduled', $this->moderator, ['pickup_date' => '2026-10-26']);
    $this->travelTo('2026-10-26 12:00:00');
    $lifecycle->advance($return, 'picked_up', $this->moderator, ['refund_amount' => 450]);
    $lifecycle->advance($withdrawn, 'cancelled', $this->moderator, ['reason' => 'غيرت رأيها']);

    $from = CarbonImmutable::parse('2026-10-01 00:00:00');
    $to = CarbonImmutable::parse('2026-10-31 23:59:59');
    $summary = app(ReturnStats::class)->summary($from, $to);

    expect($summary['orders'])->toBe(10)
        ->and($summary['returns'])->toBe(1)
        ->and($summary['exchanges'])->toBe(1)
        ->and($summary['cancelled'])->toBe(1)
        ->and($summary['rate'])->toBe(0.2)
        ->and($summary['refunded_total'])->toBe(450.0)
        ->and($summary['by_stage']['picked_up'])->toBe(1)
        ->and($summary['by_stage']['requested'])->toBe(1)
        ->and($summary['by_stage']['cancelled'])->toBe(1)
        ->and($summary['avg_days_to_pickup'])->toBe(2.1)
        ->and($summary['avg_days_to_close'])->toBeNull();

    $products = app(ReturnStats::class)->byProduct($from, $to);
    expect($products)->toHaveCount(1)
        ->and($products[0]['title'])->toBe('فستان شيفون')
        ->and($products[0]['returned_units'])->toBe(2)
        ->and($products[0]['sold_units'])->toBe(10)
        ->and($products[0]['rate'])->toBe(0.2)
        ->and($products[0]['cases'])->toBe(2);
});

it('is empty for a period with nothing', function () {
    $from = CarbonImmutable::parse('2026-01-01');
    $to = CarbonImmutable::parse('2026-01-31 23:59:59');

    expect(app(ReturnStats::class)->summary($from, $to)['rate'])->toBe(0.0)
        ->and(app(ReturnStats::class)->byProduct($from, $to))->toBe([]);
});
