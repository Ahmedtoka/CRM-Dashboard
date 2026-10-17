<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Enums\Handler;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Event;

// «ابعت منتج» in the composer (2026-10-17): the bot's product card, sent by a moderator.

beforeEach(function () {
    Event::fake();
    $acc = ChannelAccount::factory()->create(['platform' => Platform::Facebook]);
    $cust = Customer::factory()->create();
    CustomerIdentity::factory()->for($cust)->create(['platform' => Platform::Facebook]);
    $this->conv = Conversation::factory()->for($cust)->for($acc, 'channelAccount')->create([
        'platform' => Platform::Facebook, 'handler' => Handler::Bot, 'last_customer_message_at' => now()->subMinutes(5)]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform' => Platform::Facebook]);
    $this->product = Product::factory()->create(['title' => 'فستان ليلي', 'handle' => 'layla-dress', 'status' => 'active', 'image_url' => 'https://cdn.shopify.com/p.jpg']);
    $this->variant = ProductVariant::factory()->for($this->product)->create(['title' => 'أسود / M', 'price' => 950, 'inventory_quantity' => 4, 'shopify_id' => '4455']);
});

it('sends the picked variant as the bot product card, as the moderator', function () {
    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/product", ['variant_id' => $this->variant->id])
        ->assertCreated()
        ->assertJsonPath('data.sender_type', 'user')
        ->assertJsonPath('data.cards.type', 'generic');

    $m = Message::latest('id')->first();
    $card = $m->cards['cards'][0];

    expect($m->sender_type)->toBe(SenderType::User)
        ->and($m->user_id)->toBe($this->mod->id)
        ->and($m->status)->toBe(MessageStatus::Sent)
        ->and($card['title'])->toBe('فستان ليلي — أسود / M')
        ->and($card['subtitle'])->toContain('950 جنيه')->and($card['subtitle'])->toContain('متوفر')
        ->and($card['url'])->toEndWith('/products/layla-dress?variant=4455')
        ->and($card['image_url'])->toStartWith('https://cdn.shopify.com/p.jpg')
        ->and(collect($card['buttons'])->pluck('type')->all())->toBe(['web_url'])
        ->and($m->body)->toContain('فستان ليلي')
        ->and($this->conv->fresh()->handler)->toBe(Handler::Human);

    // The same send job and adapter path as a bot card: the cards ride in the options.
    $sent = FakeChannelAdapter::sent();
    expect(end($sent)['options']['cards']['type'] ?? null)->toBe('generic');
});

it('refuses an out-of-stock variant until the moderator confirms', function () {
    $this->variant->update(['inventory_quantity' => 0, 'inventory_policy' => 'deny']);

    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/product", ['variant_id' => $this->variant->id])
        ->assertStatus(409)->assertJsonPath('code', 'out_of_stock');
    expect(Message::whereNotNull('cards')->count())->toBe(0);

    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/product", ['variant_id' => $this->variant->id, 'confirm_out_of_stock' => true])
        ->assertCreated();
    expect(Message::latest('id')->first()->cards['cards'][0]['subtitle'])->toContain('نفد');
});

it('treats a variant Shopify keeps selling at zero as in stock', function () {
    $this->variant->update(['inventory_quantity' => 0, 'inventory_policy' => 'continue']);

    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/product", ['variant_id' => $this->variant->id])
        ->assertCreated();
});

it('refuses a variant of an archived product, an unknown one, and a moderator off the platform', function () {
    $this->product->update(['status' => 'archived']);
    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/product", ['variant_id' => $this->variant->id])
        ->assertUnprocessable()->assertJsonValidationErrors('variant_id');

    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/product", ['variant_id' => 999999])
        ->assertUnprocessable();

    $this->product->update(['status' => 'active']);
    $other = User::factory()->create(['role' => UserRole::Moderator]);
    $other->userPlatforms()->create(['platform' => Platform::Instagram]);
    $this->actingAs($other)->postJson("/inbox/conversations/{$this->conv->id}/product", ['variant_id' => $this->variant->id])
        ->assertForbidden();
});

it('respects the messaging window like any reply', function () {
    $this->conv->forceFill(['last_customer_message_at' => now()->subDays(10)])->save();

    $this->actingAs($this->mod)->postJson("/inbox/conversations/{$this->conv->id}/product", ['variant_id' => $this->variant->id])
        ->assertStatus(422);
    expect(Message::whereNotNull('cards')->count())->toBe(0);
});
