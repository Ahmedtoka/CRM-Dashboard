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
    openMorningShift();
    $e = app(QueueService::class)->enqueue(queueConv(), new HandoverContext('x', 'x', 'medium', null, [], 'unknown'));
    $est = app(WaitEstimator::class);
    $e->update(['eta_seconds' => 290]); $est->tickWaiting($e->fresh()); $est->tickWaiting($e->fresh());
    $bodies = $e->conversation->messages()->where('sender_type', 'bot')->pluck('body');
    expect($bodies->filter(fn ($b) => str_contains($b, '5 دقايق'))->count())->toBe(1);
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
