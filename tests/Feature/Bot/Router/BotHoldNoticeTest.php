<?php

use App\Bot\Jobs\BotHoldNotice;
use App\Bot\Router\TurnMetrics;
use App\Enums\Handler;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Event::fake();
    $this->account = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
});

function holdConversation(): Conversation
{
    return Conversation::factory()->for(test()->account, 'channelAccount')->create([
        'platform' => Platform::Facebook, 'handler' => Handler::Bot, 'last_customer_message_at' => now(),
    ]);
}

function holdTurn(Conversation $c, int $startedMsAgo = 2500): array
{
    $turn = 'turn-'.uniqid();
    Cache::put(BotHoldNotice::turnKey($c->id), $turn, now()->addMinutes(3));

    return [$c->id, $turn, (int) floor(microtime(true) * 1000) - $startedMsAgo, (int) $c->messages()->max('id')];
}

function holdBotTexts(Conversation $c): array
{
    return $c->messages()->where('sender_type', SenderType::Bot->value)->pluck('body')->all();
}

it('tells her «ثانية واحدة» once the model answer is late', function () {
    $c = holdConversation();

    (new BotHoldNotice(...holdTurn($c)))->handle();

    expect(holdBotTexts($c))->toBe(['ثانية واحدة يا فندم']);
});

it('stays quiet when the turn already answered, is over, is early, or she just got one', function () {
    $c = holdConversation();

    // Early: the sync queue runs it the moment it is dispatched.
    (new BotHoldNotice(...holdTurn($c, 100)))->handle();

    // The turn is over.
    $args = holdTurn($c);
    Cache::forget(BotHoldNotice::turnKey($c->id));
    (new BotHoldNotice(...$args))->handle();

    // The reply went out first.
    $args = holdTurn($c);
    Message::factory()->create(['conversation_id' => $c->id, 'sender_type' => SenderType::Bot, 'body' => 'الرد']);
    (new BotHoldNotice(...$args))->handle();

    expect(holdBotTexts($c))->toBe(['الرد']);

    // At most once every 10 minutes per chat.
    (new BotHoldNotice(...holdTurn($c)))->handle();
    (new BotHoldNotice(...holdTurn($c)))->handle();

    expect(holdBotTexts($c))->toBe(['الرد', 'ثانية واحدة يا فندم']);
});

it('is armed once per turn, on its own queue, never on the bot queue', function () {
    Queue::fake();
    $c = holdConversation();
    $m = app(TurnMetrics::class);
    $m->reset();
    $m->turn = 'turn-1';
    $m->startedAtMs = (int) floor(microtime(true) * 1000);
    $m->lastMessageId = 0;

    BotHoldNotice::arm($c);
    BotHoldNotice::arm($c);

    Queue::assertPushed(BotHoldNotice::class, 1);
    Queue::assertPushedOn('outbound', BotHoldNotice::class);
});

it('is never armed outside a turn', function () {
    Queue::fake();
    app(TurnMetrics::class)->reset();

    BotHoldNotice::arm(Conversation::factory()->create());

    Queue::assertNothingPushed();
});
