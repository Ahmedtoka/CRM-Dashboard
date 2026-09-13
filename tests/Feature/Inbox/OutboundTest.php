<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Enums\{Platform, Handler, MessageDirection, MessageStatus, SenderType, UserRole};
use App\Inbox\OutboundService;
use App\Models\{ActivityLog, ChannelAccount, Conversation, CustomerIdentity, Customer, Message, User};
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::Facebook]);
    $this->conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create([
        'platform' => Platform::Facebook, 'handler' => Handler::Bot, 'needs_human' => true, 'last_customer_message_at' => now()->subMinutes(5), 'handover_at' => now()->subMinutes(2)]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform' => Platform::Facebook]);
});

it('sends, takes over from bot and records first response', function () {
    $msg = app(OutboundService::class)->sendHuman($this->conv, $this->mod, 'أهلا بيكي');
    $c = $this->conv->fresh();
    expect($msg->fresh()->status)->toBe(MessageStatus::Sent)   // sync queue in tests
        ->and($c->handler)->toBe(Handler::Human)->and($c->needs_human)->toBeFalse()
        ->and($c->first_responder_id)->toBe($this->mod->id)->and($c->first_response_at)->not->toBeNull()
        ->and(FakeChannelAdapter::sent())->toHaveCount(1);
});

it('marks message failed with platform error', function () {
    FakeChannelAdapter::failNext('(#10) outside window');
    $msg = app(OutboundService::class)->sendHuman($this->conv, $this->mod, 'test');
    expect($msg->fresh()->status)->toBe(MessageStatus::Failed)->and($msg->fresh()->error)->toContain('outside window');
});

it('ignores a spam or low-value customer message when computing first response time', function () {
    $this->conv->forceFill(['handover_at' => null])->save();

    Message::factory()->create([
        'conversation_id' => $this->conv->id,
        'direction' => MessageDirection::In,
        'sender_type' => SenderType::Customer,
        'is_spam' => true,
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
    ]);
    Message::factory()->create([
        'conversation_id' => $this->conv->id,
        'direction' => MessageDirection::In,
        'sender_type' => SenderType::Customer,
        'created_at' => now()->subMinutes(1),
        'updated_at' => now()->subMinutes(1),
    ]);

    app(OutboundService::class)->sendHuman($this->conv, $this->mod, 'أهلا بيكي');

    // ~60s since the real message, not ~1800s since the spam one.
    $seconds = ActivityLog::where('action', 'conversation.first_response')->value('meta')['seconds'];
    expect($seconds)->toBeGreaterThanOrEqual(59)->toBeLessThan(120);
});

it('forbids moderators on other platforms', function () {
    $other = User::factory()->create(['role' => UserRole::Moderator]);
    app(OutboundService::class)->sendHuman($this->conv, $other, 'x');
})->throws(Illuminate\Auth\Access\AuthorizationException::class);
