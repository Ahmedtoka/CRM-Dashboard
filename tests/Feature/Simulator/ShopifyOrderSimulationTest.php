<?php

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
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
    expect($event->payload['test'])->toBeTrue()
        ->and($event->event_type)->toBe('orders/create')
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

it('only sends a Shopify test flag of false when asked to target the fake channel', function () {
    app(Simulator::class)->shopifyOrderCreated('01001234567', test: false);

    expect(WebhookEvent::where('provider', 'shopify')->sole()->payload['test'])->toBeFalse();
});

it('requires --phone', function () {
    $this->artisan('crm:simulate-shopify-order')
        ->expectsOutputToContain('--phone is required')
        ->assertFailed();

    expect(Order::count())->toBe(0);
});

it('prints the new order and says when confirmation is off', function () {
    $this->artisan('crm:simulate-shopify-order', ['--phone' => '01001234567'])
        ->expectsOutputToContain('Fake Shopify order')
        ->expectsOutputToContain('turned off')
        ->assertSuccessful();

    expect(Order::count())->toBe(1);
});

it('marks the order as a Shopify test so no confirmation is made without --fake-channel', function () {
    app(ConfirmationSettings::class)->save(['enabled' => true]);
    ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected', 'external_id' => 'demo-whatsapp']);

    $this->artisan('crm:simulate-shopify-order', ['--phone' => '01001234567', '--now' => true])
        ->expectsOutputToContain('--fake-channel')
        ->assertSuccessful();

    expect(OrderConfirmation::sole())
        ->status->toBe(OrderConfirmationStatus::Skipped)
        ->skip_reason->toBe('test_order');
});

it('refuses --fake-channel while a live WhatsApp number is connected', function () {
    app(ConfirmationSettings::class)->save(['enabled' => true]);
    ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'live', 'status' => 'connected', 'external_id' => 'real-number']);
    ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected', 'external_id' => 'demo-whatsapp']);

    $this->artisan('crm:simulate-shopify-order', ['--phone' => '01001234567', '--fake-channel' => true])
        ->expectsOutputToContain('live WhatsApp number')
        ->assertFailed();

    expect(Order::count())->toBe(0)->and(OrderConfirmation::count())->toBe(0);
});

it('is not available in production', function () {
    app()->detectEnvironment(fn () => 'production');

    try {
        $this->artisan('crm:simulate-shopify-order', ['--phone' => '01001234567'])->expectsOutputToContain('local')->assertFailed();
        expect(Order::count())->toBe(0);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('sends the confirmation on the fake channel right away with --fake-channel --now', function () {
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

    $this->artisan('crm:simulate-shopify-order', ['--phone' => '01001234567', '--fake-channel' => true, '--now' => true])
        ->expectsOutputToContain('Confirmation: sent')
        ->assertSuccessful();

    expect(OrderConfirmation::sole()->status)->toBe(OrderConfirmationStatus::Sent)
        ->and($sent->getArrayCopy())->toHaveCount(1);
});
