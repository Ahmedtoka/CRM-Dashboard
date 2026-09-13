<?php

use App\Models\{
    ActivityLog,
    AnalyticsDaily,
    BotRule,
    BotRun,
    BotSetting,
    ChannelAccount,
    City,
    Comment,
    Conversation,
    ConversationNote,
    ConversationParticipant,
    Customer,
    CustomerIdentity,
    Message,
    Order,
    OrderItem,
    Post,
    Product,
    ProductVariant,
    QuickReply,
    Shipment,
    ShipmentEvent,
    Tag,
    User,
    UserPlatform,
    UserSession,
    WebhookEvent,
};

it('creates every model via its factory', function () {
    expect(User::factory()->create())->toBeInstanceOf(User::class)
        ->and(UserPlatform::factory()->create())->toBeInstanceOf(UserPlatform::class)
        ->and(ChannelAccount::factory()->create())->toBeInstanceOf(ChannelAccount::class)
        ->and(Customer::factory()->create())->toBeInstanceOf(Customer::class)
        ->and(CustomerIdentity::factory()->create())->toBeInstanceOf(CustomerIdentity::class)
        ->and(City::factory()->create())->toBeInstanceOf(City::class)
        ->and(Conversation::factory()->create())->toBeInstanceOf(Conversation::class)
        ->and(Message::factory()->create())->toBeInstanceOf(Message::class)
        ->and(ConversationNote::factory()->create())->toBeInstanceOf(ConversationNote::class)
        ->and(Tag::factory()->create())->toBeInstanceOf(Tag::class)
        ->and(QuickReply::factory()->create())->toBeInstanceOf(QuickReply::class)
        ->and(ConversationParticipant::factory()->create())->toBeInstanceOf(ConversationParticipant::class)
        ->and(Post::factory()->create())->toBeInstanceOf(Post::class)
        ->and(Comment::factory()->create())->toBeInstanceOf(Comment::class)
        ->and(BotRule::factory()->create())->toBeInstanceOf(BotRule::class)
        ->and(BotSetting::factory()->create())->toBeInstanceOf(BotSetting::class)
        ->and(BotRun::factory()->create())->toBeInstanceOf(BotRun::class)
        ->and(Product::factory()->create())->toBeInstanceOf(Product::class)
        ->and(ProductVariant::factory()->create())->toBeInstanceOf(ProductVariant::class)
        ->and(Order::factory()->create())->toBeInstanceOf(Order::class)
        ->and(OrderItem::factory()->create())->toBeInstanceOf(OrderItem::class)
        ->and(Shipment::factory()->create())->toBeInstanceOf(Shipment::class)
        ->and(ShipmentEvent::factory()->create())->toBeInstanceOf(ShipmentEvent::class)
        ->and(ActivityLog::factory()->create())->toBeInstanceOf(ActivityLog::class)
        ->and(UserSession::factory()->create())->toBeInstanceOf(UserSession::class)
        ->and(AnalyticsDaily::factory()->create())->toBeInstanceOf(AnalyticsDaily::class)
        ->and(WebhookEvent::factory()->create())->toBeInstanceOf(WebhookEvent::class);
});

it('wires up the key relationships', function () {
    $customer = Customer::factory()->create();
    $account = ChannelAccount::factory()->create();
    $conv = Conversation::factory()->for($customer)->for($account, 'channelAccount')->create();
    $tag = Tag::factory()->create();
    $conv->tags()->attach($tag);

    $u1 = User::factory()->create();
    $conv->update(['first_responder_id' => $u1->id, 'last_responder_id' => $u1->id, 'locked_by_id' => $u1->id, 'resolved_by_id' => $u1->id]);

    $order = Order::factory()->for($customer)->for($conv, 'conversation')->create();
    $shipment = Shipment::factory()->for($order)->create();

    expect($conv->customer->is($customer))->toBeTrue()
        ->and($conv->channelAccount->is($account))->toBeTrue()
        ->and($conv->tags->pluck('id'))->toContain($tag->id)
        ->and($conv->firstResponder->is($u1))->toBeTrue()
        ->and($conv->lastResponder->is($u1))->toBeTrue()
        ->and($conv->lockedBy->is($u1))->toBeTrue()
        ->and($conv->resolvedBy->is($u1))->toBeTrue()
        ->and($customer->orders->pluck('id'))->toContain($order->id)
        ->and($order->shipment->is($shipment))->toBeTrue()
        ->and($u1->userPlatforms()->count())->toBe(0);
});
