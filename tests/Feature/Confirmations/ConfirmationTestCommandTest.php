<?php

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Order;
use App\Models\OrderConfirmation;

/**
 * A sender with the rowless test path (WhatsAppConfirmationSender::sendTest): records what
 * it would send and answers null (sent) or the error text.
 */
function fakeTestSender(?string $error = null): object
{
    $fake = new class($error) implements ConfirmationSender
    {
        /** @var list<array{order_id: int, phone: string}> */
        public array $tests = [];

        public function __construct(private readonly ?string $error) {}

        public function send(OrderConfirmation $confirmation, string $kind = 'confirm'): void
        {
            throw new LogicException('a test send must never go through send()');
        }

        public function sendTest(Order $order, string $phoneE164): ?string
        {
            $this->tests[] = ['order_id' => $order->id, 'phone' => $phoneE164];

            return $this->error;
        }
    };

    app()->instance(ConfirmationSender::class, $fake);

    return $fake;
}

beforeEach(function () {
    $this->sender = fakeTestSender();
    $this->account = ChannelAccount::factory()->create([
        'platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected', 'external_id' => 'wa-test',
    ]);
});

it('requires --order', function () {
    Order::factory()->create();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--force' => true])
        ->expectsOutputToContain('--order is required')
        ->assertFailed();

    expect($this->sender->tests)->toBe([]);
});

it('refuses while the feature is off unless --force is given', function () {
    $order = Order::factory()->create();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => $order->id])
        ->expectsOutputToContain('turned off')
        ->assertFailed();

    expect($this->sender->tests)->toBe([]);
});

it('shows what it will send, asks, and sends the order to the given phone without making a confirmation', function () {
    app(ConfirmationSettings::class)->save(['enabled' => true]);
    $order = Order::factory()->create(['order_number' => '#1002']);

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => $order->id])
        ->expectsOutputToContain('#1002')
        ->expectsOutputToContain('+201001234567')
        ->expectsOutputToContain('order_confirm_ar')
        ->expectsConfirmation('Send this test message now?', 'yes')
        ->expectsOutputToContain('Sent.')
        ->assertSuccessful();

    expect($this->sender->tests)->toBe([['order_id' => $order->id, 'phone' => '+201001234567']])
        // No row: the order's real confirmation is never blocked or touched by a test.
        ->and(OrderConfirmation::count())->toBe(0);
});

it('leaves an existing confirmation of the order alone', function () {
    $existing = OrderConfirmation::factory()->sent()->create();
    $before = $existing->fresh()->toArray();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => $existing->order_id, '--force' => true])
        ->expectsConfirmation('Send this test message now?', 'yes')
        ->assertSuccessful();

    expect(OrderConfirmation::count())->toBe(1)
        ->and($existing->fresh()->toArray())->toBe($before);
});

it('finds the order by its number', function () {
    Order::factory()->create(['order_number' => '#2001']);
    $wanted = Order::factory()->create(['order_number' => '#2000']);

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => '2000', '--force' => true])
        ->expectsConfirmation('Send this test message now?', 'yes')
        ->assertSuccessful();

    expect($this->sender->tests[0]['order_id'])->toBe($wanted->id);
});

it('sends nothing when the answer is no', function () {
    $order = Order::factory()->create();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => $order->id, '--force' => true])
        ->expectsConfirmation('Send this test message now?', 'no')
        ->expectsOutputToContain('Nothing sent.')
        ->assertSuccessful();

    expect($this->sender->tests)->toBe([]);
});

it('skips the question only with --no-interaction and --force together', function () {
    $order = Order::factory()->create();
    app(ConfirmationSettings::class)->save(['enabled' => true]);

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => $order->id, '--no-interaction' => true])
        ->expectsOutputToContain('Nothing sent.')
        ->assertSuccessful();
    expect($this->sender->tests)->toBe([]);

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => $order->id, '--no-interaction' => true, '--force' => true])
        ->assertSuccessful();
    expect($this->sender->tests)->toHaveCount(1);
});

it('rejects a bad phone and a missing order', function () {
    $this->artisan('whatsapp:confirmation-test', ['phone' => '12', '--order' => '1', '--force' => true])
        ->expectsOutputToContain('not an Egyptian mobile number')->assertFailed();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => '999', '--force' => true])
        ->expectsOutputToContain('No order found')->assertFailed();

    expect($this->sender->tests)->toBe([]);
});

it('fails clearly without a WhatsApp number to send from', function () {
    $this->account->update(['status' => 'disconnected']);
    $order = Order::factory()->create();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => $order->id, '--force' => true])
        ->expectsOutputToContain('No connected WhatsApp number')
        ->assertFailed();

    expect($this->sender->tests)->toBe([]);
});

it('reports a failed send', function () {
    $sender = fakeTestSender('template not approved');
    $order = Order::factory()->create();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => $order->id, '--force' => true])
        ->expectsConfirmation('Send this test message now?', 'yes')
        ->expectsOutputToContain('template not approved')
        ->assertFailed();

    expect($sender->tests)->toHaveCount(1)->and(OrderConfirmation::count())->toBe(0);
});

it('says so when the sender has no test path', function () {
    app()->instance(ConfirmationSender::class, new class implements ConfirmationSender
    {
        public function send(OrderConfirmation $confirmation, string $kind = 'confirm'): void {}
    });
    $order = Order::factory()->create();

    $this->artisan('whatsapp:confirmation-test', ['phone' => '01001234567', '--order' => $order->id, '--force' => true])
        ->expectsOutputToContain('no test send')
        ->assertFailed();

    expect(OrderConfirmation::count())->toBe(0);
});
