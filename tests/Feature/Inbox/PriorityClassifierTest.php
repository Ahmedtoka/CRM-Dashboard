<?php

use App\Channels\Data\InboundMessageData;
use App\Enums\ConversationPriority;
use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Inbox\ConversationPriorityClassifier;
use App\Inbox\InboxIngestor;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\User;
use App\Shopify\Connection\ShopifyIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null]);
    ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PG1']);
    $this->n = 0;
    $this->send = function (string $text, string $customer = 'u1') {
        return app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PG1', $customer, 'Nour', 'm'.(++$this->n), $text, CarbonImmutable::now()));
    };
});

it('marks unknown links as spam and skips the bot', function () {
    $m = ($this->send)('اكسب فلوس من هنا https://win-cash.example/xyz');
    expect($m->fresh()->is_spam)->toBeTrue()->and($m->conversation->fresh()->priority)->toBe(ConversationPriority::Spam)
        ->and(BotRun::count())->toBe(0);
});

it('never marks a link to the store domain as spam (the exchange product link, 2026-09-22)', function () {
    BotSetting::current()->update(['store_url' => 'https://www.levoilestores.com/']);

    $m = ($this->send)('عايزة أبدل بده https://levoilestores.com/products/abaya-linen?variant=1');
    expect($m->fresh()->is_spam)->toBeFalse()->and($m->conversation->fresh()->priority)->not->toBe(ConversationPriority::Spam);
});

it('never counts a short answer or a button tap as a repeat (the owner answering «أيوه» three times, 2026-09-22)', function () {
    foreach (range(1, 4) as $_) {
        $m = ($this->send)('أيوه');
    }
    expect($m->conversation->fresh()->priority)->not->toBe(ConversationPriority::Spam);

    foreach (range(1, 4) as $i) {
        $m = app(InboxIngestor::class)->ingestMessage(new InboundMessageData(Platform::Facebook, 'PG1', 'u2', 'Nour', 'tap'.$i, 'المرتجع والاستبدال من فضلك يا فندم', CarbonImmutable::now(), payload: 'flow:return_exchange'));
    }
    expect($m->conversation->fresh()->priority)->not->toBe(ConversationPriority::Spam);
});

it('marks repeated identical messages as spam', function () {
    foreach (range(1, 3) as $_) {
        $m = ($this->send)('ممكن تتواصلي معايا');
    }
    expect($m->conversation->fresh()->priority)->toBe(ConversationPriority::Spam);
});

it('marks emoji and thanks as low value and resets on a real question', function () {
    $m = ($this->send)('شكرا 👍');
    $conv = $m->conversation->fresh();
    expect($conv->priority)->toBe(ConversationPriority::Low)->and($m->fresh()->is_low_value)->toBeTrue();
    ($this->send)('طيب المقاس L متاح؟');
    expect($conv->fresh()->priority)->toBe(ConversationPriority::Normal);
});

it('does not treat a short question as low value', function () {
    $m = ($this->send)('تمام بكام؟');
    expect($m->fresh()->is_low_value)->toBeFalse();
});

it('keeps a manual spam mark on later automatic spam, but not once the sender is allowlisted', function () {
    $m = ($this->send)('اربح فلوس دلوقتي');
    $conv = $m->conversation->fresh();
    expect($conv->priority)->toBe(ConversationPriority::Spam);

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    app(ConversationPriorityClassifier::class)->setManually($conv, ConversationPriority::Normal, $admin);
    expect($conv->fresh()->priority)->toBe(ConversationPriority::Normal);

    $m2 = ($this->send)('اربح فلوس تاني', 'u1');
    expect($conv->fresh()->priority)->toBe(ConversationPriority::Normal)
        ->and($m2->fresh()->is_spam)->toBeFalse();
});

it('treats a whatsapp-normalized sticker attachment as low value', function () {
    // The sticker carries a bare media id with no fixture/url, so the queued
    // DownloadInboundMedia (media task) fails against the fake channel driver;
    // InboxIngestor::rescue()s that dispatch, so it never reaches this test.
    $m = app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PG1', 'u5', 'Nour', 'sticker1', '', CarbonImmutable::now(),
        attachments: [['type' => 'sticker', 'id' => 'MEDIA1']],
    ));
    expect($m->fresh()->is_low_value)->toBeTrue();
});

it('treats a meta attachment carrying payload.sticker_id as low value', function () {
    $m = app(InboxIngestor::class)->ingestMessage(new InboundMessageData(
        Platform::Facebook, 'PG1', 'u6', 'Nour', 'sticker2', '', CarbonImmutable::now(),
        attachments: [['type' => 'image', 'payload' => ['sticker_id' => '369239263222822']]],
    ));
    expect($m->fresh()->is_low_value)->toBeTrue();
});

it('treats "تسلم" and "ميرسي" as low value', function () {
    expect(($this->send)('تسلم', 'u7')->fresh()->is_low_value)->toBeTrue();
    expect(($this->send)('ميرسي', 'u8')->fresh()->is_low_value)->toBeTrue();
});

it('counts repeated identical messages across every conversation of the same customer and platform', function () {
    $customer = Customer::factory()->create();
    $identity = CustomerIdentity::factory()->for($customer)->create(['platform' => Platform::Facebook]);
    $accA = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PGA']);
    $accB = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'PGB']);
    $convA = Conversation::factory()->for($customer)->for($accA, 'channelAccount')->create(['platform' => Platform::Facebook]);
    $convB = Conversation::factory()->for($customer)->for($accB, 'channelAccount')->create(['platform' => Platform::Facebook]);

    foreach (range(1, 2) as $_) {
        Message::factory()->create([
            'conversation_id' => $convA->id,
            'platform' => Platform::Facebook,
            'direction' => MessageDirection::In,
            'sender_type' => SenderType::Customer,
            'body' => 'ممكن تتواصلي معايا',
        ]);
    }

    $m3 = Message::factory()->create([
        'conversation_id' => $convB->id,
        'platform' => Platform::Facebook,
        'direction' => MessageDirection::In,
        'sender_type' => SenderType::Customer,
        'body' => 'ممكن تتواصلي معايا',
    ]);

    $verdict = app(ConversationPriorityClassifier::class)->classify($m3, $identity);
    expect($verdict->priority)->toBe(ConversationPriority::Spam)->and($verdict->reason)->toBe('repeat');
});

it('allows links to the connected shopify store domain even when the allow-list omits it', function () {
    BotSetting::current()->update(['allowed_link_domains' => ['facebook.com']]);
    ShopifyIntegration::create(['shop_domain' => 'bezra-store.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);

    $m = ($this->send)('شوفي المنتج ده https://bezra-store.myshopify.com/products/dress', 'u-shop');
    expect($m->fresh()->is_spam)->toBeFalse();

    $other = ($this->send)('شوفي ده https://another-store.myshopify.com/products/x', 'u-other');
    expect($other->fresh()->is_spam)->toBeTrue();
});
