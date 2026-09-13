<?php

use App\Analytics\ActivityLogger;
use App\Bot\Ai\{AiReply, AiResponder, Classification};
use App\Bot\BotEngine;
use App\Enums\{CommentIntent, Handler, SenderType, Platform};
use App\Models\{ActivityLog, BotRule, BotSetting, Conversation, Message, Product, ProductVariant, ChannelAccount, Customer, CustomerIdentity};
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null, 'max_bot_turns' => 5, 'min_confidence' => 0.6, 'handover_keywords' => ['عايز اكلم حد', 'موظف']]);
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::Facebook]);
    $this->conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create(['platform' => Platform::Facebook, 'handler' => Handler::Bot, 'last_customer_message_at' => now()]);
});

function inbound(Conversation $c, string $text): Message {
    return Message::factory()->for($c)->create(['direction' => 'in', 'sender_type' => SenderType::Customer, 'body' => $text, 'platform' => $c->platform]);
}

it('replies with a matching rule', function () {
    BotRule::factory()->create(['keywords' => ['بكام'], 'private_reply' => 'الأسعار في الكتالوج 👗', 'action' => 'reply', 'scope' => 'both', 'platforms' => [], 'is_active' => true]);
    $run = app(BotEngine::class)->handleInbound(inbound($this->conv, 'بكام؟'));
    expect($run->engine)->toBe('rule')->and($this->conv->messages()->where('sender_type', 'bot')->first()->body)->toBe('الأسعار في الكتالوج 👗');
});

it('hands over on keyword', function () {
    app(BotEngine::class)->handleInbound(inbound($this->conv, 'لو سمحت عايز اكلم حد'));
    expect($this->conv->fresh()->handler)->toBe(Handler::Human)->and($this->conv->fresh()->needs_human)->toBeTrue();
});

it('uses fake ai with catalog and hands over complaints', function () {
    $p = Product::factory()->create(['title' => 'فستان ستان']);
    ProductVariant::factory()->for($p)->create(['price' => 1250, 'sku' => 'DR-101', 'inventory_quantity' => 7]);
    $run = app(BotEngine::class)->handleInbound(inbound($this->conv, 'فستان ستان بكام'));
    expect($run->engine)->toBe('ai')->and($run->decision)->toBe('reply');
    app(BotEngine::class)->handleInbound(inbound($this->conv->fresh(), 'الاوردر اتاخر ومشكله كبيره'));
    expect($this->conv->fresh()->handler)->toBe(Handler::Human);
});

it('does nothing when a human handles the conversation', function () {
    $this->conv->update(['handler' => Handler::Human]);
    expect(app(BotEngine::class)->handleInbound(inbound($this->conv, 'بكام')))->toBeNull();
});

it('does not increment rule hits when a handover keyword pre-empts it', function () {
    $rule = BotRule::factory()->create(['keywords' => ['بكام'], 'private_reply' => 'الأسعار', 'action' => 'reply', 'scope' => 'both', 'platforms' => [], 'is_active' => true]);

    app(BotEngine::class)->handleInbound(inbound($this->conv, 'عايز موظف يقولي بكام'));

    expect($this->conv->fresh()->handler)->toBe(Handler::Human)
        ->and($rule->fresh()->hits)->toBe(0);
});

it('writes a bot run and activity log for the outside-hours message', function () {
    BotSetting::current()->update([
        'working_hours' => ['days' => [], 'from' => '00:00', 'to' => '23:59'],
        'outside_hours_message' => 'احنا مقفولين دلوقتي، هنرد عليك بدري 🌙',
    ]);

    $run = app(BotEngine::class)->handleInbound(inbound($this->conv, 'بكام؟'));

    expect($run)->not->toBeNull()
        ->and($run->engine)->toBe('system')
        ->and($run->decision)->toBe('reply')
        ->and($run->reply_text)->toBe('احنا مقفولين دلوقتي، هنرد عليك بدري 🌙')
        ->and($this->conv->messages()->where('sender_type', 'bot')->first()->body)->toBe('احنا مقفولين دلوقتي، هنرد عليك بدري 🌙')
        ->and(ActivityLog::where('action', ActivityLogger::BOT_OUTSIDE_HOURS)->where('conversation_id', $this->conv->id)->exists())->toBeTrue();
});

it('tracks classifier token cost on a complaint handover', function () {
    app()->instance(AiResponder::class, new class implements AiResponder
    {
        public function classify(string $text): Classification
        {
            return new Classification(CommentIntent::Complaint, 0.9, false, 'claude-haiku-4-5-20251001', 1000, 500, 120);
        }

        public function reply(array $history, array $catalogLines, string $systemPrompt): AiReply
        {
            throw new RuntimeException('a complaint should hand over before ever calling reply()');
        }
    });

    $run = app(BotEngine::class)->handleInbound(inbound($this->conv, 'الاوردر اتاخر ومشكله كبيره'));

    expect($run->engine)->toBe('ai')
        ->and($run->decision)->toBe('handover')
        ->and($run->model)->toBe('claude-haiku-4-5-20251001')
        ->and($run->input_tokens)->toBe(1000)
        ->and($run->output_tokens)->toBe(500)
        ->and((float) $run->cost_usd)->toBeGreaterThan(0.0);
});
