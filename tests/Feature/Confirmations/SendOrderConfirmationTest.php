<?php

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\Jobs\SendOrderConfirmation;
use App\Confirmations\SkipReason;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderStatus;
use App\Enums\Platform;
use App\Models\ChannelAccount;
use App\Models\Order;
use App\Models\OrderConfirmation;
use Illuminate\Support\Facades\Cache;

/** Records every send and marks the row sent, like the real WhatsApp sender does. */
class W1RecordingConfirmationSender implements ConfirmationSender
{
    /** @var list<array{int, string}> */
    public array $sent = [];

    public ?Throwable $throw = null;

    public bool $recordsStatus = true;

    public function send(OrderConfirmation $confirmation, string $kind = 'confirm'): void
    {
        $this->sent[] = [$confirmation->id, $kind];

        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->recordsStatus) {
            $confirmation->update(['status' => OrderConfirmationStatus::Sent, 'sent_at' => now()]);
        }
    }
}

beforeEach(function () {
    app(ConfirmationSettings::class)->save(['enabled' => true]);
    $this->sender = new W1RecordingConfirmationSender;
    app()->instance(ConfirmationSender::class, $this->sender);
    $this->account = ChannelAccount::factory()->create(['platform' => Platform::WhatsApp, 'driver' => 'fake', 'status' => 'connected']);
});

function ocDue(array $attrs = []): OrderConfirmation
{
    return OrderConfirmation::factory()->create(array_merge([
        'order_id' => Order::factory()->create(['status' => OrderStatus::Confirmed])->id,
        'channel_account_id' => test()->account->id,
        'send_at' => now()->subMinute(),
    ], $attrs));
}

function ocRun(OrderConfirmation $c): void
{
    app()->call([new SendOrderConfirmation($c->id), 'handle']);
}

it('sends a due confirmation once, even when the job runs twice', function () {
    $c = ocDue();

    ocRun($c);
    ocRun($c);

    expect($this->sender->sent)->toBe([[$c->id, 'confirm']])
        ->and($c->fresh()->status)->toBe(OrderConfirmationStatus::Sent);
});

it('does not send while another worker holds the row lock', function () {
    $c = ocDue();
    $lock = Cache::lock('order-confirmation-send:'.$c->id, 120);
    $lock->get();

    ocRun($c);
    $lock->release();

    expect($this->sender->sent)->toBe([])->and($c->fresh()->status)->toBe(OrderConfirmationStatus::Scheduled);
});

it('skips instead of sending when the order was cancelled meanwhile', function () {
    $c = ocDue();
    $c->order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

    ocRun($c);

    expect($this->sender->sent)->toBe([])
        ->and($c->fresh()->status)->toBe(OrderConfirmationStatus::Skipped)
        ->and($c->fresh()->skip_reason)->toBe(SkipReason::Cancelled->value);
});

it('skips when the feature was switched off before the send', function () {
    $c = ocDue();
    app(ConfirmationSettings::class)->save(['enabled' => false]);

    ocRun($c);

    expect($this->sender->sent)->toBe([])->and($c->fresh()->skip_reason)->toBe(SkipReason::Disabled->value);
});

it('skips when the WhatsApp account was disconnected meanwhile', function () {
    $c = ocDue();
    $this->account->update(['status' => 'disconnected']);

    ocRun($c);

    expect($this->sender->sent)->toBe([])->and($c->fresh()->skip_reason)->toBe(SkipReason::NoWhatsAppChannel->value);
});

it('never sends from a fake account in production', function () {
    $c = ocDue();
    app()->detectEnvironment(fn () => 'production');

    try {
        ocRun($c);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    expect($this->sender->sent)->toBe([])->and($c->fresh()->skip_reason)->toBe(SkipReason::NoWhatsAppChannel->value);
});

it('leaves rows that are not scheduled alone', function () {
    $c = ocDue(['status' => OrderConfirmationStatus::Skipped, 'skip_reason' => 'no_phone']);

    ocRun($c);

    expect($this->sender->sent)->toBe([]);
});

it('waits when the row was moved later', function () {
    $c = ocDue(['send_at' => now()->addMinutes(5)]);

    ocRun($c);

    expect($this->sender->sent)->toBe([])->and($c->fresh()->status)->toBe(OrderConfirmationStatus::Scheduled);
});

it('marks the row failed instead of retrying when the sender throws', function () {
    $c = ocDue();
    $this->sender->throw = new RuntimeException('Graph API exploded');

    ocRun($c);
    ocRun($c);

    expect($this->sender->sent)->toHaveCount(1)
        ->and($c->fresh()->status)->toBe(OrderConfirmationStatus::Failed)
        ->and($c->fresh()->last_error)->toBe('Graph API exploded');
});

it('never sends twice when a sender forgets to record the send', function () {
    $c = ocDue();
    $this->sender->recordsStatus = false;

    ocRun($c);
    ocRun($c);

    expect($this->sender->sent)->toHaveCount(1)->and($c->fresh()->status)->toBe(OrderConfirmationStatus::Failed);
});

it('skips instead of sending a confirmation that is hours late (queue outage)', function () {
    $c = ocDue(['send_at' => now()->subHours(3)->subMinute()]);

    ocRun($c);

    expect($this->sender->sent)->toBe([])
        ->and($c->fresh()->status)->toBe(OrderConfirmationStatus::Skipped)
        ->and($c->fresh()->skip_reason)->toBe(SkipReason::TooLate->value);
});

it('still sends a confirmation that is a little late', function () {
    $c = ocDue(['send_at' => now()->subHours(2)->subMinutes(50)]);

    ocRun($c);

    expect($this->sender->sent)->toBe([[$c->id, 'confirm']]);
});
