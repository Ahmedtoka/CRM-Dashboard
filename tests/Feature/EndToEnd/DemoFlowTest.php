<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Commerce\FakeCommerceProvider;
use App\Enums\{CommentStatus, ConversationSource, Handler, MessageStatus, ParticipantRole, Platform, UserRole};
use App\Models\{BotRule, BotSetting, ChannelAccount, City, Comment, Conversation, ConversationParticipant, CustomerIdentity, Product, ProductVariant, User};
use Carbon\CarbonImmutable;

/**
 * The owner's demo, end to end over HTTP with the fake drivers (sync queue, no broadcasting):
 * simulator → bot → handover → moderator reply → COD + payment-link orders → payment →
 * ad comment → private reply → reports → moderator scoping → language switch.
 */
it('runs the whole demo flow over HTTP', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 11:00:00', 'Africa/Cairo'));
    FakeChannelAdapter::reset();
    FakeCommerceProvider::$payloads = [];
    FakeCommerceProvider::$forceError = null;

    foreach (Platform::cases() as $p) {
        ChannelAccount::factory()->create(['platform' => $p, 'external_id' => 'demo-'.$p->value, 'driver' => 'fake']);
    }
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => true, 'working_hours' => null, 'max_bot_turns' => 5, 'handover_keywords' => ['عايز اكلم حد']]);
    BotRule::factory()->create([
        'name' => 'السعر', 'keywords' => ['بكام'], 'private_reply' => 'الأسعار في الكتالوج 👗', 'public_replies' => ['ردينا عليك في الخاص 💌'],
        'scope' => 'both', 'platforms' => [], 'action' => 'reply', 'is_active' => true,
    ]);
    $variant = ProductVariant::factory()->for(Product::factory()->state(['title' => 'فستان ستان']))->create(['price' => 1250, 'shopify_id' => '4455', 'inventory_quantity' => 10]);
    $city = City::factory()->create(['shipping_fee' => 60]);

    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $supervisor = User::factory()->create(['role' => UserRole::Supervisor]);
    $whatsappMod = User::factory()->create(['role' => UserRole::Moderator, 'name' => 'Heba Adel', 'locale' => 'ar']);
    $whatsappMod->userPlatforms()->create(['platform' => Platform::WhatsApp]);
    $facebookMod = User::factory()->create(['role' => UserRole::Moderator]);
    $facebookMod->userPlatforms()->create(['platform' => Platform::Facebook]);

    // 1) A WhatsApp customer asks the price: the bot answers from the rule.
    $this->actingAs($admin)->postJson('/simulator/message', ['platform' => 'whatsapp', 'customer_key' => '201001112233', 'name' => 'Nour', 'text' => 'بكام؟'])->assertCreated();

    $identity = CustomerIdentity::where('platform', 'whatsapp')->where('external_id', '201001112233')->sole();
    $conversation = Conversation::where('customer_id', $identity->customer_id)->sole();
    $botReply = $conversation->messages()->where('sender_type', 'bot')->sole();

    expect($botReply->body)->toBe('الأسعار في الكتالوج 👗')
        ->and($botReply->status)->toBe(MessageStatus::Sent)
        ->and($conversation->channelAccount->external_id)->toBe('demo-whatsapp');

    // 2) The customer asks for a human: handover.
    $this->actingAs($admin)->postJson('/simulator/message', ['platform' => 'whatsapp', 'customer_key' => '201001112233', 'name' => 'Nour', 'text' => 'عايز اكلم حد'])->assertCreated();

    $conversation->refresh();
    expect($conversation->handler)->toBe(Handler::Human)->and($conversation->needs_human)->toBeTrue()
        ->and(Conversation::count())->toBe(1);

    // 3) The WhatsApp moderator replies: human handler, first-response role.
    $this->actingAs($whatsappMod)->postJson("/inbox/conversations/{$conversation->id}/messages", ['body' => 'أهلا يا نور، معاكي هبة'])
        ->assertCreated()
        ->assertJsonPath('data.user.id', $whatsappMod->id);

    $conversation->refresh();
    expect($conversation->needs_human)->toBeFalse()
        ->and($conversation->first_responder_id)->toBe($whatsappMod->id)
        ->and(ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $whatsappMod->id)->sole()->role)->toBe(ParticipantRole::First);

    // 4) COD order: confirmed on Shopify with attribution tags + note, shipment created.
    $shipping = ['name' => 'Nour', 'phone' => '01001112233', 'city_id' => $city->id, 'address' => '12 شارع النصر'];
    $cod = $this->actingAs($whatsappMod)->postJson("/inbox/conversations/{$conversation->id}/orders", [
        'idempotency_key' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'cod', 'items' => [['variant_id' => $variant->id, 'qty' => 1]], 'shipping' => $shipping, 'note' => 'التسليم بعد العصر',
    ]);
    expect($cod->status())->toBeIn([200, 201]);
    $cod->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.shipment.status', 'created')->assertJsonPath('data.total', 1310);

    $payload = FakeCommerceProvider::$payloads[0];
    expect($payload->tags)->toContain('social-crm', 'platform:whatsapp', 'mod:heba-adel')
        ->and($payload->note)->toContain('Created by Heba Adel')
        ->and($payload->note)->toContain('التسليم بعد العصر')
        ->and($payload->noteAttributes['crm_order_id'])->toBe($cod->json('data.id'));

    // 5) Payment-link order, paid from the simulator: confirmed + shipment.
    $link = $this->actingAs($whatsappMod)->postJson("/inbox/conversations/{$conversation->id}/orders", [
        'type' => 'payment_link', 'items' => [['variant_id' => $variant->id, 'qty' => 2]], 'shipping' => $shipping,
    ]);
    $link->assertJsonPath('data.status', 'awaiting_payment');
    expect($link->json('data.invoice_url'))->toStartWith('https://');

    $this->actingAs($admin)->postJson('/simulator/orders/'.$link->json('data.id').'/pay')
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.shipment.status', 'created');

    // 6) A comment on a Facebook ad: public reply + private reply opening an ad conversation.
    $this->actingAs($admin)->postJson('/simulator/comment', [
        'platform' => 'facebook', 'post_key' => 'ad-summer', 'is_ad' => true, 'customer_key' => 'fb-778', 'name' => 'Omar', 'text' => 'بكام الفستان؟',
    ])->assertCreated();

    $comment = Comment::sole();
    $adConversation = Conversation::find($comment->conversation_id);

    expect($comment->status)->toBe(CommentStatus::Replied)
        ->and($comment->public_reply)->toBe('ردينا عليك في الخاص 💌')
        ->and($comment->private_reply_sent_at)->not->toBeNull()
        ->and($adConversation)->not->toBeNull()
        ->and($adConversation->source)->toBe(ConversationSource::Ad)
        ->and($adConversation->source_comment_id)->toBe($comment->id)
        ->and($adConversation->messages()->where('direction', 'out')->value('body'))->toBe('الأسعار في الكتالوج 👗');

    // 7) Reports for today (Cairo) are populated.
    $today = CarbonImmutable::now('Africa/Cairo')->toDateString();
    $report = fn (string $path) => (fn (array $json) => $json['data'] ?? $json)(
        $this->actingAs($supervisor, 'sanctum')->getJson("/api/v1/reports/{$path}?from={$today}&to={$today}")->assertOk()->json()
    );

    $team = $report('team');
    // Two WhatsApp customer messages; the ad comment is a comment, not an inbound message.
    expect($team['metrics']['inbound_messages'])->toBe(2)
        ->and($team['metrics']['comments_total'])->toBe(1)
        ->and($team['metrics']['orders_count'])->toBe(2)
        ->and(collect($team['leaderboard'])->firstWhere('user.id', $whatsappMod->id)['messages_sent'])->toBe(1);

    $user = $report("users/{$whatsappMod->id}");
    expect($user['metrics']['messages_sent'])->toBe(1)
        ->and($user['metrics']['first_responses'])->toBe(1)
        ->and($user['metrics']['orders_count'])->toBe(2)
        ->and($user['metrics']['payment_link_paid'])->toBe(1);

    $bot = $report('bot');
    expect($bot['metrics']['messages_sent'])->toBeGreaterThan(0)
        ->and($bot['metrics']['handovers'])->toBe(1)
        ->and($bot['metrics']['comments_replied'])->toBe(1)
        ->and($bot['metrics']['private_replies'])->toBe(1);

    // 8) A Facebook-only moderator cannot open the WhatsApp conversation.
    $this->actingAs($facebookMod)->getJson("/inbox/conversations/{$conversation->id}")->assertForbidden();

    // 9) Switching the language changes the shared locale.
    $this->actingAs($whatsappMod)->post('/locale/en')->assertRedirect();
    expect($whatsappMod->fresh()->locale)->toBe('en');

    $this->actingAs($whatsappMod->fresh())->get('/reports/me')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('locale', 'en'));
});
