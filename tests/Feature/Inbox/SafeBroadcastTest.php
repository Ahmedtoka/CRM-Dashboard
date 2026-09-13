<?php

use App\Channels\Adapters\FakeChannelAdapter;
use App\Commerce\FakeCommerceProvider;
use App\Commerce\OrderService;
use App\Enums\{Handler, MessageStatus, OrderStatus, Platform, SenderType, UserRole};
use App\Inbox\OutboundService;
use App\Models\{BotRule, BotSetting, ChannelAccount, City, Conversation, Customer, CustomerIdentity, Product, ProductVariant, User};
use App\Simulator\Simulator;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Broadcast;

/**
 * Reverb/Pusher being down must never break the business flow: every broadcast
 * is best-effort and happens after the work (and its queued jobs) are done.
 */
beforeEach(function () {
    FakeChannelAdapter::reset();
    FakeCommerceProvider::$payloads = [];
    FakeCommerceProvider::$forceError = null;

    Broadcast::extend('throwing', fn () => new class extends Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new BroadcastException('Reverb is unreachable');
        }
    });
    config(['broadcasting.connections.throwing' => ['driver' => 'throwing'], 'broadcasting.default' => 'throwing']);

    $this->account = ChannelAccount::factory()->create(['platform' => Platform::Facebook, 'external_id' => 'demo-facebook']);
    $this->customer = Customer::factory()->create();
    CustomerIdentity::factory()->for($this->customer)->create(['platform' => Platform::Facebook]);
    $this->conv = Conversation::factory()->for($this->customer)->for($this->account, 'channelAccount')->create([
        'platform' => Platform::Facebook, 'handler' => Handler::Bot, 'last_customer_message_at' => now()->subMinute(),
    ]);
    $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform' => Platform::Facebook]);
});

it('still sends a human reply when broadcasting fails', function () {
    $message = app(OutboundService::class)->sendHuman($this->conv, $this->mod, 'أهلا بيكي');

    expect($message->fresh()->status)->toBe(MessageStatus::Sent)
        ->and(FakeChannelAdapter::sent())->toHaveCount(1);
});

it('still runs the bot on an inbound message when broadcasting fails', function () {
    BotSetting::current()->update(['enabled' => true, 'ai_enabled' => false, 'working_hours' => null, 'max_bot_turns' => 5]);
    BotRule::factory()->create(['keywords' => ['بكام'], 'private_reply' => 'الأسعار في الكتالوج', 'action' => 'reply', 'scope' => 'both', 'platforms' => [], 'is_active' => true]);

    $inbound = app(Simulator::class)->customerMessage(Platform::Facebook, 'cust-broadcast', 'Hala', 'بكام؟');

    $bot = $inbound->conversation->messages()->where('sender_type', SenderType::Bot->value)->first();

    expect($bot)->not->toBeNull()
        ->and($bot->status)->toBe(MessageStatus::Sent);
});

it('still creates an order when broadcasting fails', function () {
    $variant = ProductVariant::factory()->for(Product::factory())->create(['price' => 500, 'shopify_id' => '111']);
    $city = City::factory()->create(['shipping_fee' => 60]);

    $order = app(OrderService::class)->create($this->conv, $this->mod, [
        'type' => 'cod',
        'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        'shipping' => ['name' => 'Nour', 'phone' => '01001234567', 'city_id' => $city->id, 'address' => 'شارع النصر'],
    ]);

    expect($order->status)->toBe(OrderStatus::Confirmed)
        ->and($order->shipment)->not->toBeNull();
});
