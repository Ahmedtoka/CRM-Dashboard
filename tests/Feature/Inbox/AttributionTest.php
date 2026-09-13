<?php

use App\Enums\{Platform, ParticipantRole, UserRole};
use App\Inbox\OutboundService;
use App\Analytics\AttributionRecorder;
use App\Models\{ActivityLog, ChannelAccount, Conversation, Customer, CustomerIdentity, Order, User, ConversationParticipant};
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::WhatsApp]);
    $this->conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create(['platform' => Platform::WhatsApp, 'last_customer_message_at' => now()]);
    [$this->a, $this->b, $this->c] = User::factory()->count(3)->create(['role' => UserRole::Supervisor])->all();
});

function roleOf(User $u, Conversation $c): array {
    return ConversationParticipant::where('conversation_id', $c->id)->where('user_id', $u->id)->pluck('role')->map->value->all();
}

it('assigns first, continued and follow_up', function () {
    $svc = app(OutboundService::class);
    $this->travelTo(now());
    $svc->sendHuman($this->conv, $this->a, 'hi');
    $this->travel(10)->minutes();
    $svc->sendHuman($this->conv->fresh(), $this->b, 'continuing');
    $this->conv->update(['last_customer_message_at' => now()->addHours(13)]);
    $this->travel(13)->hours();
    $svc->sendHuman($this->conv->fresh(), $this->c, 'following up');
    expect(roleOf($this->a, $this->conv))->toBe(['first'])
        ->and(roleOf($this->b, $this->conv))->toBe(['continued'])
        ->and(roleOf($this->c, $this->conv))->toBe(['follow_up']);
});

function orderOn(Conversation $c, User $by, $at): Order {
    return Order::factory()->create([
        'customer_id' => $c->customer_id, 'conversation_id' => $c->id, 'created_by_id' => $by->id,
        'platform' => $c->platform, 'created_at' => $at, 'updated_at' => $at,
    ]);
}

it('does not mint participant rows for an order and keeps the first human message first', function () {
    $this->travelTo(now());
    app(AttributionRecorder::class)->recordOrder(orderOn($this->conv, $this->c, now()->subHour()));
    expect(ConversationParticipant::count())->toBe(0);
    app(OutboundService::class)->sendHuman($this->conv->fresh(), $this->a, 'hi');
    expect(roleOf($this->a, $this->conv))->toBe(['first'])
        ->and(roleOf($this->c, $this->conv))->toBe([]);
});

it('marks a later responder follow_up once an order exists', function () {
    $svc = app(OutboundService::class);
    $this->travelTo(now());
    $svc->sendHuman($this->conv, $this->a, 'hi');
    $this->travel(1)->minutes();
    app(AttributionRecorder::class)->recordOrder(orderOn($this->conv, $this->a, now()));
    $this->travel(10)->minutes();
    $svc->sendHuman($this->conv->fresh(), $this->b, 'your order is confirmed');
    expect(roleOf($this->a, $this->conv))->toBe(['first'])
        ->and(roleOf($this->b, $this->conv))->toBe(['follow_up']);
});

it('logs exactly one order.created entry for the creator', function () {
    $order = orderOn($this->conv, $this->a, now()->subMinutes(5));
    app(AttributionRecorder::class)->recordOrder($order);
    $logs = ActivityLog::where('action', 'order.created')->get();
    expect($logs)->toHaveCount(1)
        ->and($logs[0]->user_id)->toBe($this->a->id)
        ->and($logs[0]->conversation_id)->toBe($this->conv->id)
        ->and($logs[0]->subject_id)->toBe($order->id)
        ->and($logs[0]->platform)->toBe(Platform::WhatsApp)
        ->and($logs[0]->meta)->toHaveKeys(['type', 'total']);
});
