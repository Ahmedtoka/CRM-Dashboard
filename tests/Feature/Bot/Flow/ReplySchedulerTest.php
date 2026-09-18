<?php

use App\Bot\BotEngine;
use App\Bot\Flow\BurstPolicy;
use App\Bot\Flow\Jobs\RunBotTurn;
use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flows\FlowState;
use App\Channels\Data\InboundMessageData;
use App\Enums\Platform;
use App\Inbox\InboxIngestor;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'burst_wait_seconds' => 8, 'burst_max_wait_seconds' => 25]);
});

function fbIn(string $id, string $text): InboundMessageData
{
    return new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-1', 'Mona', $id, $text, CarbonImmutable::now());
}

/** An inbound image, mirroring a channel adapter's normalize() shape recorded by InboundAttachmentRecorder. */
function fbInImage(string $id): InboundMessageData
{
    return new InboundMessageData(
        Platform::Facebook, 'PAGE1', 'PSID-1', 'Mona', $id, '', CarbonImmutable::now(),
        attachments: [['type' => 'image', 'url' => 'https://cdn.test/photo.jpg']],
    );
}

it('delays the turn by the burst wait and pushes it forward on each new message', function () {
    Queue::fake();
    // freezeSecond (not freezeTime): bot_due_at round-trips through the DB,
    // which stores timestamps at whole-second precision, so the frozen instant
    // must start on a second boundary for the equality checks below to hold.
    $this->freezeSecond();

    app(InboxIngestor::class)->ingestMessage(fbIn('m1', 'بكام الطقم؟'));
    $c = Conversation::first();
    expect($c->bot_due_at->equalTo(now()->addSeconds(8)))->toBeTrue();

    $this->travel(3)->seconds();
    app(InboxIngestor::class)->ingestMessage(fbIn('m2', 'استنى'));
    // The longer wait is capped at the burst's first message + the max wait (final fix wave I8).
    expect($c->fresh()->bot_due_at->equalTo(now()->subSeconds(3)->addSeconds(25)))->toBeTrue();

    Queue::assertPushed(RunBotTurn::class, 2);
});

it('never pushes the due time past the first message of the burst plus the max wait', function () {
    Queue::fake();
    $this->freezeSecond();
    $start = now()->copy();

    app(InboxIngestor::class)->ingestMessage(fbIn('m1', 'بكام الطقم؟'));
    $this->travel(20)->seconds();
    app(InboxIngestor::class)->ingestMessage(fbIn('m2', 'استنى'));
    expect(Conversation::first()->bot_due_at->equalTo($start->copy()->addSeconds(25)))->toBeTrue();

    $this->travel(3)->seconds();
    app(InboxIngestor::class)->ingestMessage(fbIn('m3', 'و'));
    expect(Conversation::first()->bot_due_at->equalTo($start->copy()->addSeconds(25)))->toBeTrue();
});

it('turns typing on once per burst, when its first message schedules', function () {
    Queue::fake();
    $scheduler = Mockery::mock(ReplyScheduler::class, [app(BurstPolicy::class)])->makePartial();
    $scheduler->shouldReceive('typing')->once();
    app()->instance(ReplyScheduler::class, $scheduler);

    app(InboxIngestor::class)->ingestMessage(fbIn('m1', 'عايزة'));
    app(InboxIngestor::class)->ingestMessage(fbIn('m2', 'الطقم الاسود'));
    app(InboxIngestor::class)->ingestMessage(fbIn('m3', 'بكام؟'));
});

it('runs the turn once, only when due, for the whole burst', function () {
    Queue::fake();
    app(InboxIngestor::class)->ingestMessage(fbIn('m1', 'عايزة'));
    app(InboxIngestor::class)->ingestMessage(fbIn('m2', 'الطقم الاسود بكام؟'));
    $c = Conversation::first();

    $engine = Mockery::mock(BotEngine::class);
    $engine->shouldReceive('handleTurn')->once()->withArgs(fn ($conv, $burst) => $conv->id === $c->id && $burst->count() === 2);
    app()->instance(BotEngine::class, $engine);

    (new RunBotTurn($c->id))->handle();          // not due yet → no call
    $this->travel(30)->seconds();
    (new RunBotTurn($c->id))->handle();          // due → one call with both messages
    (new RunBotTurn($c->id))->handle();          // duplicate dispatch → no second call
});

it('restores the marker and retries the burst when the engine throws before replying', function () {
    Queue::fake();
    app(InboxIngestor::class)->ingestMessage(fbIn('m1', 'بكام الطقم؟'));
    $c = Conversation::first();

    $engine = Mockery::mock(BotEngine::class);
    $engine->shouldReceive('handleTurn')->twice()->andThrow(new RuntimeException('boom'));
    app()->instance(BotEngine::class, $engine);

    $this->travel(30)->seconds();

    expect(fn () => (new RunBotTurn($c->id))->handle())->toThrow(RuntimeException::class);
    expect($c->fresh()->bot_state['last_turn_message_id'] ?? null)->toBeNull();

    // The marker was reset, so a retry (queue's own retry, or a duplicate
    // dispatch) answers the same burst again instead of silently dropping it.
    expect(fn () => (new RunBotTurn($c->id))->handle())->toThrow(RuntimeException::class);
});

it('keeps the marker and does not resend when the engine already replied before throwing', function () {
    Queue::fake();
    app(InboxIngestor::class)->ingestMessage(fbIn('m1', 'بكام الطقم؟'));
    $c = Conversation::first();

    $engine = Mockery::mock(BotEngine::class);
    $engine->shouldReceive('handleTurn')->once()->andReturnUsing(function (Conversation $conv) {
        Message::factory()->for($conv, 'conversation')->create();

        throw new RuntimeException('boom after reply');
    });
    app()->instance(BotEngine::class, $engine);

    $this->travel(30)->seconds();

    expect(fn () => (new RunBotTurn($c->id))->handle())->toThrow(RuntimeException::class);
    expect($c->fresh()->bot_state['last_turn_message_id'] ?? null)->not->toBeNull();

    // The marker was kept because a reply already went out: a retry must not
    // call the engine (and so must not send) a second time.
    (new RunBotTurn($c->id))->handle();
});

it('answers a button tap without the burst wait', function () {
    Queue::fake();
    $this->freezeSecond();

    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        platform: Platform::Facebook,
        channelExternalId: 'PAGE1',
        customerExternalId: 'PSID-1',
        customerName: 'Mona',
        externalMessageId: 'm1',
        body: 'شكوى',
        occurredAt: CarbonImmutable::now(),
        payload: 'flow:complaint',
    ));

    expect(Conversation::first()->bot_due_at->lessThanOrEqualTo(now()))->toBeTrue();
});

it('answers a photo right away when the active flow is waiting for one', function () {
    Queue::fake();
    $this->freezeSecond();

    app(InboxIngestor::class)->ingestMessage(fbIn('m1', 'اهلا'));
    $c = Conversation::first();
    FlowState::put($c, ['key' => 'return_exchange', 'step' => 'product_photo', 'data' => [], 'retries' => 0, 'started_at' => now()->toIso8601String()]);

    app(InboxIngestor::class)->ingestMessage(fbInImage('m2'));

    // 2s, not the 25s max wait: the photo step answers right away, with just enough
    // of a window for a second photo sent together to land in the same burst.
    expect(Conversation::first()->bot_due_at->equalTo(now()->addSeconds(2)))->toBeTrue();
});

it('still waits the max burst wait for an image when no flow is active', function () {
    Queue::fake();
    $this->freezeSecond();

    app(InboxIngestor::class)->ingestMessage(fbInImage('m1'));

    expect(Conversation::first()->bot_due_at->equalTo(now()->addSeconds(25)))->toBeTrue();
});

it('still waits the max burst wait for an image when the active flow is not on a photo step', function () {
    Queue::fake();
    $this->freezeSecond();

    app(InboxIngestor::class)->ingestMessage(fbIn('m1', 'اهلا'));
    $c = Conversation::first();
    FlowState::put($c, ['key' => 'return_exchange', 'step' => 'reason', 'data' => [], 'retries' => 0, 'started_at' => now()->toIso8601String()]);

    app(InboxIngestor::class)->ingestMessage(fbInImage('m2'));

    expect(Conversation::first()->bot_due_at->equalTo(now()->addSeconds(25)))->toBeTrue();
});
