<?php

use App\Bot\BotEngine;
use App\Bot\Flows\HumanHandover;
use App\Bot\Flows\WaitingReply;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\QueueDay;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Models\UserNotification;
use App\Queue\Data\HandoverContext;
use App\Queue\Jobs\SendQueueMessage;
use App\Queue\QueueScripts;
use App\Queue\QueueService;
use App\Queue\WaitEstimator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

function openMorningShift(int $members = 1): Shift
{
    $shift = Shift::factory()->create();
    ShiftMember::factory()->count($members)->for($shift)->create();

    return $shift;
}

/** A conversation whose reply window is open (the customer just wrote), so the queue's texts can go out. */
function queueConv(): Conversation
{
    return Conversation::factory()->create(['last_customer_message_at' => now()]);
}

it('assigns daily ticket numbers in order and sends the enqueue script', function () {
    openMorningShift();
    $svc = app(QueueService::class);
    $a = $svc->enqueue(queueConv(), new HandoverContext(reason: 'human_request', category: 'human_request', priority: 'medium', topic: 'استرجاع', summaryLines: [], kind: 'case'));
    $b = $svc->enqueue(queueConv(), new HandoverContext(reason: 'human_request', category: 'human_request', priority: 'medium', topic: null, summaryLines: [], kind: 'unknown'));
    expect([$a->ticket_no, $b->ticket_no])->toBe([1, 2])->and($a->business_date->toDateString())->toBe('2026-10-05')
        ->and($a->priority)->toBe('live')->and($a->bot_summary['topic'])->toBe('استرجاع');
    expect($a->conversation->messages()->where('sender_type', 'bot')->latest('id')->first()->body)->toContain('رقم تذكرتك #1')->not->toContain('رقمك في الدور');
});

it('is idempotent per conversation', function () {
    openMorningShift();
    $c = queueConv();
    $ctx = new HandoverContext('human_request', 'human_request', 'medium', null, [], 'unknown');
    $svc = app(QueueService::class);
    expect($svc->enqueue($c, $ctx)->id)->toBe($svc->enqueue($c, $ctx)->id);
});

it('uses the night message with a next-day ticket when no shift is open', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 02:30', 'Africa/Cairo'));
    $e = app(QueueService::class)->enqueue(queueConv(), new HandoverContext('human_request', 'human_request', 'medium', null, [], 'inquiry'));
    expect($e->priority)->toBe('overnight')->and($e->business_date->toDateString())->toBe('2026-10-05');
    expect($e->conversation->messages()->where('sender_type', 'bot')->latest('id')->first()->body)->toContain('خارج مواعيد العمل')->toContain('الساعة 10 الصبح');
});

it('falls back to the legacy notification when the queue is disabled', function () {
    QueueSetting::current()->update(['enabled' => false]);
    $c = queueConv();
    $mod = User::factory()->create(['role' => 'moderator']);
    $mod->userPlatforms()->create(['platform' => $c->platform]);
    app(BotEngine::class)->handover($c, 'human_request', 'عايزة موظفة');
    expect(QueueEntry::count())->toBe(0)->and(UserNotification::where('user_id', $mod->id)->count())->toBe(1);
});

it('does not notify everyone when the queue takes the handover', function () {
    openMorningShift();
    $c = queueConv();
    $mod = User::factory()->create(['role' => 'moderator']);
    $mod->userPlatforms()->create(['platform' => $c->platform]);
    app(BotEngine::class)->handover($c, 'human_request', 'عايزة موظفة');
    expect(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)->and(UserNotification::where('type', 'conversation.handover')->count())->toBe(0)
        ->and($c->fresh()->needs_human)->toBeTrue()->and($c->fresh()->queue_entry_id)->not->toBeNull();
});

it('still alerts supervisors for a high-priority handover the queue takes', function () {
    $shift = openMorningShift();
    $c = queueConv();
    $mod = User::factory()->create(['role' => 'moderator']);
    $mod->userPlatforms()->create(['platform' => $c->platform]);
    app(BotEngine::class)->handover($c, 'complaint', 'مشكلة كبيرة', null, null, ['priority' => 'high', 'category' => 'complaint']);
    expect(UserNotification::where('type', 'conversation.handover_urgent')->where('user_id', $shift->leader_user_id)->count())->toBe(1)
        ->and(UserNotification::where('user_id', $mod->id)->count())->toBe(0);
});

it('sends the 5/3/1 messages once each as the estimate shrinks', function () {
    // One member, one window, busy with a customer delivered just now; a handle takes 10 minutes.
    QueueSetting::current()->update(['eta_default_handle_seconds' => 600]);
    $shift = Shift::factory()->create();
    $c = queueConv();
    $user = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $user->userPlatforms()->create(['platform' => $c->platform]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $user->id, 'windows_cap' => 1, 'status' => 'busy']);
    QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $user->id, 'status' => 'active', 'ticket_no' => 900, 'delivered_at' => now()]);

    $e = app(QueueService::class)->enqueue($c, new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));
    expect($e->eta_seconds)->toBe(600)->and($e->waiting_messages)->toBe([]);

    $est = app(WaitEstimator::class);
    $count = fn (string $needle) => $c->messages()->where('sender_type', 'bot')->pluck('body')->filter(fn ($b) => str_contains($b, $needle))->count();
    $tickAt = function (int $seconds) use ($est, $e, $user) {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo')->addSeconds($seconds));
        $user->forceFill(['last_seen_at' => now()])->save(); // her heartbeat
        $est->tickWaiting($e->fresh());
    };

    // Each message goes out on the very tick the estimate crosses its threshold (no one-tick lag), and only once.
    $tickAt(310);   // 290 s left
    expect($count('5 دقايق'))->toBe(1)->and($e->fresh()->eta_seconds)->toBe(290)->and($count('3 دقايق'))->toBe(0);
    $tickAt(320);   // 280 s
    expect($count('5 دقايق'))->toBe(1)->and($e->fresh()->eta_seconds)->toBe(280);
    $tickAt(430);   // 170 s
    expect($count('3 دقايق'))->toBe(1)->and($count('دقيقة واحدة'))->toBe(0);
    $tickAt(550);
    $tickAt(560);   // 50 s, 40 s
    expect($count('دقيقة واحدة'))->toBe(1)->and($count('5 دقايق'))->toBe(1)->and($count('3 دقايق'))->toBe(1);
});

it('numbers tickets 1, 2, 3 per business day with one row per day', function () {
    $svc = app(QueueService::class);
    expect([$svc->nextTicket('2026-10-05'), $svc->nextTicket('2026-10-05'), $svc->nextTicket('2026-10-05')])->toBe([1, 2, 3])
        ->and($svc->nextTicket('2026-10-06'))->toBe(1)
        ->and($svc->nextTicket('2026-10-05'))->toBe(4)
        ->and(QueueDay::count())->toBe(2)
        ->and(QueueDay::where('date', '2026-10-05')->value('next_ticket'))->toBe(5);
});

it('gives a returning customer at night the night message and no estimate', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 02:30', 'Africa/Cairo'));
    $c = queueConv();
    $c->forceFill(['return_priority_until' => now()->addHour()])->save();
    $svc = app(QueueService::class);
    $a = $svc->enqueue($c, new HandoverContext('returning', 'x', 'medium', null, [], 'unknown'));
    $b = $svc->enqueue(queueConv(), new HandoverContext('returning', 'x', 'medium', null, [], 'unknown'), 'returning');
    expect([$a->priority, $b->priority])->toBe(['overnight', 'overnight'])->and($a->eta_seconds)->toBeNull()
        ->and($c->messages()->where('sender_type', 'bot')->latest('id')->value('body'))->toContain('خارج مواعيد العمل')->toContain('نفتح الساعة 10 الصبح')
        ->not->toContain('الساعة الساعة');
});

it('keeps returning priority while a shift is open', function () {
    $shift = openMorningShift();
    $c = queueConv();
    $busy = User::factory()->create(['last_seen_at' => now()]);
    $busy->userPlatforms()->create(['platform' => $c->platform]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $busy->id, 'windows_cap' => 1, 'status' => 'busy']);
    QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $busy->id, 'status' => 'active', 'delivered_at' => now()]);
    $c->forceFill(['return_priority_until' => now()->addHour()])->save();
    $e = app(QueueService::class)->enqueue($c, new HandoverContext('returning', 'x', 'medium', null, [], 'unknown'));
    expect($e->priority)->toBe('returning')->and($c->messages()->where('sender_type', 'bot')->latest('id')->value('body'))->toContain('أهلاً بيكي تاني');
});

it('lets the queue script replace the working-hours transfer sentence', function () {
    openMorningShift();
    BotSetting::current()->update(['enabled' => true, 'working_hours' => null]);
    $c = queueConv();
    app(HumanHandover::class)->handover($c, null, 'عايزة موظفة');
    $bodies = $c->messages()->where('sender_type', 'bot')->pluck('body');
    expect($bodies)->toHaveCount(1)->and($bodies[0])->toContain('رقم تذكرتك #1');
});

it('refreshes the waiting entry when the customer writes again', function () {
    openMorningShift();
    $c = queueConv();
    $e = app(QueueService::class)->enqueue($c, new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));
    Carbon::setTestNow(now()->addMinutes(2));
    app(QueueService::class)->customerMessage($c);
    expect($e->fresh()->last_customer_message_at->equalTo(now()))->toBeTrue();
});

// ───── flow revision §2: the estimate counts only desks that are logged in ─────

it('promises no minutes when nobody on the open shift is logged in', function () {
    $shift = Shift::factory()->create();
    ShiftMember::factory()->count(2)->for($shift)->create(); // on the roster, never logged in
    $c = queueConv();

    $e = app(QueueService::class)->enqueue($c, new HandoverContext('human_request', 'human_request', 'medium', null, [], 'unknown'));

    $body = $c->messages()->where('sender_type', 'bot')->latest('id')->value('body');
    expect($e->eta_seconds)->toBeNull()->and($e->waiting_messages)->toBe([])
        ->and($body)->toContain('رقم تذكرتك #'.$e->ticket_no)->toContain('الفريق بيبدأ دلوقتي')->not->toContain('دقيقة');
});

it('estimates from logged-in desks only, never from the leader', function () {
    QueueSetting::current()->update(['eta_default_handle_seconds' => 400]);
    $c = queueConv();
    $leader = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]);
    $shift = Shift::factory()->create(['leader_user_id' => $leader->id]);
    ShiftMember::factory()->for($shift)->create(['user_id' => $leader->id]);
    $absent = ShiftMember::factory()->for($shift)->create(); // never logged in, three empty windows
    $absent->user->userPlatforms()->create(['platform' => $c->platform]);
    $here = User::factory()->create(['last_seen_at' => now()]);
    $here->userPlatforms()->create(['platform' => $c->platform]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $here->id, 'windows_cap' => 1, 'status' => 'busy']);
    QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $here->id, 'status' => 'active', 'delivered_at' => now()->subSeconds(100)]);

    $e = app(QueueService::class)->enqueue($c, new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));

    // Only $here counts: her one window frees in 400 − 100 = 300 s (the absent desk's empty windows would say 0).
    expect($e->eta_seconds)->toBe(300);
});

// ───── flow revision §3: she writes while waiting in the lounge ─────

/** A logged-in moderator on every platform, busy with one customer (10-minute chats): the lounge has an estimate. */
function busyDesk(Shift $shift): ShiftMember
{
    QueueSetting::current()->update(['eta_default_handle_seconds' => 600]);
    $u = User::factory()->create(['last_seen_at' => now()]);
    foreach (['facebook', 'instagram', 'whatsapp', 'tiktok'] as $p) {
        $u->userPlatforms()->create(['platform' => $p]);
    }
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'windows_cap' => 1, 'status' => 'busy']);
    QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'ticket_no' => 900, 'delivered_at' => now()]);

    return $m;
}

it('answers a waiting customer who writes with her ticket, who is ahead and the estimate, at most every two minutes', function () {
    $shift = Shift::factory()->create();
    busyDesk($shift);
    $svc = app(QueueService::class);
    $svc->enqueue(queueConv(), new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));   // ticket 1, ahead of her
    $c = queueConv();
    $e = $svc->enqueue($c, new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));      // ticket 2
    $updates = fn () => $c->messages()->where('sender_type', 'bot')->where('body', 'like', '%لسه معاكي%')->pluck('body');
    $writes = function (int $seconds) use ($c, $svc) {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo')->addSeconds($seconds));
        User::query()->update(['last_seen_at' => now()]);   // the busy moderator stays logged in
        $c->forceFill(['last_customer_message_at' => now()])->save();
        $svc->customerMessage($c->fresh());
    };

    $writes(10);
    // One window, 590 s left on it; she is second: 590 + 600 = 1190 s ≈ 20 minutes.
    expect($updates())->toHaveCount(1)
        ->and($updates()->first())->toBe('لسه معاكي 💛 رقم تذكرتك #'.$e->ticket_no.'، وقدامك عميلة واحدة وهنكون معاكي خلال حوالي 20 دقيقة');

    $writes(70);   // a minute later: no reply
    expect($updates())->toHaveCount(1);

    $writes(131);  // two minutes after the first update
    expect($updates())->toHaveCount(2)->and($e->fresh()->position_update_sent_at->equalTo(now()))->toBeTrue();
});

it('leaves the minutes out of the update when there is no estimate', function () {
    openMorningShift(); // nobody logged in
    $c = queueConv();
    $e = app(QueueService::class)->enqueue($c, new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));

    app(QueueService::class)->customerMessage($c);

    $body = $c->messages()->where('sender_type', 'bot')->latest('id')->value('body');
    expect($body)->toContain('رقم تذكرتك #'.$e->ticket_no)->toEndWith('وإنتي أول واحدة في الدور')->not->toContain('خلال')->not->toContain('قدامك 0');
});

it('sends no position update to an overnight customer nor to one already at a window', function () {
    Queue::fake([SendQueueMessage::class]);
    Carbon::setTestNow(Carbon::parse('2026-10-05 02:30', 'Africa/Cairo'));
    $night = queueConv();
    app(QueueService::class)->enqueue($night, new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));
    app(QueueService::class)->customerMessage($night);

    $open = QueueEntry::factory()->create(['status' => 'active', 'assigned_user_id' => User::factory()->create()->id, 'delivered_at' => now()]);
    $open->conversation->update(['queue_entry_id' => $open->id]);
    app(QueueService::class)->customerMessage($open->conversation->fresh());

    Queue::assertNotPushed(SendQueueMessage::class, fn (SendQueueMessage $job) => $job->scriptKey === 'queue_position_update');
});

it('sends no position update while the queue is switched off', function () {
    Queue::fake([SendQueueMessage::class]);
    $waiting = QueueEntry::factory()->create(['status' => 'waiting', 'priority' => 'live']);
    $waiting->conversation->update(['queue_entry_id' => $waiting->id]);
    QueueSetting::current()->update(['enabled' => false]);

    app(QueueService::class)->customerMessage($waiting->conversation->fresh());

    Queue::assertNotPushed(SendQueueMessage::class);
    expect($waiting->fresh()->position_update_sent_at)->toBeNull();
});

it('leaves the reassurance to the queue while she waits in its lounge', function () {
    $c = queueConv();
    $c->forceFill(['handler' => 'human', 'needs_human' => true, 'handover_at' => now()->subMinute()])->save();
    $e = QueueEntry::factory()->create(['conversation_id' => $c->id]);
    $c->forceFill(['queue_entry_id' => $e->id])->save();

    expect(app(WaitingReply::class)->maybeSend($c->fresh()))->toBeFalse()
        ->and($c->messages()->where('sender_type', 'bot')->count())->toBe(0);
});

it('keeps the bot reassurance for a waiting customer while the queue is switched off', function () {
    QueueSetting::current()->update(['enabled' => false]);
    $c = queueConv();
    $c->forceFill(['handler' => 'human', 'needs_human' => true, 'handover_at' => now()->subMinute()])->save();
    $e = QueueEntry::factory()->create(['conversation_id' => $c->id]);
    $c->forceFill(['queue_entry_id' => $e->id])->save();

    expect(app(WaitingReply::class)->maybeSend($c->fresh()))->toBeTrue();
});

// ───── wording: the ticket is named as a ticket, never as a place in line ─────

it('names the ticket and who is ahead in every queue message', function () {
    busyDesk(Shift::factory()->create());
    $svc = app(QueueService::class);
    $svc->enqueue(queueConv(), new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));
    $c = queueConv();
    $e = $svc->enqueue($c, new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));

    expect($c->messages()->where('sender_type', 'bot')->latest('id')->value('body'))
        ->toContain('رقم تذكرتك #'.$e->ticket_no.'، وقدامك عميلة واحدة، وهنكون معاكي خلال حوالي 20 دقيقة')->not->toContain('رقمك في الدور');

    $texts = app(QueueScripts::class);
    expect($texts->text('queue_returning', ['ticket' => 7, 'ahead' => 'قدامك عميلتين', 'eta_minutes' => '9 دقايق']))->toContain('رقم تذكرتك #7 وهنكون معاكي خلال حوالي 9 دقايق')
        ->and($texts->text('queue_enqueued_no_eta', ['ticket' => 7, 'ahead' => 'إنتي أول واحدة في الدور']))->toStartWith('رقم تذكرتك #7')
        ->and($texts->text('queue_night', ['ticket' => 7, 'opening' => 'الساعة 10 الصبح']))->toContain('رقم تذكرتك #7')->not->toContain('رقمك في الدور');
});
