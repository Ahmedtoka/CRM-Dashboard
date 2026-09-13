<?php

use App\Enums\{Platform, Handler, ConversationStatus, SenderType, MessageDirection, MessageStatus, UserRole};
use App\Models\{User, Customer, CustomerIdentity, ChannelAccount, Conversation, Message, BotSetting};

it('creates a full conversation graph with enum casts', function () {
    $account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $customer = Customer::factory()->create();
    $identity = CustomerIdentity::factory()->for($customer)->create(['platform' => Platform::WhatsApp]);
    $conv = Conversation::factory()->for($customer)->for($account, 'channelAccount')->create();
    $msg = Message::factory()->for($conv)->create(['direction' => MessageDirection::In, 'sender_type' => SenderType::Customer]);

    expect($conv->fresh()->platform)->toBe(Platform::WhatsApp)
        ->and($conv->handler)->toBe(Handler::Bot)
        ->and($conv->status)->toBe(ConversationStatus::Open)
        ->and($msg->fresh()->status)->toBeInstanceOf(MessageStatus::class)
        ->and($customer->identities)->toHaveCount(1);
});

it('scopes moderator platforms', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);
    $mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    expect($mod->canAccessPlatform(Platform::Facebook))->toBeTrue()
        ->and($mod->canAccessPlatform(Platform::TikTok))->toBeFalse()
        ->and($admin->canAccessPlatform(Platform::TikTok))->toBeTrue();
});

it('provides a singleton bot setting', function () {
    expect(BotSetting::current()->id)->toBe(BotSetting::current()->id);
});
