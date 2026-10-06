<?php

use App\Models\ActivityLog;
use App\Models\AnalyticsDaily;
use App\Models\BotRule;
use App\Models\BotRun;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\City;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\ConversationParticipant;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Post;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserPlatform;
use App\Models\UserSession;
use App\Models\WebhookEvent;

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

    expect($conv->customer->is($customer))->toBeTrue()
        ->and($conv->channelAccount->is($account))->toBeTrue()
        ->and($conv->tags->pluck('id'))->toContain($tag->id)
        ->and($conv->firstResponder->is($u1))->toBeTrue()
        ->and($conv->lastResponder->is($u1))->toBeTrue()
        ->and($conv->lockedBy->is($u1))->toBeTrue()
        ->and($conv->resolvedBy->is($u1))->toBeTrue()
        ->and($customer->orders->pluck('id'))->toContain($order->id)
        ->and($u1->userPlatforms()->count())->toBe(0);
});
