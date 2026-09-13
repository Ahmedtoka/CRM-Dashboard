<?php

use App\Bot\BotEngine;
use App\Bot\Jobs\RunBot;
use App\Channels\Data\InboundMessageData;
use App\Enums\{Handler, Platform, SenderType};
use App\Inbox\InboxIngestor;
use App\Models\{BotRule, BotRun, BotSetting, ChannelAccount, Conversation, Customer, CustomerIdentity, Message};
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => false, 'working_hours' => null, 'max_bot_turns' => 5]);
    BotRule::factory()->create(['keywords' => ['بكام'], 'private_reply' => 'الأسعار في الكتالوج', 'action' => 'reply', 'scope' => 'both', 'platforms' => [], 'is_active' => true]);

    $this->account = ChannelAccount::factory()->create(['platform' => Platform::Instagram]);
    $this->customer = Customer::factory()->create();
    CustomerIdentity::factory()->for($this->customer)->create(['platform' => Platform::Instagram]);
    $this->conv = Conversation::factory()->for($this->customer)->for($this->account, 'channelAccount')->create([
        'platform' => Platform::Instagram, 'handler' => Handler::Bot, 'last_customer_message_at' => now(),
    ]);
});

function customerMsg(Conversation $c, string $text): Message
{
    return Message::factory()->for($c)->create(['direction' => 'in', 'sender_type' => SenderType::Customer, 'body' => $text, 'platform' => $c->platform]);
}

it('serializes bot runs per conversation', function () {
    $m = customerMsg($this->conv, 'بكام؟');

    $middleware = (new RunBot($m->id))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe("conv:{$this->conv->id}");
});

it('skips a bot run when a newer customer message arrived in the meantime', function () {
    $first = customerMsg($this->conv, 'بكام؟');
    $second = customerMsg($this->conv, 'بكام الفستان؟');

    (new RunBot($first->id))->handle(app(BotEngine::class));

    expect(BotRun::count())->toBe(0)
        ->and($this->conv->messages()->where('sender_type', 'bot')->count())->toBe(0);

    (new RunBot($second->id))->handle(app(BotEngine::class));

    expect(BotRun::count())->toBe(1)
        ->and($this->conv->messages()->where('sender_type', 'bot')->count())->toBe(1);
});

it('keeps one open conversation per customer and account across back-to-back webhooks', function () {
    BotSetting::current()->update(['enabled' => false]);
    $ingestor = app(InboxIngestor::class);

    foreach (['a', 'b', 'c'] as $id) {
        $ingestor->ingestMessage(new InboundMessageData(
            Platform::Instagram, $this->account->external_id, 'new-cust', 'Nour', "m-{$id}", 'مرحبا', CarbonImmutable::now(),
        ));
    }

    $customerId = CustomerIdentity::where('external_id', 'new-cust')->value('customer_id');

    expect(Conversation::where('customer_id', $customerId)->count())->toBe(1)
        ->and(Message::where('direction', 'in')->whereIn('external_id', ['m-a', 'm-b', 'm-c'])->count())->toBe(3);
});
