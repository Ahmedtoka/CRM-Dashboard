<?php

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Inbox\Outcomes\Outcome;
use App\Inbox\Outcomes\OutcomeRecorder;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\Order;
use App\Models\QueueEntry;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', 'Africa/Cairo')));

function s3In(Conversation $c, string $body = 'سلام'): Message
{
    return Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => $body]);
}

it('keys the episode by its first customer message, never by our own messages', function () {
    $c = Conversation::factory()->create();
    Message::factory()->for($c)->create(); // the bot speaks first: outbound starts nothing
    $first = s3In($c);
    s3In($c, 'تاني');

    $ep = app(OutcomeRecorder::class)->episode($c);

    expect($ep['key'])->toBe('m'.$first->id)
        ->and($ep['first_message_id'])->toBe($first->id)
        ->and($ep['since'])->toBeNull();
});

it('starts the next episode after the watermark of the last end', function () {
    $c = Conversation::factory()->create();
    s3In($c);
    $ended = ConversationOutcome::create([
        'conversation_id' => $c->id, 'episode_key' => 'm1', 'outcome' => 'price', 'source' => 'agent', 'set_at' => now(),
        'ended_at' => now(), 'last_message_id' => (int) Message::query()->max('id'),
    ]);
    Message::factory()->for($c)->create(); // the closing thanks: outbound, after the watermark
    Carbon::setTestNow(now()->addMinutes(5));
    $next = s3In($c, 'رجعت');

    $ep = app(OutcomeRecorder::class)->episode($c);

    expect($ep['key'])->toBe('m'.$next->id)->and($ep['since']->equalTo($ended->ended_at))->toBeTrue();
});

it('uses a watermark key when she has not written since the last end', function () {
    $c = Conversation::factory()->create();
    $m = s3In($c);
    ConversationOutcome::create(['conversation_id' => $c->id, 'episode_key' => 'm'.$m->id, 'outcome' => 'price', 'source' => 'agent', 'set_at' => now(), 'ended_at' => now(), 'last_message_id' => $m->id]);

    expect(app(OutcomeRecorder::class)->episode($c)['key'])->toBe('w'.$m->id);
});

it('finds ordered first, then service from the handover in this episode, else nothing', function () {
    $c = Conversation::factory()->create(['handover_category' => 'return', 'handover_at' => now()]);
    s3In($c);
    $r = app(OutcomeRecorder::class);

    expect($r->autoOutcome($c))->toBe(Outcome::Service);

    Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'confirmed']);
    expect($r->autoOutcome($c))->toBe(Outcome::Ordered);
});

it('ignores a cancelled order, a sales handover and a handover from an earlier episode', function () {
    $c = Conversation::factory()->create(['handover_category' => 'sizes', 'handover_at' => now()]);
    s3In($c);
    Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'cancelled']);
    $r = app(OutcomeRecorder::class);
    expect($r->autoOutcome($c))->toBeNull();

    $c->forceFill(['handover_category' => 'complaint'])->save();
    ConversationOutcome::create(['conversation_id' => $c->id, 'episode_key' => 'x', 'outcome' => 'price', 'source' => 'agent', 'set_at' => now(), 'ended_at' => now()->addMinute(), 'last_message_id' => 999999]);
    expect($r->autoOutcome($c->fresh()))->toBeNull(); // the complaint handover happened before that end
});

it('reads the service category from the open queue entry summary', function () {
    $e = QueueEntry::factory()->create(['status' => 'active', 'bot_summary' => ['category' => 'order_status', 'lines' => []]]);
    s3In($e->conversation);

    expect(app(OutcomeRecorder::class)->autoOutcome($e->conversation, $e))->toBe(Outcome::Service);
});

it('records an order placed in the running episode as ordered and open', function () {
    $c = Conversation::factory()->create();
    $first = s3In($c);
    $order = Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'submitting']);

    $row = app(OutcomeRecorder::class)->orderPlaced($order);

    expect($row->episode_key)->toBe('m'.$first->id)
        ->and($row->outcome)->toBe('ordered')->and($row->source)->toBe('auto')
        ->and($row->order_id)->toBe($order->id)->and($row->ended_at)->toBeNull()
        ->and(app(OutcomeRecorder::class)->currentRow($c)?->id)->toBe($row->id);
});

it('credits an order placed right after a close to the episode that just ended', function () {
    $c = Conversation::factory()->create();
    $m = s3In($c);
    $ended = ConversationOutcome::create(['conversation_id' => $c->id, 'episode_key' => 'm'.$m->id, 'outcome' => 'browsing', 'source' => 'agent', 'set_at' => now(), 'ended_at' => now(), 'last_message_id' => $m->id]);
    Carbon::setTestNow(now()->addMinutes(2));
    $order = Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'submitting']);

    $row = app(OutcomeRecorder::class)->orderPlaced($order);

    expect($row->id)->toBe($ended->id)->and($row->outcome)->toBe('ordered')->and($row->ended_at)->not->toBeNull()
        ->and(ConversationOutcome::count())->toBe(1);
});

it('does nothing for an order with no conversation', function () {
    expect(app(OutcomeRecorder::class)->orderPlaced(Order::factory()->create(['conversation_id' => null])))->toBeNull()
        ->and(ConversationOutcome::count())->toBe(0);
});
