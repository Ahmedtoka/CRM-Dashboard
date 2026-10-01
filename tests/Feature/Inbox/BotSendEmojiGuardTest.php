<?php

use App\Enums\Platform;
use App\Inbox\EmptyBotMessageException;
use App\Inbox\Jobs\SendOutboundMessage;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/** Spec 2026-10-01 §6: the send gate strips what a stored text, a translation or the AI may still carry. */
function emojiGuardConversation(): Conversation
{
    Event::fake();
    Bus::fake();
    $account = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $c = Conversation::factory()->create(['channel_account_id' => $account->id, 'platform' => Platform::Facebook, 'last_customer_message_at' => now()]);
    Message::factory()->create(['conversation_id' => $c->id, 'direction' => 'in', 'sender_type' => 'customer', 'created_at' => now()]);

    return $c;
}

it('never lets the bot send an emoji, even when a stored text or the AI has one', function () {
    $c = emojiGuardConversation();
    $m = app(OutboundService::class)->sendBot($c, 'أهلاً بيكي 🌸', buttons: [['title' => '🛍️ تسوقي', 'payload' => 'shop']]);

    expect($m->body)->toBe('أهلاً بيكي')
        ->and($m->buttons[0]['title'])->toBe('تسوقي')
        ->and($m->buttons[0]['payload'])->toBe('shop')
        ->and($m->fresh()->body)->toBe('أهلاً بيكي');
});

it('strips the cards too, and leaves their links alone', function () {
    $c = emojiGuardConversation();
    $cards = ['type' => 'generic', 'cards' => [['title' => '✨ فستان', 'subtitle' => '500 جنيه', 'buttons' => [['type' => 'web_url', 'title' => '📍 الخريطة', 'url' => 'https://example.test/a']]]]];
    $m = app(OutboundService::class)->sendBot($c, 'الموديلات 👇', cards: $cards);

    expect($m->body)->toBe('الموديلات')
        ->and($m->cards['cards'][0]['title'])->toBe('فستان')
        ->and($m->cards['cards'][0]['buttons'][0]['title'])->toBe('الخريطة')
        ->and($m->cards['cards'][0]['buttons'][0]['url'])->toBe('https://example.test/a');
});

it('strips a bot attachment caption', function () {
    $c = emojiGuardConversation();
    $attachment = MessageAttachment::factory()->create(['message_id' => null]);
    $m = app(OutboundService::class)->sendBotAttachment($c, $attachment, 'جدول المقاسات 📏');

    expect($m->body)->toBe('جدول المقاسات');
});

it('keeps system lines emoji-free too', function () {
    $c = emojiGuardConversation();
    expect(app(OutboundService::class)->sendSystem($c, '📦 اتشحن')->body)->toBe('اتشحن');
});

it('never touches what a moderator types by hand', function () {
    $c = emojiGuardConversation();
    $user = User::factory()->create(['role' => 'admin']);
    $m = app(OutboundService::class)->sendHuman($c, $user, 'تمام يا قمر 🌸');

    expect($m->body)->toBe('تمام يا قمر 🌸');
});

it('never queues an empty message when a bot text was nothing but emoji', function () {
    $c = emojiGuardConversation();
    Log::spy();

    expect(fn () => app(OutboundService::class)->sendBot($c, '🌸 🙏'))->toThrow(EmptyBotMessageException::class)
        ->and(fn () => app(OutboundService::class)->sendBot($c, '🌸'))->toThrow(WindowClosedException::class)
        ->and($c->messages()->where('sender_type', 'bot')->count())->toBe(0);
    Bus::assertNotDispatched(SendOutboundMessage::class);
    Log::shouldHaveReceived('warning')->with('outbound.bot_empty_after_emoji_strip', ['conversation_id' => $c->id]);

    // With a button there is still something to send.
    $m = app(OutboundService::class)->sendBot($c, '👇', buttons: [['title' => 'القائمة', 'payload' => 'menu:main_menu']]);
    expect($m->body)->toBe('')->and($m->buttons[0]['title'])->toBe('القائمة');
});
