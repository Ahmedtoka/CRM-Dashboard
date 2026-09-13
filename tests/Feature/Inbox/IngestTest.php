<?php

use App\Channels\Data\{InboundMessageData, DeliveryReceiptData};
use App\Enums\{Platform, MessageStatus, ConversationStatus};
use App\Inbox\InboxIngestor;
use App\Models\{BotSetting, ChannelAccount, Conversation, Message};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    // These tests only exercise ingestion mechanics; the bot (Task 4) is
    // enabled by default and would otherwise run synchronously (queue is
    // sync in tests) on every ingested inbound message and add its own
    // system/bot messages to the conversation.
    BotSetting::current()->update(['enabled' => false]);
    $this->account = ChannelAccount::factory()->create(['platform' => Platform::Instagram, 'external_id' => 'IG1']);
});

function igMsg(string $id, string $text = 'بكام الفستان'): InboundMessageData {
    return new InboundMessageData(Platform::Instagram, 'IG1', 'cust-1', 'Nour', $id, $text, CarbonImmutable::now());
}

it('creates customer, conversation and message once per external id', function () {
    $ing = app(InboxIngestor::class);
    $ing->ingestMessage(igMsg('m1'));
    expect($ing->ingestMessage(igMsg('m1')))->toBeNull();
    $ing->ingestMessage(igMsg('m2', 'متاح مقاس L؟'));
    expect(Conversation::count())->toBe(1)->and(Message::count())->toBe(2)
        ->and(Conversation::first()->unread_count)->toBe(2);
});

it('reopens a resolved conversation', function () {
    $ing = app(InboxIngestor::class);
    $ing->ingestMessage(igMsg('m1'));
    Conversation::first()->update(['status' => ConversationStatus::Resolved]);
    $ing->ingestMessage(igMsg('m2'));
    expect(Conversation::count())->toBe(1)->and(Conversation::first()->status)->toBe(ConversationStatus::Open);
});

it('moves delivery status only forward', function () {
    $m = Message::factory()->create(['platform' => Platform::Instagram, 'external_id' => 'out1', 'status' => MessageStatus::Read]);
    app(InboxIngestor::class)->ingestReceipt(new DeliveryReceiptData(Platform::Instagram, 'out1', MessageStatus::Delivered, CarbonImmutable::now()));
    expect($m->fresh()->status)->toBe(MessageStatus::Read);
});
