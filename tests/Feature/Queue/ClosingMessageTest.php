<?php

use App\Bot\BotEngine;
use App\Bot\Flow\Jobs\RunBotTurn;
use App\Bot\Flows\FlowScripts;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Inbox\InboxIngestor;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Customer;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\Shift;
use App\Models\ShiftMember;
use App\Models\User;
use App\Queue\Jobs\SendQueueMessage;
use App\Queue\WindowLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

// Addendum C4: the closing message has no emoji.
const CM_CLOSING_TEXT = 'سعدنا بخدمتك يا فندم، لو احتجتي أي حاجة تانية ابعتيلنا في أي وقت.';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00', 'Africa/Cairo'));
    QueueSetting::factory()->create(['id' => 1, 'enabled' => true]);
});

/** An open window of a logged-in moderator on the open shift; the customer just wrote (the reply window is open). */
function cmWindow(): array
{
    $shift = Shift::query()->first() ?? Shift::factory()->create();
    $u = User::factory()->create(['role' => 'moderator', 'last_seen_at' => now()]);
    $m = ShiftMember::factory()->for($shift)->create(['user_id' => $u->id, 'status' => 'busy']);
    $e = QueueEntry::factory()->create([
        'shift_id' => $shift->id, 'shift_member_id' => $m->id, 'assigned_user_id' => $u->id, 'status' => 'active', 'window_no' => 1,
        'delivered_at' => now(), 'enqueued_at' => now()->subMinutes(2), 'last_customer_message_at' => now(),
    ]);
    $e->conversation->update(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true, 'last_customer_message_at' => now()]);

    return [$e, $u];
}

/** What the customer got from the bot in this conversation, oldest first. */
function cmBotTexts(QueueEntry $e): array
{
    return Message::query()->where('conversation_id', $e->conversation_id)->where('sender_type', 'bot')->orderBy('id')->pluck('body')->all();
}

it('ends every manual close with the closing message, after the case number on a case close', function (string $reason) {
    [$e, $u] = cmWindow();

    app(WindowLifecycle::class)->close($e, $reason, $u, ['case_type' => 'complaint']);

    $texts = cmBotTexts($e);
    expect($texts)->toHaveCount($reason === 'case' ? 2 : 1)
        ->and(end($texts))->toBe(CM_CLOSING_TEXT);

    if ($reason === 'case') {
        expect($texts[0])->toContain('فتحنالك طلب رقم '.$e->fresh()->support_case_id);
    }
})->with(['inquiry', 'problem', 'case']);

it('sends it when a supervisor closes the window on her behalf', function () {
    [$e] = cmWindow();
    $boss = User::factory()->create(['role' => 'supervisor', 'last_seen_at' => now()]);

    $this->actingAs($boss)->postJson("/queue/entries/{$e->id}/close", ['reason' => 'inquiry'])->assertOk();

    expect($e->fresh()->closed_by_id)->toBe($boss->id)->and(cmBotTexts($e))->toBe([CM_CLOSING_TEXT]);
});

it('sends no closing message on the other closes', function (string $reason) {
    [$e, $u] = cmWindow();

    app(WindowLifecycle::class)->close($e, $reason, $reason === 'auto' ? null : $u);

    expect(cmBotTexts($e))->not->toContain(CM_CLOSING_TEXT);
})->with(['auto', 'escalation', 'transfer', 'no_reply', 'resolved_elsewhere', 'cancelled']);

it('sends none on a real escalation, transfer or automatic close after silence', function () {
    [$e1, $u1] = cmWindow();
    app(WindowLifecycle::class)->escalate($e1, $u1);

    [$e2] = cmWindow();
    app(WindowLifecycle::class)->transferAway($e2, 'أوفلاين');

    [$e3] = cmWindow();
    $e3->forceFill(['last_agent_message_at' => now(), 'last_customer_message_at' => now()->subSeconds(5)])->save();
    $e3->conversation->forceFill(['last_customer_message_at' => now()->subSeconds(5)])->save();
    Carbon::setTestNow(now()->addSeconds(301));
    app(WindowLifecycle::class)->tickSilence();

    expect($e3->fresh()->close_reason)->toBe('auto')
        ->and(cmBotTexts($e1))->not->toContain(CM_CLOSING_TEXT)
        ->and(cmBotTexts($e2))->not->toContain(CM_CLOSING_TEXT)
        ->and(cmBotTexts($e3))->not->toContain(CM_CLOSING_TEXT)
        ->and(cmBotTexts($e3))->toContain(FlowScripts::all()['queue_auto_closed']['body']);
});

it('uses the owner\'s wording, and says nothing when she turned it off', function () {
    BotKnowledgeEntry::where('key', 'script.queue_closed_thanks')->update(['body' => 'نورتينا']);
    [$e, $u] = cmWindow();
    app(WindowLifecycle::class)->close($e, 'inquiry', $u);
    expect(cmBotTexts($e))->toBe(['نورتينا']);

    BotKnowledgeEntry::where('key', 'script.queue_closed_thanks')->update(['is_active' => false]);
    [$e2, $u2] = cmWindow();
    app(WindowLifecycle::class)->close($e2, 'problem', $u2);
    expect(cmBotTexts($e2))->toBe([]);
});

// ───── Addendum C2 (R1 override): a manual close hands the chat back to the bot ─────

it('hands the conversation back to the bot on a manual close, with a fresh bot state', function (string $reason) {
    [$e, $u] = cmWindow();
    $e->conversation->forceFill([
        'priority_level' => 'high', 'queue' => 'agents', 'handover_category' => 'complaint', 'handover_topic' => 'المقاس',
        'bot_state' => ['last_turn_message_id' => 7, 'clarified' => true, 'repeat_count' => 2],
    ])->save();

    app(WindowLifecycle::class)->close($e, $reason, $u, ['case_type' => 'complaint']);

    $c = $e->conversation->fresh();
    expect($c->handler->value)->toBe('bot')
        ->and($c->needs_human)->toBeFalse()
        ->and($c->priority_level)->toBeNull()
        ->and($c->queue)->toBeNull()
        ->and($c->handover_category)->toBeNull()
        ->and($c->handover_topic)->toBeNull()
        ->and($c->bot_state)->toBe(['last_turn_message_id' => 7])
        ->and($c->assignee_id)->toBeNull();
})->with(['inquiry', 'problem', 'case']);

it('keeps a person on the conversation after the other closes', function (string $reason) {
    [$e, $u] = cmWindow();

    app(WindowLifecycle::class)->close($e, $reason, $reason === 'auto' ? null : $u);

    expect($e->conversation->fresh()->handler->value)->toBe('human');
})->with(['auto', 'escalation', 'transfer', 'no_reply', 'resolved_elsewhere']);

it('never starts a bot turn by closing', function () {
    Bus::fake([RunBotTurn::class]);
    BotSetting::current()->update(['enabled' => true]);
    [$e, $u] = cmWindow();

    app(WindowLifecycle::class)->close($e, 'inquiry', $u);

    Bus::assertNotDispatched(RunBotTurn::class);
});

it('gives her next real message to the bot after «خلصت», and a thanks within a day to nobody', function () {
    Bus::fake([RunBotTurn::class]);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE-CM']);
    BotSetting::current()->update(['enabled' => true]);
    $ingest = fn (string $id, string $text) => app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE-CM', 'PSID-CM', 'Mona', $id, $text, CarbonImmutable::now()));
    $c = $ingest('first', 'ألو')->conversation;
    [$e, $u] = cmWindow();
    $e->forceFill(['conversation_id' => $c->id, 'customer_id' => $c->customer_id])->save();
    $c->forceFill(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true])->save();
    Bus::fake([RunBotTurn::class]);

    app(WindowLifecycle::class)->close($e->fresh(), 'inquiry', $u);
    Carbon::setTestNow(now()->addMinutes(5));
    $unread = $c->fresh()->unread_count;

    $ingest('thanks', 'شكراً');
    expect($c->fresh()->unread_count)->toBe($unread);
    Bus::assertNotDispatched(RunBotTurn::class);

    $ingest('question', 'عندكم فساتين سواريه؟');
    Bus::assertDispatched(RunBotTurn::class);
    expect(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1)
        ->and($e->fresh()->reversed_at)->toBeNull()
        ->and($c->fresh()->handler->value)->toBe('bot');
});

// ───── Review minors (2026-10-02) ─────

it('lets the bot answer a real message whose window is closed by «خلصت» while it is being ingested', function () {
    Bus::fake([RunBotTurn::class]);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE-CM']);
    $ingest = fn (string $id, string $text) => app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE-CM', 'PSID-CM', 'Mona', $id, $text, CarbonImmutable::now()));
    BotSetting::current()->update(['enabled' => false]);
    $c = $ingest('first', 'ألو')->conversation;
    [$e, $u] = cmWindow();
    $e->forceFill(['conversation_id' => $c->id, 'customer_id' => $c->customer_id])->save();
    $c->forceFill(['assignee_id' => $u->id, 'queue_entry_id' => $e->id, 'handler' => 'human', 'needs_human' => true])->save();
    BotSetting::current()->update(['enabled' => true]);
    Carbon::setTestNow(now()->addMinutes(2));

    // The close commits after the ingest read her conversation (human) and before the queue hook.
    Customer::saved(function () use ($e, $u) {
        if (Message::where('external_id', 'question-race')->exists() && $e->fresh()->status === 'active') {
            app(WindowLifecycle::class)->close($e->fresh(), 'inquiry', $u);
        }
    });

    $ingest('question-race', 'عندكم فساتين سواريه؟');

    expect($e->fresh()->status)->toBe('closed')->and($c->fresh()->handler->value)->toBe('bot')
        ->and(QueueEntry::where('conversation_id', $c->id)->count())->toBe(1);
    Bus::assertDispatched(RunBotTurn::class);
});

/** She asks the bot for a person 5 minutes after her last ticket ended; `$served` = a moderator had her. */
function cmAskAfter(bool $served, bool $deskOnline): QueueEntry
{
    Bus::fake([SendQueueMessage::class]);
    $shift = Shift::query()->first() ?? Shift::factory()->create();
    $desk = User::factory()->create(['role' => 'moderator', 'last_seen_at' => $deskOnline ? now() : now()->subDay()]);
    $desk->userPlatforms()->create(['platform' => 'facebook']);
    ShiftMember::factory()->for($shift)->create(['user_id' => $desk->id, 'status' => 'available']);
    $last = QueueEntry::factory()->create([
        'status' => $served ? 'closed' : 'cancelled', 'close_reason' => $served ? 'inquiry' : 'cancelled',
        'assigned_user_id' => $served ? $desk->id : null, 'closed_at' => now()->subMinutes(5), 'enqueued_at' => now()->subMinutes(20),
    ]);
    $last->conversation->update(['handler' => 'bot', 'needs_human' => false, 'platform' => 'facebook', 'last_customer_message_at' => now()]);

    app(BotEngine::class)->handover($last->conversation->fresh(), 'human_request');

    return QueueEntry::where('conversation_id', $last->conversation_id)->latest('id')->first();
}

it('promises the same moderator only to a customer one served', function () {
    $new = cmAskAfter(served: false, deskOnline: false);
    expect($new->priority)->toBe('returning')->and($new->reserved_user_id)->toBeNull()->and($new->status)->toBe('waiting');
    Bus::assertNotDispatched(SendQueueMessage::class, fn ($job) => $job->scriptKey === 'queue_returning');

    $new2 = cmAskAfter(served: false, deskOnline: true);
    expect($new2->priority)->toBe('returning');
    Bus::assertDispatched(SendQueueMessage::class, fn ($job) => $job->entryId === $new2->id && $job->scriptKey === 'queue_enqueued');
    Bus::assertNotDispatched(SendQueueMessage::class, fn ($job) => $job->scriptKey === 'queue_returning');

    $served = cmAskAfter(served: true, deskOnline: true);
    Bus::assertDispatched(SendQueueMessage::class, fn ($job) => $job->entryId === $served->id && $job->scriptKey === 'queue_returning');
});
