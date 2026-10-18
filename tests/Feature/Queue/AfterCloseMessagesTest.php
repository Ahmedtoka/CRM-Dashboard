<?php

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\SenderType;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\AfterCloseGuard;
use App\Queue\Jobs\SendClosingMessage;
use App\Queue\Jobs\SendQueueMessage;
use App\Queue\RatingService;
use App\Queue\WindowLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

// Close-cycle hotfixes C1 #1 (D-01, D-02, H-03, H-04): the closing message and the rating are
// re-checked when they are sent, claimed once, and checked again right before the platform call.

const AC_THANKS = 'سعدنا بخدمتك يا فندم، لو احتجتي أي حاجة تانية ابعتيلنا في أي وقت.';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** A window of a logged-in moderator that she closed (inquiry) a moment ago; the reply window is open. */
function acClosed(): array
{
    $shift = Shift::query()->first() ?? Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'available']);
    $e = QueueEntry::factory()->create([
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'closed', 'close_reason' => 'inquiry',
        'closed_at' => now(), 'closed_by_id' => $u->id, 'delivered_at' => now()->subMinutes(3), 'enqueued_at' => now()->subMinutes(5),
        'first_reply_at' => now()->subMinutes(2),
    ]);
    $e->conversation->update(['queue_entry_id' => $e->id, 'handler' => 'bot', 'needs_human' => false, 'last_customer_message_at' => now()->subMinute()]);
    Carbon::setTestNow(now()->addSeconds(10));

    return [$e->fresh(), $u];
}

function acInbound(QueueEntry $e, string $body): Message
{
    return Message::factory()->create([
        'conversation_id' => $e->conversation_id, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => $body, 'status' => MessageStatus::Received,
    ]);
}

function acStaffReply(QueueEntry $e, User $u): Message
{
    return Message::factory()->create([
        'conversation_id' => $e->conversation_id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => $u->id, 'body' => 'نسيت أقولك الشحن 3 أيام',
    ]);
}

function acBotTexts(QueueEntry $e): array
{
    return Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->orderBy('id')->pluck('body')->all();
}

function acRun(SendQueueMessage $job): void
{
    app()->call([$job, 'handle']);
}

it('sends the closing message when nobody wrote since the close (a thanks does not count)', function () {
    [$e] = acClosed();
    acInbound($e, 'شكراً');

    acRun(new SendQueueMessage($e->id, 'queue_closed_thanks', []));

    expect(acBotTexts($e))->toBe([AC_THANKS])->and($e->fresh()->closing_claimed_at)->not->toBeNull();
});

it('drops the closing message when she wrote a real message after the close (D-01)', function () {
    [$e] = acClosed();
    acInbound($e, 'طب والمقاس L موجود؟');

    acRun(new SendQueueMessage($e->id, 'queue_closed_thanks', []));

    expect(acBotTexts($e))->toBe([])->and(AfterCloseGuard::reason($e->fresh()))->toBe('customer_back');
});

it('drops the closing message when a staff member replied in the CRM after the close', function () {
    [$e, $u] = acClosed();
    acStaffReply($e, User::factory()->create());

    acRun(new SendQueueMessage($e->id, 'queue_closed_thanks', []));

    expect(acBotTexts($e))->toBe([])->and(AfterCloseGuard::reason($e->fresh()))->toBe('staff_replied');
});

it('does not count a Business Suite echo as a staff reply yet (H-05)', function () {
    [$e] = acClosed();
    Message::factory()->create([
        'conversation_id' => $e->conversation_id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::User, 'user_id' => null, 'sent_outside_crm' => true,
    ]);

    expect(AfterCloseGuard::reason($e->fresh()))->toBeNull();
});

it('never sends the closing message twice: a retried job, or a second job of the same close', function () {
    [$e] = acClosed();
    $job = new SendQueueMessage($e->id, 'queue_closed_thanks', []);

    acRun($job);
    acRun($job); // tries = 2: the retry after the Message exists
    acRun(new SendQueueMessage($e->id, 'queue_closed_thanks', []));

    expect(acBotTexts($e))->toBe([AC_THANKS]);
});

it('never sends any queue script twice when the job is retried', function () {
    $u = User::factory()->create();
    $e = QueueEntry::factory()->create(['status' => 'active', 'assigned_user_id' => $u->id, 'delivered_at' => now()]);
    $e->conversation->update(['queue_entry_id' => $e->id, 'last_customer_message_at' => now()]);
    $job = new SendQueueMessage($e->id, 'queue_called', ['name' => 'منى']);

    acRun($job);
    acRun($job);
    acRun(new SendQueueMessage($e->id, 'queue_called', ['name' => 'منى'])); // another job: its own send

    expect(acBotTexts($e))->toHaveCount(2);
});

it('re-checks right before the platform call: she wrote between the claim and the send (second hop)', function () {
    [$e] = acClosed();
    Bus::fake([SendOutboundMessage::class]);

    acRun(new SendQueueMessage($e->id, 'queue_closed_thanks', []));

    $send = null;
    Bus::assertDispatched(SendOutboundMessage::class, function (SendOutboundMessage $job) use ($e, &$send) {
        $send = $job;

        return $job->afterCloseOf === $e->id;
    });

    acInbound($e, 'استني عندي سؤال');
    app()->call([$send, 'handle']);

    $thanks = Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->sole();
    expect($thanks->status)->toBe(MessageStatus::Failed)->and($thanks->error)->toBe('stale_after_close');
});

it('lets the second hop send when nothing changed', function () {
    [$e] = acClosed();
    Bus::fake([SendOutboundMessage::class]);
    acRun(new SendQueueMessage($e->id, 'queue_closed_thanks', []));
    $send = Bus::dispatched(SendOutboundMessage::class)->first();

    app()->call([$send, 'handle']);

    $m = Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->sole();
    expect($m->error)->not->toBe('stale_after_close');
});

it('gives the claim back when the reply window closed, so a later close can still thank her', function () {
    [$e] = acClosed();
    $e->conversation->update(['last_customer_message_at' => now()->subDays(3)]);

    acRun(new SendQueueMessage($e->id, 'queue_closed_thanks', []));

    expect(acBotTexts($e))->toBe([])->and($e->fresh()->closing_claimed_at)->toBeNull();
});

it('delays the closing chain a few seconds so in-flight webhooks land first', function () {
    Bus::fake();
    $shift = Shift::factory()->create();
    $u = User::factory()->create(['last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'delivered_at' => now()]);
    $e->conversation->update(['queue_entry_id' => $e->id, 'handler' => 'human', 'last_customer_message_at' => now()]);

    app(WindowLifecycle::class)->close($e, 'inquiry', $u);

    // Close cycle §6.1: the one closing message waits long enough for «تراجع» too.
    Bus::assertDispatched(SendClosingMessage::class, fn (SendClosingMessage $job) => $job->entryId === $e->id
        && $job->delay !== null && now()->diffInSeconds($job->delay) >= SendClosingMessage::DELAY_SECONDS - 1);
});

// ───── the rating (D-02) ─────

it('asks no rating when a staff member replied in the CRM after the close', function () {
    [$e] = acClosed();
    Carbon::setTestNow(now()->addSeconds(60));
    acStaffReply($e, User::factory()->create());

    expect(app(RatingService::class)->skipReason($e->fresh()->load('conversation')))->toBe('staff_replied');
});

it('sends the rating question with the second-hop check', function () {
    [$e] = acClosed();
    Carbon::setTestNow(now()->addSeconds(60));
    Bus::fake([SendOutboundMessage::class]);

    expect(app(RatingService::class)->request($e))->toBeTrue();

    Bus::assertDispatched(SendOutboundMessage::class, fn (SendOutboundMessage $job) => $job->afterCloseOf === $e->id);
});

/** The job as it sat in Redis before this deploy: serialized without `$property`. */
function acLegacyPayload(object $job, string $property): object
{
    $raw = serialize($job);
    $pattern = '/s:'.strlen($property).':"'.preg_quote($property, '/').'";(?:N;|s:\d+:"[^"]*";|i:\d+;)/';
    // SerializesModels already leaves a property at its default out of the payload.
    if (preg_match($pattern, $raw) !== 1) {
        return unserialize($raw);
    }
    $raw = preg_replace($pattern, '', $raw);
    $raw = preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', fn ($m) => 'O:'.$m[1].':"'.$m[2].'":'.((int) $m[3] - 1).':', $raw);

    return unserialize($raw);
}

it('runs jobs queued before the deploy (no send key, no after-close id) instead of crashing', function () {
    [$e] = acClosed();

    $legacy = acLegacyPayload(new SendQueueMessage($e->id, 'queue_closed_thanks', []), 'sendKey');
    expect($legacy->sendKey)->toBeNull();
    acRun($legacy);
    expect(acBotTexts($e))->toBe([AC_THANKS]);

    $m = Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->sole();
    $m->forceFill(['status' => MessageStatus::Queued])->save();
    $send = acLegacyPayload(new SendOutboundMessage($m->id), 'afterCloseOf');
    expect($send->afterCloseOf)->toBeNull();
    app()->call([$send, 'handle']);
    expect($m->fresh()->status)->not->toBe(MessageStatus::Queued);
});
