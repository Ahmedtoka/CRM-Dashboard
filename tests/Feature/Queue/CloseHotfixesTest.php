<?php

use App\Bot\BotEngine;
use App\Bot\Flow\Jobs\RunBotTurn;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Inbox\ConversationActions;
use App\Inbox\InboxIngestor;
use App\Inbox\OutboundService;
use App\Inbox\Outcomes\Outcome;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\ConversationOutcome;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Events\WindowClosed;
use App\Queue\Jobs\RequestRating;
use App\Queue\QueueService;
use App\Queue\WindowLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

// Close-cycle hotfixes C1 #4, #5, #7 (docs/round-2026-10-17/close-cycle.md §5.5).

const CH_THANKS = 'سعدنا بخدمتك يا فندم، لو احتجتي أي حاجة تانية ابعتيلنا في أي وقت.';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** An open window of a logged-in moderator on the open shift; the customer just wrote. */
function chWindow(array $attrs = []): array
{
    $shift = Shift::query()->first() ?? Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $u->userPlatforms()->create(['platform' => 'facebook']);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create($attrs + [
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1,
        'delivered_at' => now(), 'enqueued_at' => now()->subMinutes(2), 'last_customer_message_at' => now(),
    ]);
    $e->conversation->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true, 'last_customer_message_at' => now(), 'platform' => 'facebook']);

    return [$e->fresh(), $u, $m];
}

function chBotTexts(QueueEntry $e): array
{
    return Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->orderBy('id')->pluck('body')->all();
}

// ───── #4 (D-21): no second case for the same problem ─────

it('links her open case on a «كيس» close instead of opening a second one', function () {
    [$e, $u] = chWindow();
    $open = SupportCase::factory()->create(['conversation_id' => $e->conversation_id, 'customer_id' => $e->customer_id, 'type' => 'return', 'queue_entry_id' => null]);
    $e->forceFill(['open_case_id' => $open->id])->save();

    app(WindowLifecycle::class)->close($e->fresh(), 'case', $u, ['case_type' => 'complaint']);

    expect(SupportCase::count())->toBe(1)
        ->and($e->fresh()->support_case_id)->toBe($open->id)
        ->and($e->fresh()->close_reason)->toBe('case')
        ->and(chBotTexts($e))->toBe([CH_THANKS]);
});

it('links her open case found on her customer record, opened after she was queued', function () {
    [$e, $u] = chWindow();
    $open = SupportCase::factory()->create(['customer_id' => $e->conversation->customer_id, 'status' => 'in_progress']);

    app(WindowLifecycle::class)->close($e, 'case', $u, ['case_type' => 'complaint']);

    expect(SupportCase::count())->toBe(1)->and($e->fresh()->support_case_id)->toBe($open->id);
});

it('still opens a new case when her earlier one is resolved or closed', function () {
    [$e, $u] = chWindow();
    SupportCase::factory()->create(['customer_id' => $e->conversation->customer_id, 'status' => 'resolved', 'resolved_at' => now()->subDay()]);
    SupportCase::factory()->create(['customer_id' => $e->conversation->customer_id, 'status' => 'closed']);

    app(WindowLifecycle::class)->close($e, 'case', $u, ['case_type' => 'complaint']);

    $new = SupportCase::query()->latest('id')->first();
    expect(SupportCase::count())->toBe(3)->and($e->fresh()->support_case_id)->toBe($new->id)
        ->and(chBotTexts($e)[0])->toContain('فتحنالك طلب رقم '.$new->id);
});

// ───── #5 (D-03): «رجوع للبوت» from the web sends no thanks and no rating ─────

it('closes with its reason and outcome on the way back to the bot, with no thanks and no rating', function () {
    Bus::fake([RequestRating::class]);
    [$e, $u] = chWindow();

    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'browsing', 'to_bot' => true])
        ->assertOk()->assertJsonPath('data.close_reason', 'inquiry');

    expect($e->fresh()->status)->toBe('closed')->and($e->fresh()->close_reason)->toBe('inquiry')
        ->and(chBotTexts($e))->toBe([])
        ->and($e->conversation->fresh()->handler->value)->toBe('bot')
        ->and(ConversationOutcome::where('conversation_id', $e->conversation_id)->value('outcome'))->toBe('browsing');
    Bus::assertNotDispatched(RequestRating::class);
});

it('still thanks and rates a plain «خلصت»', function () {
    Bus::fake([RequestRating::class]);
    [$e, $u] = chWindow();

    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'browsing', 'to_bot' => false])->assertOk();

    expect(chBotTexts($e))->toBe([CH_THANKS]);
    Bus::assertDispatched(RequestRating::class);
});

it('takes to_bot on the mobile API too, with the same response shape', function () {
    [$e, $u] = chWindow();
    Sanctum::actingAs($u);

    $this->postJson("/api/v1/queue/entries/{$e->id}/close", ['reason' => 'problem', 'outcome' => 'service', 'to_bot' => true])
        ->assertOk()->assertJsonStructure(['data' => ['id', 'close_reason', 'support_case_id']]);

    expect(chBotTexts($e))->toBe([]);
});

it('returns a window to the bot in one step: a message arriving meanwhile never lands in the lounge (M-06)', function () {
    [$e, $u] = chWindow();
    // Her message is ingested the moment the close committed (the listener runs after the commit).
    Event::listen(WindowClosed::class, function () use ($e) {
        $m = Message::factory()->create(['conversation_id' => $e->conversation_id, 'direction' => 'in', 'sender_type' => 'customer', 'body' => 'طب والمقاس؟', 'status' => 'received']);
        app(QueueService::class)->settles($e->conversation->fresh(), $m);
    });

    app(BotEngine::class)->returnToBot($e->conversation->fresh(), $u);

    // Nothing open, the bot has her: her next message is the bot's, never a new ticket.
    $c = $e->conversation->fresh();
    expect($e->fresh()->close_reason)->toBe('cancelled')->and($c->handler->value)->toBe('bot')
        ->and(app(QueueService::class)->customerReturned($c))->toBeFalse()
        ->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1);
});

// ───── #7 (D-08 + H-01): auto-close and «حل» give the chat back to the bot; inside the return window she still goes back to her moderator ─────

/** Her conversation through the real ingest (page PAGE-CH, customer PSID-CH) in an open window of a logged-in moderator. */
function chIngestWindow(): array
{
    ChannelAccount::query()->firstWhere('external_id', 'PAGE-CH') ?? ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE-CH']);
    BotSetting::current()->update(['enabled' => false]);
    $c = chIngest('first', 'عايزة أسأل عن المقاس')->conversation;
    [$e, $u] = chWindow();
    $e->forceFill(['conversation_id' => $c->id, 'customer_id' => $c->customer_id])->save();
    $c->forceFill(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true])->save();

    return [$e->fresh(), $u, $c->fresh()];
}

function chIngest(string $id, string $text): ?Message
{
    return app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE-CH', 'PSID-CH', 'Mona', $id, $text, CarbonImmutable::now()));
}

/** The moderator answered at 12:00:10 and the customer stayed silent: the tick auto-closes at 12:05:11. */
function chAutoClose(QueueEntry $e, User $u): void
{
    Carbon::setTestNow(now()->addSeconds(10));
    app(OutboundService::class)->sendHuman($e->conversation->fresh(), $u, 'المقاس متاح');
    Carbon::setTestNow(now()->addSeconds(301));
    User::query()->update(['last_seen_at' => now()]);
    app(WindowLifecycle::class)->tickSilence();
    expect($e->fresh()->close_reason)->toBe('auto');
}

it('gives the chat back to the bot on the silence auto-close, inside the close (D-08)', function () {
    [$e, $u, $c] = chIngestWindow();

    chAutoClose($e, $u);

    $c->refresh();
    expect($c->handler->value)->toBe('bot')->and($c->needs_human)->toBeFalse()
        ->and($c->return_priority_until->isFuture())->toBeTrue();
});

it('sends her real message inside the return window back to the same moderator, not to the bot (H-01)', function () {
    [$e, $u, $c] = chIngestWindow();
    Bus::fake([RunBotTurn::class]);
    BotSetting::current()->update(['enabled' => true]);
    chAutoClose($e, $u);
    Carbon::setTestNow(now()->addMinutes(2));
    User::query()->update(['last_seen_at' => now()]);

    chIngest('back', 'أنا رجعت، طب الألوان؟');

    $new = QueueEntry::where('conversation_id', $c->id)->latest('id')->first();
    expect($new->id)->not->toBe($e->id)->and($new->priority)->toBe('returning')
        ->and($new->reopened_from_entry_id)->toBe($e->id)
        ->and($c->fresh()->handler->value)->toBe('human');
    expect(in_array($new->fresh()->status, ['waiting', 'active'], true))->toBeTrue()
        ->and((int) ($new->fresh()->reserved_user_id ?? $new->fresh()->assigned_user_id))->toBe($u->id);
    Bus::assertNotDispatched(RunBotTurn::class);
});

it('lets the bot answer once the return window is over: no ticket for ever (D-08)', function () {
    [$e, $u, $c] = chIngestWindow();
    Bus::fake([RunBotTurn::class]);
    BotSetting::current()->update(['enabled' => true]);
    chAutoClose($e, $u);
    Carbon::setTestNow(now()->addDays(7));

    chIngest('week-later', 'عندكم فساتين سواريه؟');

    expect(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)->and($c->fresh()->handler->value)->toBe('bot');
    Bus::assertDispatched(RunBotTurn::class);
});

it('keeps a thanks inside the return window quiet: no ticket, no bot turn', function () {
    [$e, $u, $c] = chIngestWindow();
    Bus::fake([RunBotTurn::class]);
    BotSetting::current()->update(['enabled' => true]);
    chAutoClose($e, $u);
    Carbon::setTestNow(now()->addMinutes(1));

    chIngest('thanks', 'شكراً');

    expect(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1);
    Bus::assertNotDispatched(RunBotTurn::class);
});

it('gives the chat back to the bot on «حل» while the queue is on, under the conversation lock (D-08)', function () {
    [$e, $u, $c] = chIngestWindow();
    Bus::fake([RunBotTurn::class]);
    BotSetting::current()->update(['enabled' => true]);
    $boss = User::factory()->create(['role' => 'supervisor']);

    app(ConversationActions::class)->resolve($c->fresh(), $boss, Outcome::Browsing);

    $c->refresh();
    expect($e->fresh()->close_reason)->toBe('resolved_elsewhere')->and($c->status->value)->toBe('resolved')
        ->and($c->handler->value)->toBe('bot')->and($c->needs_human)->toBeFalse();

    Carbon::setTestNow(now()->addHour());
    chIngest('after-resolve', 'عندكم توصيل لإسكندرية؟');
    expect(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1);
    Bus::assertDispatched(RunBotTurn::class);
});

it('keeps «حل» as it was while the queue is off (a legacy human chat stays human)', function () {
    QueueSetting::query()->update(['enabled' => false]);
    [$e, $u, $c] = chIngestWindow();

    app(ConversationActions::class)->resolve($c->fresh(), User::factory()->create(['role' => 'supervisor']), Outcome::Browsing);

    expect($c->fresh()->handler->value)->toBe('human');
});

// ───── race: the timer against the staff ─────

it('lets exactly one of the tick and «خلصت» close the window, whoever commits first', function () {
    [$e, $u, $c] = chIngestWindow();
    Carbon::setTestNow(now()->addSeconds(10));
    app(OutboundService::class)->sendHuman($c->fresh(), $u, 'المقاس متاح');
    Carbon::setTestNow(now()->addSeconds(301));
    User::query()->update(['last_seen_at' => now()]);

    // «خلصت» commits while the tick is between its read and its lock.
    $staleRead = QueueEntry::with('conversation')->find($e->id);
    $this->actingAs($u)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry', 'outcome' => 'browsing'])->assertOk();
    (fn () => $this->closeIfSilent($staleRead, QueueSetting::current()))->call(app(WindowLifecycle::class));

    expect($e->fresh()->close_reason)->toBe('inquiry')
        ->and(chBotTexts($e))->toBe([CH_THANKS])
        ->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1);
});
