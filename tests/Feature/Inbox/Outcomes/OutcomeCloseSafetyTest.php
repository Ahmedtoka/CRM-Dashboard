<?php

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Inbox\Outcomes\EpisodeEnd;
use App\Inbox\Outcomes\Outcome;
use App\Inbox\Outcomes\OutcomeRecorder;
use App\Models\Conversation;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\Order;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\WindowLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

// Review round 1 (IMPORTANT 3): a racing orderPlaced() insert or any outcomes failure never rolls back a close.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
    Bus::fake();
});

function s3SafeIn(Conversation $c): Message
{
    return Message::factory()->for($c)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'بكام؟']);
}

it('ends the row a racing order inserted instead of failing on the unique key', function () {
    $c = Conversation::factory()->create();
    $first = s3SafeIn($c);
    $order = Order::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'submitting']);
    $raced = false;
    // The order's row lands between endEpisode()'s read and its insert.
    ConversationOutcome::creating(function () use (&$raced, $c, $first, $order) {
        if (! $raced) {
            $raced = true;
            DB::table('conversation_outcomes')->insert(['conversation_id' => $c->id, 'episode_key' => 'm'.$first->id, 'outcome' => 'ordered', 'source' => 'auto', 'order_id' => $order->id, 'set_at' => now(), 'reached_agent' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $row = app(OutcomeRecorder::class)->endEpisode($c, null, Outcome::Price, null, User::factory()->create(), EpisodeEnd::Resolve);

    expect(ConversationOutcome::count())->toBe(1)
        ->and($row->fresh()->outcome)->toBe('ordered')->and($row->fresh()->order_id)->toBe($order->id)
        ->and($row->fresh()->ended_at)->not->toBeNull();
});

it('still closes the window when recording the outcome fails', function () {
    Exceptions::fake();
    $shift = Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1, 'delivered_at' => now(), 'enqueued_at' => now()->subMinute()]);
    $e->conversation->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true]);
    s3SafeIn($e->conversation);
    ConversationOutcome::saving(fn () => throw new RuntimeException('outcomes bug'));

    app(WindowLifecycle::class)->close($e, 'inquiry', $u, ['outcome' => Outcome::Price]);

    expect($e->fresh()->status)->toBe('closed')->and($e->fresh()->close_reason)->toBe('inquiry')
        ->and(ConversationOutcome::count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});
