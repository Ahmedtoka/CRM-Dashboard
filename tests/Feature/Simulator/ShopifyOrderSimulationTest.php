<?php

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\OrderConfirmations;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderSource;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Models\WebhookEvent;
use App\Simulator\Simulator;

it('fires a fake Shopify orders/create through the real webhook pipeline', function () {
    $order = app(Simulator::class)->shopifyOrderCreated('01001234567', 'سارة محمد');

    $event = WebhookEvent::where('provider', 'shopify')->sole();
    expect($event->event_type)->toBe('orders/create')
        ->and($event->status)->toBe('processed')
        ->and($order->source)->toBe(OrderSource::Store)
        ->and($order->shipping_phone)->toBe('01001234567')
        ->and($order->shipping_name)->toBe('سارة محمد')
        ->and($order->items()->count())->toBe(2)
        ->and((float) $order->total)->toBeGreaterThan(0.0)
        ->and(($order->placed_at ?? $order->created_at)->diffInMinutes(now(), true))->toBeLessThan(1.0);

    // Each run is a new order.
    $second = app(Simulator::class)->shopifyOrderCreated('01001234567');
    expect($second->id)->not->toBe($order->id)
        ->and($second->shopify_order_id)->not->toBe($order->shopify_order_id);
});

it('refuses outside local and testing', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => app(Simulator::class)->shopifyOrderCreated('01001234567'))->toThrow(RuntimeException::class);
        expect(Order::count())->toBe(0);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('prints the new order and says when confirmation is off', function () {
    $this->artisan('crm:simulate-shopify-order', ['--phone' => '01001234567'])
        ->expectsOutputToContain('Fake Shopify order')
        ->expectsOutputToContain('turned off')
        ->assertSuccessful();

    expect(Order::count())->toBe(1);
});

it('is not available in production', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        $this->artisan('crm:simulate-shopify-order')->expectsOutputToContain('local')->assertFailed();
        expect(Order::count())->toBe(0);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('sends the confirmation right away with --now', function () {
    ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected', 'external_id' => 'demo-whatsapp']);
    app(ConfirmationSettings::class)->save(['enabled' => true]);
    $sent = new ArrayObject;
    app()->instance(ConfirmationSender::class, new class($sent) implements ConfirmationSender
    {
        public function __construct(private ArrayObject $sent) {}

        public function send(OrderConfirmation $confirmation, string $kind = 'confirm'): void
        {
            $this->sent[] = $confirmation->id;
            $confirmation->forceFill(['status' => OrderConfirmationStatus::Sent, 'sent_at' => now()])->save();
        }
    });

    $this->artisan('crm:simulate-shopify-order', ['--phone' => '01001234567', '--now' => true])
        ->expectsOutputToContain('Confirmation: sent')
        ->assertSuccessful();

    expect(OrderConfirmation::sole()->status)->toBe(OrderConfirmationStatus::Sent)
        ->and($sent->getArrayCopy())->toHaveCount(1);
})->skip(fn () => ! class_exists(OrderConfirmations::class), 'waiting for slice W1 (trigger)');
