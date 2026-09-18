<?php

use App\Bot\BotEngine;
use App\Bot\Flow\Jobs\RunBotTurn;
use App\Bot\Flow\ReplyScheduler;
use App\Channels\Data\InboundMessageData;
use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
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
    Queue::fake();
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => false]);
});

function boundaryIn(string $id, string $text): Message
{
    return app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-1', 'Mona', $id, $text, CarbonImmutable::now()));
}

function outbound(Conversation $c, SenderType $sender): Message
{
    return Message::factory()->for($c, 'conversation')->create(['direction' => MessageDirection::Out, 'sender_type' => $sender, 'body' => 'رد']);
}

it('still answers a message stored before the bot reply but after the burst was read', function () {
    $m1 = boundaryIn('m1', 'بكام الطقم؟');
    $c = Conversation::first();
    // The turn read [m1] and marked it; m2 landed before the bot's reply row was written.
    $c->forceFill(['bot_state' => ['last_turn_message_id' => $m1->id]])->save();
    $m2 = boundaryIn('m2', 'والشحن كام؟');
    outbound($c, SenderType::Bot);

    expect(app(ReplyScheduler::class)->burst($c->fresh())->pluck('id')->all())->toBe([$m2->id]);

    BotSetting::current()->update(['enabled' => true]);
    $engine = Mockery::mock(BotEngine::class);
    $engine->shouldReceive('handleTurn')->once()->withArgs(fn ($conv, $burst) => $burst->pluck('id')->all() === [$m2->id]);
    app()->instance(BotEngine::class, $engine);

    (new RunBotTurn($c->id))->handle();
});

it('lets a human reply close the burst even after the turn marker', function () {
    $m1 = boundaryIn('m1', 'بكام الطقم؟');
    $c = Conversation::first();
    $c->forceFill(['bot_state' => ['last_turn_message_id' => $m1->id]])->save();
    boundaryIn('m2', 'والشحن كام؟');
    outbound($c, SenderType::User);
    $m3 = boundaryIn('m3', 'تمام شكرا');

    expect(app(ReplyScheduler::class)->burst($c->fresh())->pluck('id')->all())->toBe([$m3->id]);
});

it('schedules its own turn for a message that arrived while a turn was running', function () {
    boundaryIn('m1', 'بكام الطقم؟');
    $c = Conversation::first();
    BotSetting::current()->update(['enabled' => true]);

    $engine = Mockery::mock(BotEngine::class);
    $engine->shouldReceive('handleTurn')->once()->andReturnUsing(function (Conversation $conv) {
        Message::factory()->for($conv, 'conversation')->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'والشحن؟']);

        return null;
    });
    app()->instance(BotEngine::class, $engine);

    (new RunBotTurn($c->id))->handle();

    Queue::assertPushed(RunBotTurn::class, fn (RunBotTurn $job) => $job->conversationId === $c->id);
    expect($c->fresh()->bot_due_at)->not->toBeNull();
});

it('retries an overlapped turn instead of dropping it', function () {
    $job = new RunBotTurn(1);
    $overlap = $job->middleware()[0];

    expect($job->tries)->toBe(10)
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->timeout)->toBeLessThanOrEqual(75)
        ->and($job->queue)->toBe('bot')
        ->and($overlap->releaseAfter)->toBe(10);
});
