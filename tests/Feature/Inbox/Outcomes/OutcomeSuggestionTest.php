<?php

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Inbox\Outcomes\OutcomeSuggestion;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Support\Carbon;

// finish-api.md §2: what the «خلصت» sheet shows preselected, and why.

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-10-24 12:00', 'Africa/Cairo')));

function sgIn(Conversation $c, string $body = 'عايزة اسأل', ?Carbon $at = null): Message
{
    return Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => $body, 'created_at' => $at ?? now()]);
}

function sgReply(Conversation $c, ?Carbon $at = null): Message
{
    return Message::factory()->for($c)->create(['direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => User::factory()->create()->id, 'body' => 'اتفضلي', 'created_at' => $at ?? now()]);
}

/** Her window: delivered 10 minutes ago, she wrote at the start. */
function sgWindow(array $attrs = []): QueueEntry
{
    $e = QueueEntry::factory()->create($attrs + ['status' => 'active', 'delivered_at' => now()->subMinutes(10)]);
    sgIn($e->conversation, 'السلام عليكم', now()->subMinutes(11));

    return $e;
}

it('suggests nothing when nobody answered her yet', function () {
    $e = sgWindow();

    expect(app(OutcomeSuggestion::class)->for($e->conversation, $e))->toBeNull();
});

it('suggests «ما ردّتش» when we replied and she never wrote again, with the minutes since our last reply', function () {
    $e = sgWindow();
    sgReply($e->conversation, now()->subMinutes(8));
    sgReply($e->conversation, now()->subMinutes(7));

    $s = app(OutcomeSuggestion::class)->for($e->conversation, $e);

    expect($s['outcome'])->toBe('no_answer')->and($s['why'])->toBe('no_reply')->and($s['params']['minutes'])->toBe(7)
        ->and($s['case_id'])->toBeNull()->and($s['order_id'])->toBeNull();
});

it('does not count her thanks or a rating answer as a reply', function () {
    $e = sgWindow();
    sgReply($e->conversation, now()->subMinutes(8));
    sgIn($e->conversation, 'شكرا', now()->subMinutes(6));
    sgIn($e->conversation, '5', now()->subMinutes(5));

    expect(app(OutcomeSuggestion::class)->for($e->conversation, $e)['outcome'])->toBe('no_answer');
});

it('suggests «استفسرت وهترجع» when she asked, we answered and she answered back', function () {
    $e = sgWindow();
    sgReply($e->conversation, now()->subMinutes(8));
    sgIn($e->conversation, 'طب والمقاس L؟', now()->subMinutes(6));
    sgReply($e->conversation, now()->subMinutes(5));

    $s = app(OutcomeSuggestion::class)->for($e->conversation, $e);

    expect($s['outcome'])->toBe('browsing')->and($s['why'])->toBe('answered');
});

it('suggests the service outcome of the handover category when no case is recorded yet', function (string $category, string $outcome) {
    $e = sgWindow(['bot_summary' => ['category' => $category, 'lines' => []]]);
    sgReply($e->conversation, now()->subMinutes(8));

    $s = app(OutcomeSuggestion::class)->for($e->conversation, $e);

    expect($s['outcome'])->toBe($outcome)->and($s['why'])->toBe('handover')->and($s['params']['category'])->toBe($category)
        ->and($s['params']['category_label'])->toBeString();
})->with([
    'return' => ['return', 'return_case'],
    'exchange' => ['exchange', 'exchange_case'],
    'complaint' => ['complaint', 'complaint_case'],
    'edit order' => ['edit_order', 'order_change'],
    'order status' => ['order_status', 'order_inquiry'],
]);

it('reads the handover category from the conversation when the window carries none', function () {
    $e = sgWindow();
    $e->conversation->forceFill(['handover_category' => 'complaint', 'handover_at' => now()->subMinutes(9)])->save();

    expect(app(OutcomeSuggestion::class)->for($e->conversation->fresh(), $e)['outcome'])->toBe('complaint_case');
});

it('suggests the case outcome of a case opened in this episode, open or resolved, over the handover', function () {
    $e = sgWindow(['bot_summary' => ['category' => 'complaint', 'lines' => []]]);
    $case = SupportCase::factory()->create(['conversation_id' => $e->conversation_id, 'type' => 'return', 'status' => 'new', 'created_at' => now()->subMinutes(9)]);

    $s = app(OutcomeSuggestion::class)->for($e->conversation, $e);
    expect($s['outcome'])->toBe('return_case')->and($s['why'])->toBe('case_open')->and($s['case_id'])->toBe($case->id)
        ->and($s['params'])->toBe(['case_id' => $case->id, 'type' => 'return']);

    $case->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
    expect(app(OutcomeSuggestion::class)->for($e->conversation, $e)['why'])->toBe('case_resolved');
});

it('suggests «اشترت» for a real order placed in this episode, with its number', function () {
    $e = sgWindow();
    $order = Order::factory()->create(['conversation_id' => $e->conversation_id, 'customer_id' => $e->conversation->customer_id, 'status' => 'confirmed', 'order_number' => '1384', 'created_at' => now()->subMinutes(4)]);

    $s = app(OutcomeSuggestion::class)->for($e->conversation, $e);

    expect($s['outcome'])->toBe('ordered')->and($s['why'])->toBe('order_placed')->and($s['params']['number'])->toBe('1384')->and($s['order_id'])->toBe($order->id);
});

it('ignores a cancelled order and lets the later fact win between a case and an order', function () {
    $e = sgWindow();
    Order::factory()->create(['conversation_id' => $e->conversation_id, 'customer_id' => $e->conversation->customer_id, 'status' => 'cancelled', 'created_at' => now()->subMinutes(4)]);
    sgReply($e->conversation, now()->subMinutes(8));
    expect(app(OutcomeSuggestion::class)->for($e->conversation, $e)['outcome'])->toBe('no_answer');

    SupportCase::factory()->create(['conversation_id' => $e->conversation_id, 'type' => 'exchange', 'created_at' => now()->subMinutes(6)]);
    Order::factory()->create(['conversation_id' => $e->conversation_id, 'customer_id' => $e->conversation->customer_id, 'status' => 'confirmed', 'created_at' => now()->subMinutes(3)]);
    expect(app(OutcomeSuggestion::class)->for($e->conversation, $e)['outcome'])->toBe('ordered');

    SupportCase::factory()->create(['conversation_id' => $e->conversation_id, 'type' => 'complaint', 'created_at' => now()->subMinute()]);
    expect(app(OutcomeSuggestion::class)->for($e->conversation, $e)['outcome'])->toBe('complaint_case');
});

it('never reads a case or an order from an earlier ended episode', function () {
    $e = sgWindow();
    $c = $e->conversation;
    SupportCase::factory()->create(['conversation_id' => $c->id, 'type' => 'return', 'created_at' => now()->subDays(3)]);
    $c->outcomes()->create(['episode_key' => 'old', 'outcome' => 'return_case', 'source' => 'agent', 'set_at' => now()->subDays(3), 'ended_at' => now()->subDays(2), 'last_message_id' => 0]);
    sgReply($c, now()->subMinutes(8));

    expect(app(OutcomeSuggestion::class)->for($c, $e)['outcome'])->toBe('no_answer');
});
