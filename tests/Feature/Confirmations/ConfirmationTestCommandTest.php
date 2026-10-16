<?php

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Enums\OrderConfirmationStatus;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Order;
use App\Models\OrderConfirmation;

/** Records what would be sent and marks the row sent, like the real sender does on success. */
function fakeConfirmationSender(): object
{
    $fake = new class implements ConfirmationSender
    {
        /** @var list<array{id: int, kind: string, phone: string, order_id: int}> */
        public array $sent = [];

        public function send(OrderConfirmation $confirmation, string $kind = 'confirm'): void
        {
            $this->sent[] = ['id' => $confirmation->id, 'kind' => $kind, 'phone' => $confirmation->phone_e164, 'order_id' => $confirmation->order_id];
            $confirmation->forceFill(['status' => OrderConfirmationStatus::Sent, 'sent_at' => now()])->save();
        }
    };

    app()->instance(ConfirmationSender::class, $fake);

    return $fake;
}

beforeEach(function () {
    $this->sender = fakeConfirmationSender();
    $this->account = ChannelAccount::factory()->create([
        'platform' => Platform::WhatsApp, 'driver' => 'live', 'status' => 'connected', 'profile' => ['waba_id' => '555'],
    ]);
});

it('refuses while the feature is off unless --force is given', function () {
    Order::factory()->create();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567'])
        ->expectsOutputToContain('turned off')
        ->assertFailed();

    expect($this->sender->sent)->toBe([]);
});

it('shows what it will send, asks, and sends the latest order to the given phone', function () {
    app(ConfirmationSettings::class)->save(['enabled' => true]);
    Order::factory()->create(['order_number' => '#1001']);
    $latest = Order::factory()->create(['order_number' => '#1002']);

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567'])
        ->expectsOutputToContain('#1002')
        ->expectsOutputToContain('+201001234567')
        ->expectsOutputToContain('order_confirm_ar')
        ->expectsConfirmation('Send this test message now?', 'yes')
        ->expectsOutputToContain('Sent.')
        ->assertSuccessful();

    expect($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->sent[0])->toMatchArray(['kind' => 'test', 'phone' => '+201001234567', 'order_id' => $latest->id]);

    // Buttons stay inert by default: the row is parked as skipped so a tap never tags the real order.
    $row = OrderConfirmation::sole();
    expect($row->status)->toBe(OrderConfirmationStatus::Skipped)
        ->and($row->skip_reason)->toBe('test_send')
        ->and($row->channel_account_id)->toBe($this->account->id);
});

it('keeps the row live with --live-buttons so taps act on the order', function () {
    $order = Order::factory()->create();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '+201001234567', '--order' => $order->id, '--force' => true, '--live-buttons' => true])
        ->expectsOutputToContain('tag this order in Shopify')
        ->expectsConfirmation('Send this test message now?', 'yes')
        ->assertSuccessful();

    expect(OrderConfirmation::sole()->status)->toBe(OrderConfirmationStatus::Sent);
});

it('finds the order by its number', function () {
    Order::factory()->create(['order_number' => '#2001']);
    $wanted = Order::factory()->create(['order_number' => '#2000']);

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => '2000', '--force' => true])
        ->expectsConfirmation('Send this test message now?', 'yes')
        ->assertSuccessful();

    expect($this->sender->sent[0]['order_id'])->toBe($wanted->id);
});

it('sends nothing when the answer is no', function () {
    Order::factory()->create();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--force' => true])
        ->expectsConfirmation('Send this test message now?', 'no')
        ->expectsOutputToContain('Nothing sent.')
        ->assertSuccessful();

    expect($this->sender->sent)->toBe([])->and(OrderConfirmation::count())->toBe(0);
});

it('skips the question only with --no-interaction and --force together', function () {
    Order::factory()->create();
    app(ConfirmationSettings::class)->save(['enabled' => true]);

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--no-interaction' => true])
        ->expectsOutputToContain('Nothing sent.')
        ->assertSuccessful();
    expect($this->sender->sent)->toBe([]);

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--no-interaction' => true, '--force' => true])
        ->assertSuccessful();
    expect($this->sender->sent)->toHaveCount(1);
});

it('rejects a bad phone, a missing order and an order that already has a confirmation', function () {
    $this->artisan('whatsapp:confirmation-test', ['phone' => '12', '--force' => true])
        ->expectsOutputToContain('not an Egyptian mobile number')->assertFailed();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--force' => true])
        ->expectsOutputToContain('No order found')->assertFailed();

    $confirmed = OrderConfirmation::factory()->sent()->create();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => $confirmed->order_id, '--force' => true])
        ->expectsOutputToContain('already has a confirmation')->assertFailed();

    expect($this->sender->sent)->toBe([]);
});

it('reports a failed send', function () {
    $order = Order::factory()->create();
    app()->instance(ConfirmationSender::class, new class implements ConfirmationSender
    {
        public function send(OrderConfirmation $confirmation, string $kind = 'confirm'): void
        {
            $confirmation->forceFill(['status' => OrderConfirmationStatus::Failed, 'last_error' => 'template not approved'])->save();
        }
    });

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => $order->id, '--force' => true])
        ->expectsConfirmation('Send this test message now?', 'yes')
        ->expectsOutputToContain('template not approved')
        ->assertFailed();

    // Nothing reached the phone: no row is left on the order or in the counters.
    expect(OrderConfirmation::count())->toBe(0);
});
