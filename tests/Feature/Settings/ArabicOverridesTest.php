<?php

use App\Bot\Flows\Steps\ContactStep;
use App\Bot\Language\ArabicOverrides;
use App\Bot\Language\TranslationMask;
use App\Bot\Replies\ReplyCatalog;
use App\Enums\Platform;
use App\Inbox\OutboundService;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;

it('lists the sentences written in code on the replies page, applies the owner\'s wording when the bot sends one, and restores the original', function () {
    Event::fake();
    $supervisor = User::factory()->create(['role' => 'supervisor']);
    $masked = app(TranslationMask::class)->mask(ContactStep::ASK_BOTH_TEXT)[0];

    $row = app(ReplyCatalog::class)->rows()->firstWhere('key', $masked);
    expect($row)->not->toBeNull()->and($row['section'])->toBe('steps')->and($row['source'])->toBe('text')->and($row['original'])->toBeNull();

    // A marker must survive an edit.
    // A fill-in value (here the order number) must stay; an emoji she changes is hers.
    $this->actingAs($supervisor)->putJson('/settings/bot-replies/text', ['source' => 'الأوردر رقم 1047 اتشحن ✨', 'text' => 'أوردرك اتشحن ✨'])->assertStatus(422);
    $this->actingAs($supervisor)->putJson('/settings/bot-replies/text', ['source' => 'الأوردر رقم 1047 اتشحن ✨', 'text' => 'أوردرك رقم 1047 خرج من عندنا 🚚'])->assertOk();
    expect(app(ArabicOverrides::class)->apply('الأوردر رقم 2088 اتشحن ✨'))->toBe('أوردرك رقم 2088 خرج من عندنا 🚚');

    $this->actingAs($supervisor)->putJson('/settings/bot-replies/text', ['source' => ContactStep::ASK_BOTH_TEXT, 'text' => 'اسم حضرتك ورقم موبايلك لو سمحتي 💐'])->assertOk();
    expect(app(ReplyCatalog::class)->rows()->firstWhere('key', $masked)['reply'])->toBe('اسم حضرتك ورقم موبايلك لو سمحتي 💐')
        ->and(app(ArabicOverrides::class)->apply(ContactStep::ASK_BOTH_TEXT))->toBe('اسم حضرتك ورقم موبايلك لو سمحتي 💐');

    $account = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $c = Conversation::factory()->create(['channel_account_id' => $account->id, 'platform' => Platform::Facebook, 'last_customer_message_at' => now()]);
    Message::factory()->create(['conversation_id' => $c->id, 'direction' => 'in', 'sender_type' => 'customer', 'created_at' => now()]);
    $sent = app(OutboundService::class)->sendBot($c, ContactStep::ASK_BOTH_TEXT);
    expect($sent->body)->toBe('اسم حضرتك ورقم موبايلك لو سمحتي 💐');

    $this->actingAs($supervisor)->deleteJson('/settings/bot-replies/text', ['source' => ContactStep::ASK_BOTH_TEXT])->assertOk();
    expect(app(ArabicOverrides::class)->apply(ContactStep::ASK_BOTH_TEXT))->toBe(ContactStep::ASK_BOTH_TEXT);
});
