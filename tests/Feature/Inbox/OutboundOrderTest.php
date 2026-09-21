<?php

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\Message;
use Illuminate\Support\Facades\Event;

it('holds a message back while an earlier one of the same conversation is still queued', function () {
    Event::fake();
    config(['crm.outbound_order_wait_seconds' => 0.6]);
    $account = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $c = Conversation::factory()->create(['channel_account_id' => $account->id, 'platform' => Platform::Facebook]);
    CustomerIdentity::factory()->create(['customer_id' => $c->customer_id, 'platform' => Platform::Facebook]);
    $row = ['conversation_id' => $c->id, 'direction' => MessageDirection::Out, 'sender_type' => SenderType::Bot, 'status' => MessageStatus::Queued];
    $greeting = Message::factory()->create($row + ['body' => 'أهلاً']);
    $menu = Message::factory()->create($row + ['body' => 'اختاري من القائمة']);

    $started = microtime(true);
    dispatch_sync(new SendOutboundMessage($menu->id));

    // The greeting never left `queued`, so the menu waited the whole window before going anyway.
    expect(microtime(true) - $started)->toBeGreaterThan(0.55)
        ->and($menu->refresh()->status)->not->toBe(MessageStatus::Queued);

    $greeting->update(['status' => MessageStatus::Sent]);
    $next = Message::factory()->create($row + ['body' => 'تاني']);
    $started = microtime(true);
    dispatch_sync(new SendOutboundMessage($next->id));

    expect(microtime(true) - $started)->toBeLessThan(0.5);
});
