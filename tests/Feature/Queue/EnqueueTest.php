<?php

use App\Bot\BotEngine;
use App\Bot\Flows\HumanHandover;
use App\Models\{BotSetting, Conversation, QueueEntry, QueueSetting, Shift, ShiftMember, User, UserNotification};
use App\Queue\{QueueService, WaitEstimator};
use App\Queue\Data\HandoverContext;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

function openMorningShift(int $members = 1): Shift {
    $shift = Shift::factory()->create();
    ShiftMember::factory()->count($members)->for($shift)->create();
    return $shift;
}

/** A conversation whose reply window is open (the customer just wrote), so the queue's texts can go out. */
function queueConv(): Conversation {
    return Conversation::factory()->create(['last_customer_message_at' => now()]);
}

it('assigns daily ticket numbers in order and sends the enqueue script', function () {
    openMorningShift();
    $svc = app(QueueService::class);
    $a = $svc->enqueue(queueConv(), new HandoverContext(reason: 'human_request', category: 'human_request', priority: 'medium', topic: 'استرجاع', summaryLines: [], kind: 'case'));
    $b = $svc->enqueue(queueConv(), new HandoverContext(reason: 'human_request', category: 'human_request', priority: 'medium', topic: null, summaryLines: [], kind: 'unknown'));
    expect([$a->ticket_no, $b->ticket_no])->toBe([1, 2])->and($a->business_date->toDateString())->toBe('2026-10-05')
        ->and($a->priority)->toBe('live')->and($a->bot_summary['topic'])->toBe('استرجاع');
    expect($a->conversation->messages()->where('sender_type', 'bot')->latest('id')->first()->body)->toContain('رقمك في الدور 1');
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
    $user = User::factory()->create(['role' => 'moderator']);
    $user->userPlatforms()->create(['platform' => $c->platform]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $user->id, 'windows_cap' => 1, 'status' => 'busy']);
    QueueEntry::factory()->create(['shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $user->id, 'status' => 'active', 'ticket_no' => 900, 'delivered_at' => now()]);

    $e = app(QueueService::class)->enqueue($c, new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));
    expect($e->eta_seconds)->toBe(600)->and($e->waiting_messages)->toBe([]);

    $est = app(WaitEstimator::class);
    $count = fn (string $needle) => $c->messages()->where('sender_type', 'bot')->pluck('body')->filter(fn ($b) => str_contains($b, $needle))->count();
    $tickAt = function (int $seconds) use ($est, $e) {
        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo')->addSeconds($seconds));
        $est->tickWaiting($e->fresh());
    };

    // Each message goes out on the very tick the estimate crosses its threshold (no one-tick lag), and only once.
    $tickAt(310);   // 290 s left
    expect($count('5 دقايق'))->toBe(1)->and($e->fresh()->eta_seconds)->toBe(290)->and($count('3 دقايق'))->toBe(0);
    $tickAt(320);   // 280 s
    expect($count('5 دقايق'))->toBe(1)->and($e->fresh()->eta_seconds)->toBe(280);
    $tickAt(430);   // 170 s
    expect($count('3 دقايق'))->toBe(1)->and($count('دقيقة واحدة'))->toBe(0);
    $tickAt(550); $tickAt(560);   // 50 s, 40 s
    expect($count('دقيقة واحدة'))->toBe(1)->and($count('5 دقايق'))->toBe(1)->and($count('3 دقايق'))->toBe(1);
});

it('numbers tickets 1, 2, 3 per business day with one row per day', function () {
    $svc = app(QueueService::class);
    expect([$svc->nextTicket('2026-10-05'), $svc->nextTicket('2026-10-05'), $svc->nextTicket('2026-10-05')])->toBe([1, 2, 3])
        ->and($svc->nextTicket('2026-10-06'))->toBe(1)
        ->and($svc->nextTicket('2026-10-05'))->toBe(4)
        ->and(\App\Models\QueueDay::count())->toBe(2)
        ->and(\App\Models\QueueDay::where('date', '2026-10-05')->value('next_ticket'))->toBe(5);
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
    openMorningShift();
    $c = queueConv();
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
    expect($bodies)->toHaveCount(1)->and($bodies[0])->toContain('رقمك في الدور 1');
});

it('refreshes the waiting entry when the customer writes again', function () {
    openMorningShift();
    $c = queueConv();
    $e = app(QueueService::class)->enqueue($c, new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));
    Carbon::setTestNow(now()->addMinutes(2));
    app(QueueService::class)->customerMessage($c);
    expect($e->fresh()->last_customer_message_at->equalTo(now()))->toBeTrue();
});
