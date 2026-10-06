<?php

use App\Analytics\ActivityLogger;
use App\Analytics\MetricsService;
use App\Enums\ConversationSource;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\QueueSetting;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Today\TodayCards;
use App\Today\TodayWindow;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-06 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

it('builds the chats card from the same definitions as the reports', function () {
    Conversation::factory()->count(2)->create(['created_at' => now()->subHours(2)]);
    $ad = Conversation::factory()->create(['created_at' => now()->subHour(), 'source' => ConversationSource::Ad]);
    Conversation::factory()->create(['created_at' => now()->subHour(), 'status' => 'resolved', 'resolved_at' => now()->subMinutes(30)]); // bot closed alone
    Conversation::factory()->create(['created_at' => now()->subDay()]); // yesterday
    ActivityLog::factory()->create(['action' => ActivityLogger::CONVERSATION_HANDOVER, 'conversation_id' => $ad->id, 'created_at' => now()->subMinutes(40)]);

    $w = TodayWindow::for('today');
    $c = app(TodayCards::class)->chats($w, User::factory()->create(['role' => UserRole::Admin]));
    $team = app(MetricsService::class)->teamMetrics($w->from, $w->to);
    $bot = app(MetricsService::class)->botMetrics($w->from, $w->to);

    expect($c)->toMatchArray([
        'new' => $team['conversations_new'], 'from_ads' => 1, 'ads_share' => 0.25,
        'bot_alone' => $bot['auto_resolved'], 'to_agent' => $bot['handovers'],
        'first_reply_avg_sec' => $team['avg_first_response_sec'],
    ])->and($c['new'])->toBe(4)
        ->and($c['queue'])->toBeArray()->toHaveKey('sla_pct')
        ->and($c['rating'])->toBe(['count' => 0, 'avg' => null, 'low' => 0])
        ->and($c['links'])->toMatchArray([
            'new' => '/reports/team?from=2026-10-06&to=2026-10-06', 'ads' => '/inbox?flags=ad&from=2026-10-06&to=2026-10-06',
            'bot' => '/reports/bot?from=2026-10-06&to=2026-10-06', 'rating' => '/reports/team?from=2026-10-06&to=2026-10-06#ratings',
        ]);
});

it('builds the orders card: today\'s orders, cancelled and failed of the day, deliveries of yesterday', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'source' => OrderSource::Chat, 'total' => 1000, 'created_at' => now()->subHour()]);
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'source' => OrderSource::Store, 'total' => 500, 'created_at' => now()->subHour()]);
    Order::factory()->create(['status' => OrderStatus::Cancelled, 'created_at' => now()->subHour(), 'cancelled_at' => now()]);
    Order::factory()->create(['status' => OrderStatus::Failed, 'created_at' => now()->subMinutes(5)]);

    $w = TodayWindow::for('today');
    $o = app(TodayCards::class)->orders($w);
    $team = app(MetricsService::class)->teamMetrics($w->from, $w->to);
    $prev = app(MetricsService::class)->teamMetrics($w->outcomeDay()->from, $w->outcomeDay()->to);

    expect($o)->toMatchArray([
        'count' => $team['orders_count'], 'total' => $team['orders_total'], 'from_chat' => 1, 'from_store' => 1,
        'cancelled' => 1, 'failed' => 1, 'outcome_date' => '2026-10-05',
        'delivered' => $prev['orders_delivered'], 'returned' => $prev['orders_returned'],
    ])->and($o['count'])->toBe(2);

    $this->actingAs($sup)->getJson($o['links']['cancelled'])->assertJsonPath('meta.total', 1);
    $this->actingAs($sup)->getJson($o['links']['failed'])->assertJsonPath('meta.total', 1);
});

it('reads a complete yesterday in yesterday mode and leaves the queue out when it is off', function () {
    QueueSetting::current()->update(['enabled' => false]);
    Conversation::factory()->create(['created_at' => Carbon::parse('2026-10-05 23:50', 'Africa/Cairo')->utc()]); // stored as UTC, like real rows
    Conversation::factory()->create(['created_at' => now()]);

    $c = app(TodayCards::class)->chats(TodayWindow::for('yesterday'), User::factory()->create(['role' => UserRole::Admin]));

    expect($c['new'])->toBe(1)->and($c['queue'])->toBeNull()
        ->and($c['links']['new'])->toBe('/reports/team?from=2026-10-05&to=2026-10-05');
});

it('opens every chats and orders number on a list of exactly that many rows', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    // Chats: two from ads today, one from an ad yesterday, one plain today.
    Conversation::factory()->count(2)->create(['created_at' => now()->subHour(), 'source' => ConversationSource::Ad]);
    Conversation::factory()->create(['created_at' => now()->subDay(), 'source' => ConversationSource::Ad]);
    Conversation::factory()->create(['created_at' => now()->subHour()]);
    // Orders of today, all statuses; deliveries and returns of yesterday (latest event), one delivered today.
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'source' => OrderSource::Chat, 'created_at' => now()->subHour()]);
    Order::factory()->create(['status' => OrderStatus::Confirmed, 'source' => OrderSource::Store, 'created_at' => now()->subHour()]);
    Order::factory()->create(['status' => OrderStatus::Cancelled, 'source' => OrderSource::Chat, 'created_at' => now()->subHour()]);
    Order::factory()->create(['status' => OrderStatus::Failed, 'source' => OrderSource::Store, 'created_at' => now()->subHour()]);
    $shipped = function (ShipmentStatus $step, Carbon $at, OrderStatus $status = OrderStatus::Confirmed): void {
        $o = Order::factory()->create(['status' => $status, 'created_at' => now()->subDays(4)]);
        $s = Shipment::factory()->create(['order_id' => $o->id, 'status' => $step, 'last_event_at' => $at]);
        ShipmentEvent::factory()->create(['shipment_id' => $s->id, 'status' => $step, 'occurred_at' => $at]);
    };
    $shipped(ShipmentStatus::Delivered, now()->subDay());
    $shipped(ShipmentStatus::Delivered, now()->subDay()->subHours(2));
    $shipped(ShipmentStatus::Delivered, now()->subHour());                          // today: not yesterday's
    $shipped(ShipmentStatus::Delivered, now()->subDay(), OrderStatus::Cancelled);  // never counted
    $shipped(ShipmentStatus::Returned, now()->subDay());

    $cards = app(TodayCards::class);
    $w = TodayWindow::for('today');
    $c = $cards->chats($w, $admin);
    $o = $cards->orders($w);
    $this->actingAs($admin);

    expect($c['from_ads'])->toBe(2);
    $this->getJson(str_replace('/inbox?', '/inbox/conversations?', $c['links']['ads']))->assertOk()->assertJsonCount($c['from_ads'], 'data');

    expect([$o['count'], $o['from_chat'], $o['from_store'], $o['delivered'], $o['returned']])->toBe([2, 1, 1, 2, 1]);
    foreach (['count', 'from_chat', 'from_store', 'cancelled', 'failed', 'delivered', 'returned'] as $key) {
        $this->getJson($o['links'][$key])->assertOk()->assertJsonPath('meta.total', $o[$key]);
    }
});
