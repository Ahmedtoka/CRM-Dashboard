<?php

// TikTok's messaging limits (x6, 2026-10-24) as the queue and the bot see them through WindowPolicy:
// 10 messages before her first reply, unlimited for 48 h after each of her replies, 3 after 48 h of silence,
// and never a first word from the business.

use App\Channels\Adapters\TikTok\TikTokWindow;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Inbox\WindowPolicy;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->account = ChannelAccount::factory()->create(['platform' => Platform::TikTok, 'driver' => 'fake', 'external_id' => 'open-id-levoile']);
    $this->conversation = Conversation::factory()->for($this->account, 'channelAccount')->create(['platform' => Platform::TikTok, 'last_customer_message_at' => now()->subMinutes(5)]);
    CustomerIdentity::factory()->create(['customer_id' => $this->conversation->customer_id, 'platform' => Platform::TikTok, 'external_id' => 'uid-mona']);
    $this->conversation->messages()->create(['platform' => Platform::TikTok, 'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer, 'body' => 'hi', 'external_id' => 'in-1', 'status' => MessageStatus::Received, 'sent_at' => now()->subMinutes(5), 'created_at' => now()->subMinutes(5)]);
});

function ttOut(Conversation $c, CarbonImmutable $at, SenderType $sender = SenderType::Bot, MessageStatus $status = MessageStatus::Sent): void
{
    // created_at is not mass-assignable: set it after the insert so the row really sits at $at.
    $c->messages()->create(['platform' => Platform::TikTok, 'direction' => MessageDirection::Out, 'sender_type' => $sender, 'body' => 'x', 'status' => $status, 'sent_at' => $at])
        ->forceFill(['created_at' => $at])->saveQuietly();
}

it('allows 10 messages after her first message and closes the window on the eleventh', function () {
    $policy = app(WindowPolicy::class);
    expect(TikTokWindow::remaining($this->conversation))->toBe(10)
        ->and($policy->evaluate($this->conversation, SenderType::Bot)->mode)->toBe('open');

    foreach (range(1, 9) as $i) {
        ttOut($this->conversation, CarbonImmutable::now()->subMinutes(4)->addSeconds($i));
    }
    expect(TikTokWindow::remaining($this->conversation))->toBe(1)->and($policy->evaluate($this->conversation, SenderType::Bot)->mode)->toBe('open');

    ttOut($this->conversation, CarbonImmutable::now()->subMinutes(3));
    expect(TikTokWindow::remaining($this->conversation))->toBe(0)
        ->and($policy->evaluate($this->conversation, SenderType::Bot)->mode)->toBe('closed')
        ->and($policy->evaluate($this->conversation, SenderType::User)->mode)->toBe('closed');
});

it('does not count failed sends or system lines against the cap', function () {
    foreach (range(1, 10) as $i) {
        ttOut($this->conversation, CarbonImmutable::now()->subMinutes(4)->addSeconds($i), status: MessageStatus::Failed);
    }
    $this->conversation->messages()->create(['platform' => Platform::TikTok, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::System, 'body' => 'note', 'status' => MessageStatus::Sent]);

    expect(TikTokWindow::remaining($this->conversation))->toBe(10);
});

it('is unlimited for 48 hours once she has replied to one of our messages', function () {
    ttOut($this->conversation, CarbonImmutable::now()->subHours(2)); // we wrote before her last message → she replied to us
    $this->conversation->update(['last_customer_message_at' => now()->subMinutes(5)]);

    foreach (range(1, 12) as $i) {
        ttOut($this->conversation, CarbonImmutable::now()->subMinutes(4)->addSeconds($i));
    }

    expect(TikTokWindow::remaining($this->conversation))->toBeNull()
        ->and(app(WindowPolicy::class)->evaluate($this->conversation, SenderType::Bot)->mode)->toBe('open');
});

it('leaves staff 3 messages after 48 hours of silence and nothing for the bot', function () {
    $this->conversation->update(['last_customer_message_at' => now()->subHours(50)]);
    $policy = app(WindowPolicy::class);

    expect(TikTokWindow::remaining($this->conversation))->toBe(3)
        ->and($policy->evaluate($this->conversation, SenderType::Bot)->mode)->toBe('closed')
        ->and($policy->evaluate($this->conversation, SenderType::User)->mode)->toBe('human_agent');

    foreach (range(1, 3) as $i) {
        ttOut($this->conversation, CarbonImmutable::now()->subMinutes(10)->addSeconds($i), SenderType::User);
    }

    expect(TikTokWindow::remaining($this->conversation))->toBe(0)->and($policy->evaluate($this->conversation, SenderType::User)->mode)->toBe('closed');
});

it('never lets the business write first', function () {
    $fresh = Conversation::factory()->for($this->account, 'channelAccount')->create(['platform' => Platform::TikTok, 'last_customer_message_at' => null]);

    expect(TikTokWindow::remaining($fresh))->toBe(0)->and(app(WindowPolicy::class)->evaluate($fresh, SenderType::User)->mode)->toBe('closed');
});

it('throws the WindowClosedException the queue already handles when the cap is spent', function () {
    foreach (range(1, 10) as $i) {
        ttOut($this->conversation, CarbonImmutable::now()->subMinutes(4)->addSeconds($i));
    }

    expect(fn () => app(OutboundService::class)->sendBot($this->conversation, 'كمان رسالة'))->toThrow(WindowClosedException::class);
});
