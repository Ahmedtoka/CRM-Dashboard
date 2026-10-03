<?php

use App\Analytics\ActivityLogger;
use App\Bot\BotEngine;
use App\Bot\Flow\Jobs\RunBotTurn;
use App\Bot\Flows\FlowState;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Events\MessageCreated;
use App\Inbox\InboxIngestor;
use App\Inbox\OutboundService;
use App\Models\ActivityLog;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Customer;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Acknowledgement;
use App\Queue\QueueService;
use App\Queue\WindowLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** A customer message through the real ingest (Messenger page PAGE-ACK, customer PSID-ACK). */
function ackIngest(string $id, string $text, array $attachments = [], bool $bot = false): ?Message
{
    ChannelAccount::query()->firstWhere('external_id', 'PAGE-ACK') ?? ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE-ACK']);
    BotSetting::current()->update(['enabled' => $bot]);

    return app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE-ACK', 'PSID-ACK', 'Mona', $id, $text, CarbonImmutable::now(), $attachments));
}

/** Her conversation (made by a real message at 12:00) in an open window of a logged-in moderator who may reply on Messenger; not answered yet. */
function ackWindow(): array
{
    $c = ackIngest('first', 'عايزة أسأل عن المقاس')->conversation;
    $shift = Shift::query()->first() ?? Shift::factory()->create();
    $u = User::factory()->create(['last_seen_at' => now()]);
    $u->userPlatforms()->create(['platform' => $c->platform->value]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create([
        'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id,
        'status' => 'active', 'window_no' => 1, 'delivered_at' => now(), 'enqueued_at' => now()->subMinutes(2),
        'last_customer_message_at' => now(), 'awaiting_reply_since' => now(),
    ]);
    $c->forceFill(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true])->save();

    return [$e, $u, $c->fresh()];
}

/** Her answer 10 seconds later: the reply clock stops, the customer-silence clock starts. */
function ackReply(QueueEntry $e, User $u): void
{
    Carbon::setTestNow(now()->addSeconds(10));
    app(OutboundService::class)->sendHuman($e->conversation()->firstOrFail(), $u, 'المقاس L متاح يا فندم');
}

// ───── The detector ─────

it('knows an acknowledgement from a request', function (string $body, array $attachments, bool $ack) {
    expect(app(Acknowledgement::class)->matchesContent($body, $attachments))->toBe($ack);
})->with([
    'شكراً' => ['شكراً', [], true],
    'thanks and a flower' => ['شكرا 🌸', [], true],
    'feminine thanks, very' => ['متشكرة جداً ❤️', [], true],
    'تسلمي يا قمر' => ['تسلمي يا قمر', [], true],
    'تسلم إيدك' => ['تسلم إيدك', [], true],
    'تمام' => ['تمام', [], true],
    'تمام وشكراً' => ['تمام وشكراً', [], true],
    'elongated' => ['شكراااا', [], true],
    'اوك' => ['اوك', [], true],
    'OK and a like' => ['OK 👍', [], true],
    'thanks' => ['thanks', [], true],
    'thank you so much' => ['Thank you so much!', [], true],
    'ميرسي خالص' => ['ميرسي خالص', [], true],
    'a heart' => ['❤️', [], true],
    'two likes' => ['👍👍', [], true],
    'flowers' => ['🌸🌸🌸', [], true],
    'Messenger like (a sticker)' => ['', [['type' => 'sticker', 'url' => null, 'sticker_id' => '369239263222822']], true],
    'WhatsApp sticker' => ['', [['type' => 'sticker', 'id' => 'wamid-sticker']], true],
    'thanks, then a question' => ['شكراً، طب المقاس L موجود؟', [], false],
    'thanks, then a complaint' => ['شكرا بس الأوردر ماوصلش', [], false],
    'تمام as a question' => ['تمام؟', [], false],
    'a request' => ['عايزة أرجّع الفستان', [], false],
    'a number' => ['5', [], false],
    'ok and more' => ['ok send me the link', [], false],
    'a photo' => ['', [['type' => 'image', 'url' => 'https://example.test/p.jpg']], false],
    'thanks with a photo' => ['شكراً', [['type' => 'image', 'url' => 'https://example.test/p.jpg']], false],
    'nothing at all' => ['', [], false],
    'a filler alone' => ['يا فندم', [], false],
    'ta marbuta written as ha' => ['متشكره', [], true],
    'tatweel' => ['شكـــرا', [], true],
    'و joined to تسلمي' => ['وتسلمي', [], true],
    'تمام and a size' => ['تمام L', [], false],
    'اوك and a number' => ['اوك 2', [], false],
]);

it('never takes a button tap for an acknowledgement', function () {
    expect(app(Acknowledgement::class)->matches(new Message(['body' => 'تمام', 'payload' => 'menu:ok'])))->toBeFalse()
        ->and(app(Acknowledgement::class)->matches(new Message(['body' => 'تمام'])))->toBeTrue();
});

it('reads its words from the config, so the list can grow', function () {
    expect(app(Acknowledgement::class)->matchesContent('تشكراتي يا فندم'))->toBeFalse();

    config(['crm.queue.acknowledgements.phrases' => [...config('crm.queue.acknowledgements.phrases'), 'تشكراتي']]);

    expect(app(Acknowledgement::class)->matchesContent('تشكراتي يا فندم'))->toBeTrue();
});

// ───── In an open window ─────

it('starts no reply clock for a thanks after her answer, and the silence clock keeps running from the answer', function () {
    [$e, $u, $c] = ackWindow();
    ackReply($e, $u);                                   // 12:00:10
    expect($e->fresh()->awaiting_reply_since)->toBeNull();

    Carbon::setTestNow(now()->addSeconds(20));          // 12:00:30
    $unread = $c->fresh()->unread_count;
    ackIngest('thanks', 'شكراً ❤️');

    $e->refresh();
    expect($e->awaiting_reply_since)->toBeNull()
        ->and($e->last_ack_at->equalTo(now()))->toBeTrue()
        ->and($c->fresh()->unread_count)->toBe($unread + 1)   // in an open window the moderator still sees it
        ->and(WindowLifecycle::idleSeconds($e))->toBe(20);    // counted from her answer at 12:00:10

    Carbon::setTestNow(now()->addSeconds(161));         // 12:03:11: 181 s after her answer
    app(WindowLifecycle::class)->tickReplies();
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->apology_sent_at)->toBeNull()->and($e->fresh()->silence_warned_at)->not->toBeNull();

    Carbon::setTestNow(now()->addSeconds(120));         // 12:05:11: 301 s
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->close_reason)->toBe('auto');
});

it('starts the reply clock for a real message, also one that begins with thanks', function () {
    [$e, $u] = ackWindow();
    ackReply($e, $u);
    Carbon::setTestNow(now()->addSeconds(20));

    ackIngest('mixed', 'شكراً، طب المقاس XL موجود؟');

    expect($e->fresh()->awaiting_reply_since->equalTo(now()))->toBeTrue()
        ->and($e->fresh()->last_ack_at)->toBeNull()
        ->and(WindowLifecycle::idleSeconds($e->fresh()))->toBeNull();
});

it('leaves a running reply clock as it was when she only says thanks before the answer', function () {
    [$e] = ackWindow();                                 // delivered 12:00:00, not answered
    Carbon::setTestNow(now()->addSeconds(30));

    ackIngest('thanks-early', 'تمام 👍');

    expect($e->fresh()->awaiting_reply_since->equalTo(now()->subSeconds(30)))->toBeTrue();
});

it('sends no position reply for a thanks in the lounge, and one for a real message', function () {
    $c = ackIngest('first', 'عايزة موظفة')->conversation;
    $e = QueueEntry::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'status' => 'waiting', 'priority' => 'live', 'enqueued_at' => now()]);
    $c->forceFill(['queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true])->save();

    ackIngest('thanks-lounge', 'تمام شكراً');
    expect($e->fresh()->position_update_sent_at)->toBeNull();

    ackIngest('question-lounge', 'فاضل كتير؟');
    expect($e->fresh()->position_update_sent_at)->not->toBeNull();
});

// ───── After the close ─────

// Addendum C2: an acknowledgement never reopens after ANY close, a manual «خلصت» (inquiry) or
// an automatic one (the conversation stays with a person, inside the return window).
it('keeps a thanks, a heart, an English thanks or a like after the close in the thread, reopening nothing', function (string $reason, string $text, array $attachments) {
    [$e, $u, $c] = ackWindow();
    app(WindowLifecycle::class)->close($e, $reason, $reason === 'auto' ? null : $u);
    Carbon::setTestNow(now()->addMinutes(5));           // inside the confirm / return window
    $unread = $c->fresh()->unread_count;

    $msg = ackIngest('after-'.md5($text.json_encode($attachments)), $text, $attachments);

    expect($msg)->not->toBeNull()
        ->and(Message::whereKey($msg->id)->exists())->toBeTrue()
        ->and($c->fresh()->unread_count)->toBe($unread)
        ->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)
        ->and($e->fresh()->reversed_at)->toBeNull()
        ->and($c->fresh()->queue_entry_id)->toBe($e->id)
        ->and(ActivityLog::where('action', ActivityLogger::QUEUE_ENQUEUE)->count())->toBe(0);
})->with(['inquiry', 'auto'])->with([
    'thanks' => ['شكراً يا فندم 🌸', []],
    'a heart' => ['❤️', []],
    'english' => ['Thank you so much!', []],
    'a like' => ['', [['type' => 'sticker', 'url' => null, 'sticker_id' => '369239263222822']]],
]);

// Addendum C2: after a MANUAL close the chat goes back to the bot (Task 2), so the direct return
// with priority is the AUTO close's path; it is the one tested here.
it('still puts her back in the queue for a real message after an automatic close, also one that starts with thanks', function () {
    [$e, $u, $c] = ackWindow();
    app(WindowLifecycle::class)->close($e, 'auto');
    $u->update(['last_seen_at' => now()->subHour()]);   // the new ticket waits in the lounge
    Carbon::setTestNow(now()->addMinutes(5));

    ackIngest('thanks-first', 'شكراً ❤️');
    expect(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1);

    ackIngest('then-question', 'شكراً، طب المقاس L موجود؟');
    $new = QueueEntry::where('conversation_id', $c->id)->latest('id')->first();
    expect($new->id)->not->toBe($e->id)->and($new->priority)->toBe('returning')->and($new->reopened_from_entry_id)->toBe($e->id);
});

it('gives a thanks no bot turn once the closed chat is back with the bot, and a real message one', function () {
    Bus::fake([RunBotTurn::class]);
    [$e, $u, $c] = ackWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    app(BotEngine::class)->returnToBot($c->fresh(), $u);   // «رجوع للبوت» after «خلصت»

    ackIngest('thanks-bot', 'تمام 👍', bot: true);
    Bus::assertNotDispatched(RunBotTurn::class);

    ackIngest('question-bot', 'عندكم فساتين سواريه؟', bot: true);
    Bus::assertDispatched(RunBotTurn::class);
});

it('gives «تمام» a bot turn when the bot is waiting for her answer after the close', function (string $waiting) {
    Bus::fake([RunBotTurn::class]);
    [$e, $u, $c] = ackWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    app(BotEngine::class)->returnToBot($c->fresh(), $u);

    $c = $c->fresh();
    match ($waiting) {
        'flow' => FlowState::put($c, ['key' => 'order_status', 'step' => 'order', 'data' => []]),
        'confirm' => FlowState::setConfirm($c, 'order_status'),
    };

    ackIngest('answer-bot', 'تمام', bot: true);

    Bus::assertDispatched(RunBotTurn::class);
})->with(['flow', 'confirm']);

it('lets the bot answer a thanks again a day after the close', function () {
    Bus::fake([RunBotTurn::class]);
    [$e, $u, $c] = ackWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    app(BotEngine::class)->returnToBot($c->fresh(), $u);
    Carbon::setTestNow(now()->addHours(25));

    ackIngest('thanks-next-day', 'شكراً', bot: true);

    Bus::assertDispatched(RunBotTurn::class);
});

it('still broadcasts a settled message to the inbox', function () {
    [$e, $u] = ackWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    Event::fake([MessageCreated::class]);

    $msg = ackIngest('thanks-broadcast', 'شكراً');

    Event::assertDispatched(MessageCreated::class, fn (MessageCreated $ev) => $ev->message->id === $msg->id);
});

// ───── A close that commits while her thanks is being ingested (review fix 1) ─────

it('settles a thanks whose window closes while it is being ingested: no ticket, no reversal, not unread, no bot turn', function (string $reason) {
    Bus::fake([RunBotTurn::class]);
    [$e, $u, $c] = ackWindow();
    ackReply($e, $u);
    Carbon::setTestNow(now()->addMinutes(2));
    $unread = $c->fresh()->unread_count;

    // The close commits after the ingest stored her message and before the queue hook runs
    // (her customer row is saved between the two).
    Customer::saved(function () use ($e, $u, $reason) {
        if (Message::where('external_id', 'thanks-race')->exists() && $e->fresh()->status === 'active') {
            app(WindowLifecycle::class)->close($e->fresh(), $reason, $reason === 'auto' ? null : $u);
        }
    });

    ackIngest('thanks-race', 'شكراً ❤️', bot: true);

    expect($e->fresh()->status)->toBe('closed')
        ->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)
        ->and($e->fresh()->reversed_at)->toBeNull()
        ->and($c->fresh()->unread_count)->toBe($unread)
        ->and(ActivityLog::where('action', ActivityLogger::QUEUE_ENQUEUE)->count())->toBe(0);
    Bus::assertNotDispatched(RunBotTurn::class);
})->with(['inquiry', 'auto']);

it('settles under the lock when the caller still holds the open snapshot', function () {
    [$e, $u, $c] = ackWindow();
    $stale = $c->fresh();                                   // read while the window was open
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);

    $settled = app(QueueService::class)->settles($stale, new Message(['body' => 'شكراً']), true);

    expect($settled)->toBeTrue()
        ->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)
        ->and($e->fresh()->reversed_at)->toBeNull();
});

// ───── Queue off ─────

it('changes nothing while the queue is off', function () {
    [$e, $u, $c] = ackWindow();
    ackReply($e, $u);
    QueueSetting::current()->update(['enabled' => false]);
    Carbon::setTestNow(now()->addSeconds(20));

    ackIngest('thanks-off', 'شكراً');
    expect($e->fresh()->awaiting_reply_since->equalTo(now()))->toBeTrue()->and($e->fresh()->last_ack_at)->toBeNull();

    QueueSetting::current()->update(['enabled' => true]);
    app(WindowLifecycle::class)->close($e->fresh(), 'inquiry', $u);
    QueueSetting::current()->update(['enabled' => false]);
    $unread = $c->fresh()->unread_count;

    ackIngest('thanks-off-closed', 'شكراً');
    expect($c->fresh()->unread_count)->toBe($unread + 1)->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1);
});
