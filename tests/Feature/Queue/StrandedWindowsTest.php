<?php

use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Jobs\SendQueueMessage;
use App\Queue\ShiftService;
use App\Queue\WindowLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

// Close-cycle hotfix C1 #2 (D-12 with the H-07 rules): a window left with a moderator whose shift
// is over and who is gone is never stranded: silent close when the customer has the turn, back to
// the lounge when we do — overnight (night message, no «موظفة تانية») when no shift is open.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 18:30', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true, 'night_message_enabled' => true]);
});

/** The morning shift (10:00–18:00) ended and was closed; `$u` left it (auto_out) and was last seen `$seenMinutesAgo` ago. */
function swEndedShiftWindow(int $seenMinutesAgo = 20, string $memberStatus = 'left', array $attrs = []): array
{
    $shift = Shift::query()->where('shift_key', 'morning')->first() ?? Shift::factory()->create(['status' => 'closed', 'closed_at' => now()->subMinutes(30)]);
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()->subMinutes($seenMinutesAgo)]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => $memberStatus]);
    $e = QueueEntry::factory()->create($attrs + [
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1, 'priority' => 'live',
        'delivered_at' => now()->subMinutes(40), 'enqueued_at' => now()->subMinutes(45), 'first_reply_at' => now()->subMinutes(39),
        'last_agent_message_at' => now()->subMinutes(38), 'last_customer_message_at' => now()->subMinutes(32), 'awaiting_reply_since' => now()->subMinutes(32),
    ]);
    $e->conversation->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true, 'last_customer_message_at' => now()->subMinutes(32)]);

    return [$e->fresh(), $u];
}

function swTexts(QueueEntry $e): array
{
    return Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->orderBy('id')->pluck('body')->all();
}

it('sends a waiting customer to the overnight lane with the night message when no shift is open', function () {
    Bus::fake([SendQueueMessage::class]);
    [$e, $u] = swEndedShiftWindow();

    app(WindowLifecycle::class)->tickStranded();

    $new = QueueEntry::where('conversation_id', $e->conversation_id)->latest('id')->first();
    expect($e->fresh()->status)->toBe('closed')->and($e->fresh()->close_reason)->toBe('transfer')
        ->and($new->id)->not->toBe($e->id)->and($new->status)->toBe('waiting')->and($new->priority)->toBe('overnight')
        ->and($new->ticket_no)->toBe($e->ticket_no)
        ->and($e->conversation->fresh()->queue_entry_id)->toBe($new->id);
    Bus::assertDispatched(SendQueueMessage::class, fn ($job) => $job->entryId === $new->id && $job->scriptKey === 'queue_night');
    Bus::assertNotDispatched(SendQueueMessage::class, fn ($job) => $job->scriptKey === 'queue_reassigned');
});

it('sends a waiting customer back to the top of the lounge when another shift is open', function () {
    Bus::fake([SendQueueMessage::class]);
    [$e] = swEndedShiftWindow();
    Shift::factory()->create(['shift_key' => 'evening', 'starts_at' => now()->subMinutes(30), 'ends_at' => now()->addHours(5)]);

    app(WindowLifecycle::class)->tickStranded();

    $new = QueueEntry::where('conversation_id', $e->conversation_id)->latest('id')->first();
    expect($new->priority)->toBe('returning')->and($new->status)->toBe('waiting');
    Bus::assertDispatched(SendQueueMessage::class, fn ($job) => $job->entryId === $new->id && $job->scriptKey === 'queue_reassigned');
});

it('closes silently when the customer has the turn, and the chat goes back to the bot', function () {
    [$e] = swEndedShiftWindow(attrs: ['awaiting_reply_since' => null, 'last_agent_message_at' => now()->subMinutes(30), 'last_customer_message_at' => now()->subMinutes(32)]);

    app(WindowLifecycle::class)->tickStranded();

    expect($e->fresh()->status)->toBe('closed')->and($e->fresh()->close_reason)->toBe('auto')
        ->and(swTexts($e))->toBe([])
        ->and(QueueEntry::where('conversation_id', $e->conversation_id)->count())->toBe(1)
        ->and($e->conversation->fresh()->handler->value)->toBe('bot');
});

it('also takes the windows of a desk still checking out when its shift closed', function () {
    Bus::fake([SendQueueMessage::class]);
    [$e] = swEndedShiftWindow(memberStatus: 'checking_out');

    app(WindowLifecycle::class)->tickStranded();

    expect($e->fresh()->status)->toBe('closed');
});

it('leaves the windows of a moderator still logged in after her shift: she is finishing them', function () {
    [$e] = swEndedShiftWindow(seenMinutesAgo: 0);

    app(WindowLifecycle::class)->tickStranded();

    expect($e->fresh()->status)->toBe('active');
});

it('leaves the windows of a moderator on an open shift to the offline path', function () {
    $shift = Shift::factory()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5)]);
    $u = User::factory()->create(['last_seen_at' => now()->subMinutes(20)]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'offline']);
    $e = QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'delivered_at' => now(), 'awaiting_reply_since' => now()]);

    app(WindowLifecycle::class)->tickStranded();

    expect($e->fresh()->status)->toBe('active');
});

it('waits while the open shift\'s heartbeats fail together (the mass-offline safeguard)', function () {
    [$e] = swEndedShiftWindow();
    $shift = Shift::factory()->create(['shift_key' => 'evening', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5), 'opened_at' => now()->subHour()]);
    foreach (range(1, 2) as $i) {
        ShiftMember::factory()->for($shift)->create(['user_id' => User::factory()->create(['last_seen_at' => now()->subMinutes(5)])->id, 'status' => 'available', 'joined_at' => now()->subHour()]);
    }

    expect(app(ShiftService::class)->heartbeatsFailing())->toBeTrue();
    app(WindowLifecycle::class)->tickStranded();

    expect($e->fresh()->status)->toBe('active');
});

it('runs in queue:tick', function () {
    Bus::fake([SendQueueMessage::class]);
    [$e] = swEndedShiftWindow();

    $this->artisan('queue:tick')->assertSuccessful();

    expect($e->fresh()->status)->toBe('closed');
});

it('sends no delay apology for a window whose moderator is off shift and gone', function () {
    Bus::fake([SendQueueMessage::class]);
    [$e] = swEndedShiftWindow(seenMinutesAgo: 4, attrs: ['awaiting_reply_since' => now()->subMinutes(4), 'apology_sent_at' => null]);

    app(WindowLifecycle::class)->tickReplies();

    expect($e->fresh()->apology_sent_at)->toBeNull();
    Bus::assertNotDispatched(SendQueueMessage::class, fn ($job) => $job->scriptKey === 'queue_agent_delay_apology');
});

it('still apologises for a moderator on shift', function () {
    Bus::fake([SendQueueMessage::class]);
    $shift = Shift::factory()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHours(5)]);
    $u = User::factory()->create(['last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'delivered_at' => now()->subMinutes(4), 'awaiting_reply_since' => now()->subMinutes(4)]);

    app(WindowLifecycle::class)->tickReplies();

    expect($e->fresh()->apology_sent_at)->not->toBeNull();
});

it('waits while two or more moderators finishing after their shift go dark together, at most 15 minutes', function () {
    Bus::fake([SendQueueMessage::class]);
    [$e1] = swEndedShiftWindow(seenMinutesAgo: 6);
    [$e2] = swEndedShiftWindow(seenMinutesAgo: 6);

    app(WindowLifecycle::class)->tickStranded();
    expect($e1->fresh()->status)->toBe('active')->and($e2->fresh()->status)->toBe('active');

    Carbon::setTestNow(now()->addMinutes(10)); // 16 minutes dark: they went home, not the network
    app(WindowLifecycle::class)->tickStranded();
    expect($e1->fresh()->status)->toBe('closed')->and($e2->fresh()->status)->toBe('closed');
});
