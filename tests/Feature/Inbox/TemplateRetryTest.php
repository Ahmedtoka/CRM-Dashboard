<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Enums\Handler;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\UserRole;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Event;

// Audit A9: a failed WhatsApp template message must be retried as the same
// template, not as plain text (which WhatsApp refuses outside the 24h window).

beforeEach(function () {
    Event::fake();
    FakeChannelAdapter::reset();
    $acc = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::WhatsApp]);
    // 30h since the customer's last message: WhatsApp is template-only.
    $this->conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')
        ->create(['platform' => Platform::WhatsApp, 'handler' => Handler::Human, 'last_customer_message_at' => now()->subHours(30)]);
    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    $this->template = ['name' => 'order_ready', 'language' => 'ar_EG', 'params' => ['#1042', 'ليلى']];
});

it('persists the template on the message', function () {
    $msg = app(OutboundService::class)->sendHuman($this->conv, $this->admin, 'طلبك جاهز', ['template' => $this->template]);

    expect($msg->fresh()->template)->toBe($this->template)
        ->and($msg->fresh()->is_template)->toBeTrue();
});

it('retries a failed template message as the same template outside the window', function () {
    FakeChannelAdapter::failNext('temporary outage');
    $msg = app(OutboundService::class)->sendHuman($this->conv, $this->admin, 'طلبك جاهز', ['template' => $this->template]);
    expect($msg->fresh()->status)->toBe(MessageStatus::Failed);

    app(OutboundService::class)->retry($msg->fresh(), $this->admin);

    $sent = FakeChannelAdapter::sent();
    expect($msg->fresh()->status)->toBe(MessageStatus::Sent)
        ->and(end($sent)['options']['template'] ?? null)->toBe($this->template);
});

it('still refuses to retry a failed plain-text message outside the window', function () {
    $this->conv->forceFill(['last_customer_message_at' => now()->subMinute()])->save();
    FakeChannelAdapter::failNext('temporary outage');
    $msg = app(OutboundService::class)->sendHuman($this->conv, $this->admin, 'أهلا');
    $this->conv->forceFill(['last_customer_message_at' => now()->subHours(30)])->save();

    expect(fn () => app(OutboundService::class)->retry($msg->fresh(), $this->admin))
        ->toThrow(WindowClosedException::class);
});
