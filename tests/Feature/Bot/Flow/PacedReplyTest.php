<?php

use App\Bot\BotEngine;
use App\Bot\Flow\FakeTurnUnderstanding;
use App\Bot\Flow\ReplyScheduler;
use App\Bot\Flow\TurnUnderstanding;
use App\Bot\Flows\FlowAnswer;
use App\Bot\Flows\FlowAnswerInterpreter;
use App\Bot\Flows\FlowState;
use App\Channels\Data\InboundMessageData;
use App\Enums\Handler;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\InboxIngestor;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    useOrderAwareReturnFlow();
    Event::fake();
    Queue::fake();
    Sleep::fake();
    Http::preventStrayRequests();
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PAGE1']);
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null, 'typing_ms_per_char' => 35]);
    app()->bind(TurnUnderstanding::class, FakeTurnUnderstanding::class);
    config(['crm.drivers.ai' => 'fake']);
});

/** A two-part reply: the delivery_time script made longer than one message. */
function twoPartTurn(): array
{
    $second = 'الجزء التاني '.str_repeat('ب', 200);
    BotKnowledgeEntry::where('key', 'script.delivery_time')->update(['body' => str_repeat('أ', 300)."\n\n".$second]);

    // The one-time offer-a-human line (reply flow v2) would join the second part; this test is about pacing only.
    BotKnowledgeEntry::where('key', 'script.offer_human')->update(['is_active' => false]);

    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-1', 'Mona', 'm1', 'التوصيل بياخد كام يوم؟', CarbonImmutable::now()));
    $c = Conversation::first();
    app(BotEngine::class)->handleTurn($c, app(ReplyScheduler::class)->burst($c));

    return [$c, $second];
}

it('waits once before the first part and queues the second part with a delay instead of sleeping', function () {
    [, $second] = twoPartTurn();

    $parts = Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->get();
    expect($parts)->toHaveCount(2)->and($parts[1]->body)->toBe($second);

    Sleep::assertSleptTimes(1);
    Queue::assertPushed(SendOutboundMessage::class, fn (SendOutboundMessage $job) => $job->messageId === $parts[0]->id && $job->delay === null);
    Queue::assertPushed(SendOutboundMessage::class, fn (SendOutboundMessage $job) => $job->messageId === $parts[1]->id && $job->delay !== null);
});

it('does not send the delayed second part once a person has taken the conversation', function () {
    [$c] = twoPartTurn();
    $second = Message::where('sender_type', SenderType::Bot->value)->orderByDesc('id')->first();
    $job = Queue::pushed(SendOutboundMessage::class, fn (SendOutboundMessage $job) => $job->messageId === $second->id)->first();

    $c->forceFill(['handler' => Handler::Human])->save();
    app()->call([$job, 'handle']);

    expect($second->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($second->fresh()->error)->toBe('human_took_over');
});

/** @return int milliseconds from now until the queued job runs (0 when not delayed) */
function jobDelayMs(SendOutboundMessage $job): int
{
    return $job->delay === null ? 0 : (int) now()->diffInMilliseconds($job->delay);
}

function jobFor(Message $m): SendOutboundMessage
{
    return Queue::pushed(SendOutboundMessage::class, fn (SendOutboundMessage $j) => $j->messageId === $m->id)->first();
}

it('queues the flow prompt after the delayed tail of a split answer', function () {
    test()->freezeTime();
    BotKnowledgeEntry::where('key', 'script.delivery_time')->update(['body' => str_repeat('أ', 300)."\n\n".'الجزء التاني '.str_repeat('ب', 200)]);
    BotSetting::current()->update(['enabled' => false]);
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-1', 'Mona', 'm1', 'التوصيل بياخد كام يوم؟', CarbonImmutable::now()));
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-1', 'Mona', 'm2', 'وعايزة الغي الاوردر', CarbonImmutable::now()));
    BotSetting::current()->update(['enabled' => true]);
    $c = Conversation::first();

    app(BotEngine::class)->handleTurn($c, app(ReplyScheduler::class)->burst($c));

    $bot = Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->get();
    $tail = $bot->first(fn ($m) => str_starts_with($m->body, 'الجزء التاني'));
    $prompt = $bot->last();
    expect($tail)->not->toBeNull()
        ->and($c->refresh()->bot_state['flow']['key'])->toBe('cancel_edit')
        ->and($prompt->id)->toBeGreaterThan($tail->id)
        ->and(jobDelayMs(jobFor($tail)))->toBeGreaterThan(0)
        ->and(jobDelayMs(jobFor($prompt)))->toBeGreaterThan(jobDelayMs(jobFor($tail)));
});

it('queues the re-asked flow step after the delayed tail of an in-flow answer', function () {
    test()->freezeTime();
    BotKnowledgeEntry::where('key', 'script.delivery_time')->update(['body' => str_repeat('أ', 300)."\n\n".'الجزء التاني '.str_repeat('ب', 200)]);
    app()->instance(FlowAnswerInterpreter::class, new class implements FlowAnswerInterpreter
    {
        public function interpret(array $step, string $text, array $history): FlowAnswer
        {
            return new FlowAnswer('question');
        }
    });
    BotSetting::current()->update(['enabled' => false]);
    app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PAGE1', 'PSID-1', 'Mona', 'm1', 'التوصيل كام يوم', CarbonImmutable::now()));
    BotSetting::current()->update(['enabled' => true]);
    $c = Conversation::first();
    FlowState::put($c, ['key' => 'return_exchange', 'step' => 'reason', 'data' => [], 'retries' => 0, 'started_at' => now()->toIso8601String()]);

    app(BotEngine::class)->handleTurn($c, app(ReplyScheduler::class)->burst($c));

    $bot = Message::where('sender_type', SenderType::Bot->value)->orderBy('id')->get();
    $tail = $bot->first(fn ($m) => str_starts_with($m->body, 'الجزء التاني'));
    $prompt = $bot->last();
    expect($tail)->not->toBeNull()
        ->and($prompt->body)->toBe("نرجع لـطلب المرتجع 🌸\nإيه سبب المرتجع؟")
        ->and(jobDelayMs(jobFor($prompt)))->toBeGreaterThan(jobDelayMs(jobFor($tail)));
});
