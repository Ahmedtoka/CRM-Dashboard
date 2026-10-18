<?php

use App\Channels\Data\InboundMessageData;
use App\Channels\Data\OutboundEchoData;
use App\Enums\Platform;
use App\Inbox\EchoIngestor;
use App\Inbox\InboxIngestor;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Cases\CaseResolution;
use App\Queue\WindowLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// Close cycle §6.3, §7.1, §8, Q8/Q11/Q12/Q13, M-01, M-05, M-09.

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE-CC']);
    BotSetting::current()->update(['enabled' => false]);
});

function ccIngest(string $id, string $text, ?string $payload = null): ?Message
{
    return app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE-CC', 'PSID-CC', 'Mona', $id, $text, CarbonImmutable::now(), payload: $payload));
}

/** Her real chat with a window of a logged-in moderator on the open shift, answered a minute ago. */
function ccWindow(): array
{
    $c = ccIngest('first', 'عايزة أسأل عن المقاس')->conversation;
    $shift = Shift::query()->first() ?? Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $u->userPlatforms()->create(['platform' => 'facebook']);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create([
        'conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id,
        'status' => 'active', 'window_no' => 1, 'delivered_at' => now()->subMinutes(3), 'first_reply_at' => now()->subMinutes(2), 'last_agent_message_at' => now()->subMinute(),
    ]);
    $c->forceFill(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => false])->save();

    return [$e->fresh(), $u, $c->fresh(), $m];
}

function ccFinish(QueueEntry $e, User $u, array $body = []): void
{
    test()->actingAs($u)->postJson("/inbox/conversations/{$e->conversation_id}/finish", $body + ['entry_id' => $e->id, 'outcome' => 'price'])->assertOk();
}

it('gives her free text inside 30 minutes back to the same moderator, quietly (Q8, Q9)', function () {
    [$e, $u, $c] = ccWindow();
    ccFinish($e, $u);
    $bot = Message::where('conversation_id', $c->id)->where('sender_type', 'bot')->count();
    Carbon::setTestNow(now()->addMinutes(10));
    User::query()->whereKey($u->id)->update(['last_seen_at' => now()]);

    ccIngest('back', 'طب ولو عايزة لون تاني؟');

    $new = QueueEntry::where('conversation_id', $c->id)->latest('id')->first();
    expect($new->id)->not->toBe($e->id)->and($new->assigned_user_id)->toBe($u->id)->and($new->status)->toBe('active')
        ->and($e->fresh()->reopened_at)->not->toBeNull()
        ->and(Message::where('conversation_id', $c->id)->where('sender_type', 'bot')->count())->toBe($bot);
});

it('leaves her to the bot when the moderator is not free, or after 30 minutes, or on a bot button (H-13)', function (string $case) {
    [$e, $u, $c] = ccWindow();
    ccFinish($e, $u);
    match ($case) {
        'offline' => [Carbon::setTestNow(now()->addMinutes(10)), User::query()->whereKey($u->id)->update(['last_seen_at' => now()->subHour()])],
        'late' => Carbon::setTestNow(now()->addMinutes(31)),
        'button' => Carbon::setTestNow(now()->addMinutes(5)),
    };
    User::query()->whereKey($u->id)->when($case !== 'offline', fn ($q) => $q->update(['last_seen_at' => now()]));

    ccIngest('back-'.$case, 'المقاس L', $case === 'button' ? 'size:L' : null);

    expect(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)->and($c->fresh()->handler->value)->toBe('bot');
})->with(['offline', 'late', 'button']);

it('keeps a closed chat resolved when she only says thanks (M-09)', function () {
    [$e, $u, $c] = ccWindow();
    ccFinish($e, $u);
    Carbon::setTestNow(now()->addMinutes(2));

    $unread = $c->fresh()->unread_count;
    ccIngest('thanks', 'شكرا');

    expect($c->fresh()->status->value)->toBe('resolved')->and($c->fresh()->unread_count)->toBe($unread);

    ccIngest('real', 'عندي سؤال تاني');
    expect($c->fresh()->status->value)->toBe('open');
});

it('marks her message on the close so the closing message is skipped, even in the same second (RC-6)', function () {
    [$e, $u, $c] = ccWindow();
    \Illuminate\Support\Facades\Bus::fake([\App\Queue\Jobs\SendClosingMessage::class]);
    ccFinish($e, $u);
    User::query()->whereKey($u->id)->update(['last_seen_at' => now()->subHour()]); // not free: the bot has her (no Q8 reopen)

    ccIngest('same-second', 'استني لسه عندي سؤال');

    expect($e->fresh()->customer_back_at)->not->toBeNull()
        ->and(\App\Queue\AfterCloseGuard::reason($e->fresh()))->toBe('customer_back');
});

it('drains the open windows with the queue switched off: «خلصت» works, the silence still closes (M-05)', function () {
    [$e, $u] = ccWindow();
    $e->forceFill(['last_agent_message_at' => now()->subMinutes(11), 'last_customer_message_at' => now()->subMinutes(12)])->save();
    $e->conversation->forceFill(['last_customer_message_at' => now()->subMinutes(12)])->save();
    $open = QueueEntry::factory()->create(['status' => 'active', 'assigned_user_id' => $u->id, 'window_no' => 2, 'delivered_at' => now()->subMinutes(3)]);
    $open->conversation->update(['handler' => 'human', 'queue_entry_id' => $open->id, 'platform' => 'facebook', 'last_customer_message_at' => now()->subMinute()]);
    QueueSetting::query()->update(['enabled' => false]);

    $this->artisan('queue:tick')->assertSuccessful();
    ccFinish($open, $u);

    expect($e->fresh()->close_reason)->toBe('auto')->and($open->fresh()->status)->toBe('closed');
});

it('hands a customer cancelled from the board back to the bot, with no message (M-01)', function () {
    $sup = User::factory()->create(['role' => 'supervisor']);
    $w = QueueEntry::factory()->create(['status' => 'waiting', 'priority' => 'live']);
    $w->conversation->update(['handler' => 'human', 'needs_human' => true, 'queue_entry_id' => $w->id]);

    $this->actingAs($sup)->postJson("/board/entries/{$w->id}/cancel", ['reason' => 'تجربة'])->assertOk();

    expect($w->fresh()->status)->toBe('cancelled')->and($w->conversation->fresh()->handler->value)->toBe('bot')
        ->and(Message::where('conversation_id', $w->conversation_id)->where('sender_type', 'bot')->count())->toBe(0);
});

it('tells her a bot case was resolved once she is the bot\'s, and waits while she is in a window (Q11, RC-14)', function () {
    [$e, $u, $c] = ccWindow();
    $case = SupportCase::factory()->create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'queue_entry_id' => null, 'status' => 'new']);
    $sup = User::factory()->create(['role' => 'supervisor']);

    app(CaseResolution::class)->setStatus($case, 'resolved', $sup);
    expect(Message::where('conversation_id', $c->id)->where('sender_type', 'bot')->where('body', 'like', '%تم حل طلبك%')->count())->toBe(0);

    $e->forceFill(['status' => 'closed', 'close_reason' => 'inquiry', 'closed_at' => now()])->save();
    $c->forceFill(['handler' => 'bot'])->save();
    (new \App\Queue\Jobs\SendCaseResolved($case->id))->handle(app(\App\Inbox\OutboundService::class), app(\App\Queue\QueueScripts::class), app(\App\Queue\RatingService::class));

    expect(Message::where('conversation_id', $c->id)->where('sender_type', 'bot')->pluck('body')->all())->toContain('تم حل طلبك رقم '.$case->id.'، شكراً لصبرك');
});

it('gives a bot case an SLA, and tells the supervisors when nobody owns it (Q11)', function () {
    $c = ccIngest('first', 'عايزة أرجّع')->conversation;
    $case = app(\App\Cases\CaseRecorder::class)->record($c, 'return', ['order_number' => '1384']);
    $sup = User::factory()->create(['role' => 'supervisor', 'is_active' => true]);
    expect($case->sla_due_at)->not->toBeNull();

    Carbon::setTestNow(now()->addHours(QueueSetting::current()->case_sla_hours + 1));
    app(\App\Queue\Cases\CaseSla::class)->sweep();

    expect($case->fresh()->sla_breached_at)->not->toBeNull()
        ->and(\App\Models\UserNotification::where('type', 'cases.sla_overdue')->where('user_id', $sup->id)->exists())->toBeTrue();
});

it('counts a person\'s Business Suite echo as a reply only with the switch on, and pauses the bot (Q12)', function (bool $on) {
    config(['crm.queue.echo_as_staff' => $on, 'crm.meta.app_id' => 'OUR-APP']);
    $c = ccIngest('first', 'سلام')->conversation;
    $c->forceFill(['handler' => 'bot'])->save();

    app(EchoIngestor::class)->ingest(new OutboundEchoData(Platform::Facebook, 'PAGE-CC', 'PSID-CC', 'echo-1', 'أهلاً من البيزنس سويت', CarbonImmutable::now(), [], '263902037430900'));

    $echo = Message::where('external_id', 'echo-1')->first();
    expect($echo->echo_app_id)->toBe('263902037430900')
        ->and($c->fresh()->bot_paused_until !== null)->toBe($on);
})->with(['off' => false, 'on' => true]);

it('lists human chats nobody holds and hands them back to the bot only with --apply (Q13)', function () {
    [$e, $u, $c] = ccWindow();
    $e->forceFill(['status' => 'closed', 'close_reason' => 'auto', 'closed_at' => now()->subHours(5)])->save();
    $c->forceFill(['handler' => 'human', 'return_priority_until' => null])->save();
    Message::where('conversation_id', $c->id)->update(['created_at' => now()->subHours(5)]);

    $this->artisan('queue:orphans')->assertSuccessful();
    expect($c->fresh()->handler->value)->toBe('human');

    $this->artisan('queue:orphans', ['--apply' => true])->assertSuccessful();
    expect($c->fresh()->handler->value)->toBe('bot');
});

it('closes a stranded window silently with the queue off instead of a lounge nobody serves (review #1)', function () {
    [$e, $u, $c] = ccWindow();
    $e->forceFill(['awaiting_reply_since' => now()->subMinutes(2)])->save();
    \App\Models\Shift::query()->update(['ends_at' => now()->subMinute()]);
    User::query()->whereKey($u->id)->update(['last_seen_at' => now()->subHour()]);
    QueueSetting::query()->update(['enabled' => false]);

    $this->artisan('queue:tick')->assertSuccessful();

    expect($e->fresh()->close_reason)->toBe('auto')->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)
        ->and($c->fresh()->handler->value)->toBe('bot')
        ->and(Message::where('conversation_id', $c->id)->where('sender_type', 'bot')->count())->toBe(0);
});
