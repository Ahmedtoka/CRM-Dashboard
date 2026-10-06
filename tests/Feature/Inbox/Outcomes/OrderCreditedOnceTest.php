<?php

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Inbox\Outcomes\EpisodeEnd;
use App\Inbox\Outcomes\Outcome;
use App\Inbox\Outcomes\OutcomeRecorder;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

// Review round 1 (IMPORTANT 1 + minors): one order is credited to one episode only; unpaid / failed
// orders are not `ordered`; an episode that ends with another outcome holds no order.

beforeEach(function () {
    Event::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00', 'Africa/Cairo'));
});

/** @return array{0: Conversation, 1: User} */
function s3OnceChat(): array
{
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $c = Conversation::factory()->for($acc, 'channelAccount')->create(['platform' => Platform::Facebook]);
    Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'بكام ده؟']);
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);

    return [$c, $mod];
}

it('credits an order placed right after a close to that episode only: her next chat needs an outcome', function () {
    [$c, $mod] = s3OnceChat();
    $this->actingAs($mod)->postJson("/inbox/conversations/{$c->id}/resolve", ['outcome' => 'browsing'])->assertOk();

    Carbon::setTestNow(now()->addMinutes(2));
    $order = Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'confirmed']);
    app(OutcomeRecorder::class)->orderPlaced($order);
    expect(ConversationOutcome::sole()->outcome)->toBe('ordered');

    Carbon::setTestNow(now()->addHour());
    Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'عايزة كمان واحد']);
    Conversation::query()->whereKey($c->id)->update(['status' => 'open']); // she wrote again: the chat is open

    expect(app(OutcomeRecorder::class)->autoOutcome($c->fresh()))->toBeNull();
    $this->actingAs($mod)->postJson("/inbox/conversations/{$c->id}/resolve")->assertUnprocessable()->assertJsonValidationErrors('outcome');
    $this->actingAs($mod)->postJson("/inbox/conversations/{$c->id}/resolve", ['outcome' => 'price'])->assertOk();

    expect(ConversationOutcome::orderBy('id')->pluck('outcome')->all())->toBe(['ordered', 'price'])
        ->and(ConversationOutcome::orderBy('id')->pluck('order_id')->all())->toBe([$order->id, null]);
});

it('does not count an unpaid or failed order as ordered', function (string $status) {
    [$c] = s3OnceChat();
    Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => $status]);

    expect(app(OutcomeRecorder::class)->autoOutcome($c))->toBeNull();
})->with(['awaiting_payment', 'failed', 'cancelled']);

it('drops the order from an episode that ends with another outcome', function () {
    [$c, $mod] = s3OnceChat();
    $order = Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'awaiting_payment']);
    $r = app(OutcomeRecorder::class);
    $open = $r->orderPlaced($order);

    $row = $r->endEpisode($c, null, Outcome::Price, null, $mod, EpisodeEnd::Resolve);

    expect($row->id)->toBe($open->id)->and($row->outcome)->toBe('price')->and($row->order_id)->toBeNull();
});
