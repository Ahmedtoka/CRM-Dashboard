<?php

use App\Bot\Agent\AgentOutcome;
use App\Bot\Agent\AgentTools;
use App\Bot\Flow\Orders\FakeOmsClient;
use App\Bot\Flow\Orders\OmsClient;
use App\Bot\Flow\Orders\OrderLookup;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Fulfillment;
use App\Models\Order;

/*
 * Full audit A4 (2026-10-16): knowing a mobile number is not proof of owning its orders.
 * Only the chat's own number (its customer's phone, or the WhatsApp number it writes from)
 * is; any other number or email needs the order number too before anything is revealed.
 */

beforeEach(fn () => app()->instance(OmsClient::class, new FakeOmsClient));

function chatOf(?string $phone = null, ?string $email = null): Conversation
{
    return Conversation::factory()->create([
        'customer_id' => Customer::factory()->create(['phone' => $phone, 'email' => $email])->id,
    ]);
}

function victimOrder(array $attrs = []): Order
{
    $o = Order::factory()->create($attrs + [
        'order_number' => '8100', 'shopify_order_name' => '#8100', 'shipping_phone' => '01001234567',
        'shipping_name' => 'سارة أحمد', 'shipping_city' => 'Cairo', 'shipping_province_code' => 'C', 'fulfillment_status' => 'fulfilled',
    ]);
    Fulfillment::factory()->for($o)->create(['tracking_url' => 'https://track.test/8100', 'shopify_created_at' => now()]);

    return $o;
}

it('gives the details when the mobile is the chat\'s own number', function () {
    $o = victimOrder();

    $r = app(OrderLookup::class)->find(chatOf('01001234567'), ['phone' => '+20 100 123 4567']);

    expect($r['status'])->toBe('found')
        ->and($r['snapshots'][0]->orderId)->toBe($o->id)
        ->and($r['snapshots'][0]->ownerVerified)->toBeTrue()
        ->and($r['snapshots'][0]->trackingUrl)->toBe('https://track.test/8100');
});

it('treats the WhatsApp number the chat writes from as its own', function () {
    victimOrder();
    $account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp]);
    $customer = Customer::factory()->create(['phone' => null]);
    CustomerIdentity::factory()->create(['customer_id' => $customer->id, 'platform' => Platform::WhatsApp, 'external_id' => '201001234567']);
    $chat = Conversation::factory()->create(['customer_id' => $customer->id, 'channel_account_id' => $account->id]);

    $r = app(OrderLookup::class)->find($chat, ['phone' => '01001234567']);

    expect($r['status'])->toBe('found')->and($r['snapshots'][0]->ownerVerified)->toBeTrue();
});

it('reveals nothing for another customer\'s mobile until the order number is given', function () {
    $o = victimOrder();
    $lookup = app(OrderLookup::class);
    $stranger = chatOf('01229999999');

    $r = $lookup->find($stranger, ['phone' => '01001234567']);

    expect($r['status'])->toBe('unverified')
        ->and($r['snapshots'])->toBe([])
        ->and($r['via'])->toBe('phone');

    $proven = $lookup->find($stranger, ['phone' => '01001234567', 'order_ref' => '8100']);

    expect($proven['status'])->toBe('found')
        ->and($proven['snapshots'][0]->orderId)->toBe($o->id)
        ->and($proven['snapshots'][0]->ownerVerified)->toBeTrue();
});

it('does not list another customer\'s order numbers for her mobile', function () {
    Order::factory()->count(2)->create(['shipping_phone' => '01001234567']);

    $r = app(OrderLookup::class)->find(chatOf(), ['phone' => '01001234567']);

    expect($r['status'])->toBe('unverified')->and($r['snapshots'])->toBe([]);
});

it('needs the order number for an email that is not the chat\'s own', function () {
    $owner = Customer::factory()->create(['email' => 'mona@example.test']);
    Order::factory()->for($owner)->create(['order_number' => '8200']);

    expect(app(OrderLookup::class)->find(chatOf(), ['email' => 'mona@example.test'])['status'])->toBe('unverified')
        ->and(app(OrderLookup::class)->find(chatOf(email: 'Mona@Example.test'), ['email' => 'mona@example.test'])['status'])->toBe('found');
});

it('keeps an order of the chat\'s own customer verified whatever mobile she types', function () {
    $mine = Customer::factory()->create(['phone' => '01001234567']);
    Order::factory()->for($mine)->create(['order_number' => '8300', 'shipping_phone' => '01115556666']);

    $r = app(OrderLookup::class)->find(Conversation::factory()->create(['customer_id' => $mine->id]), ['phone' => '01115556666']);

    expect($r['status'])->toBe('found')->and($r['snapshots'][0]->ownerVerified)->toBeTrue();
});

it('gives the agent no order details for another customer\'s mobile', function () {
    victimOrder();
    $tools = app(AgentTools::class);

    $out = $tools->run('get_order_status', ['phone' => '01001234567'], chatOf('01229999999'), new AgentOutcome);

    expect($out)->not->toContain('8100')
        ->not->toContain('track.test')
        ->not->toContain('القاهرة')
        ->toContain('order number');

    $proven = $tools->run('get_order_status', ['phone' => '01001234567', 'order_number' => '8100'], chatOf('01229999999'), new AgentOutcome);

    expect($proven)->toContain('8100')->toContain('track.test');
});

it('gives the agent the status for the chat\'s own mobile', function () {
    victimOrder();

    $out = app(AgentTools::class)->run('get_order_status', ['phone' => '01001234567'], chatOf('01001234567'), new AgentOutcome);

    expect($out)->toContain('8100')->toContain('track.test');
});
