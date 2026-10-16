<?php

use App\Confirmations\ConfirmationSettings;
use App\Confirmations\Contracts\ConfirmationSender;
use App\Confirmations\Events\OrderConfirmationNoResponse;
use App\Confirmations\TagSync;
use App\Enums\OrderConfirmationStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderConfirmation;
use App\Shopify\Client\FakeShopifyTransport;
use App\Shopify\Connection\ShopifyIntegration;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    ShopifyIntegration::create(['shop_domain' => 'levoile-test.myshopify.com', 'access_token' => 't', 'api_secret' => 's', 'status' => 'connected']);
    app(ConfirmationSettings::class)->save(['enabled' => true]);
});

/** A sender that records every call; $stamp mimics the real sender writing reminded_at, $throw a failing one. */
function sweepSender(bool $stamp = true, bool $throw = false): object
{
    $sender = new class($stamp, $throw) implements ConfirmationSender
    {
        /** @var list<array{id: int, kind: string}> */
        public array $calls = [];

        public function __construct(private bool $stamp, private bool $throw) {}

        public function send(OrderConfirmation $confirmation, string $kind = 'confirm'): void
        {
            $this->calls[] = ['id' => $confirmation->id, 'kind' => $kind];

            if ($this->throw) {
                throw new RuntimeException('WhatsApp said no');
            }

            if ($this->stamp) {
                $confirmation->update(['reminded_at' => now()]);
            }
        }
    };

    app()->instance(ConfirmationSender::class, $sender);

    return $sender;
}

function sentHoursAgo(float $hours, array $attrs = []): OrderConfirmation
{
    $order = Order::factory()->create(['shopify_order_id' => (string) fake()->unique()->numberBetween(1000, 999999)]);

    return OrderConfirmation::factory()->sent()->create(array_merge([
        'order_id' => $order->id,
        'sent_at' => now()->subMinutes((int) round($hours * 60)),
    ], $attrs));
}

it('sends one reminder after reminder_after_hours, and only once', function () {
    $sender = sweepSender(stamp: false); // a sender that forgets reminded_at must still not repeat it
    $due = sentHoursAgo(7);
    $early = sentHoursAgo(5);

    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();
    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();

    expect($sender->calls)->toBe([['id' => $due->id, 'kind' => 'reminder']])
        ->and($due->fresh()->reminded_at)->not->toBeNull()
        ->and($due->fresh()->status)->toBe(OrderConfirmationStatus::Sent)
        ->and($early->fresh()->reminded_at)->toBeNull();
});

it('follows the reminder setting', function () {
    $sender = sweepSender();
    app(ConfirmationSettings::class)->save(['reminder_after_hours' => 2]);
    $due = sentHoursAgo(3);

    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();

    expect($sender->calls)->toBe([['id' => $due->id, 'kind' => 'reminder']]);
});

it('does not resend a reminder that failed', function () {
    $sender = sweepSender(throw: true);
    $due = sentHoursAgo(7);

    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();
    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();

    expect($sender->calls)->toHaveCount(1)
        ->and($due->fresh()->reminded_at)->not->toBeNull()
        ->and($due->fresh()->last_error)->toContain('WhatsApp said no');
});

it('moves silent orders to the call list with the no-response tag, without a late reminder', function () {
    $sender = sweepSender();
    $reminded = sentHoursAgo(25, ['reminded_at' => now()->subHours(19)]);
    $neverReminded = sentHoursAgo(30);
    $confirmed = sentHoursAgo(30, ['status' => OrderConfirmationStatus::Confirmed]);

    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();

    expect($reminded->fresh()->status)->toBe(OrderConfirmationStatus::NoResponse)
        ->and($neverReminded->fresh()->status)->toBe(OrderConfirmationStatus::NoResponse)
        ->and($confirmed->fresh()->status)->toBe(OrderConfirmationStatus::Confirmed)
        ->and($sender->calls)->toBe([])
        ->and(OrderConfirmation::needsCall()->count())->toBe(2)
        ->and(array_column(FakeShopifyTransport::tagsAdded(), 'tags'))->toBe([['wa-no-response'], ['wa-no-response']])
        ->and(FakeShopifyTransport::tagsRemoved()[0]['tags'])->toContain('wa-confirmed')->not->toContain('wa-no-response')
        ->and($reminded->fresh()->tag_synced_at)->not->toBeNull();
});

it('dispatches the no-response event once per order', function () {
    sweepSender();
    Event::fake([OrderConfirmationNoResponse::class]);
    $silent = sentHoursAgo(25);

    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();
    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();

    Event::assertDispatchedTimes(OrderConfirmationNoResponse::class, 1);
    Event::assertDispatched(OrderConfirmationNoResponse::class, fn ($e) => $e->confirmation->is($silent));
});

it('retries pending Shopify tags', function () {
    sweepSender();
    $pending = sentHoursAgo(1, ['status' => OrderConfirmationStatus::Confirmed, 'pending_tags' => ['tags' => ['wa-confirmed'], 'attempts' => 3]]);
    $givenUp = sentHoursAgo(1, ['status' => OrderConfirmationStatus::Confirmed, 'pending_tags' => ['tags' => ['wa-confirmed'], 'attempts' => TagSync::MAX_ATTEMPTS]]);

    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();

    expect($pending->fresh()->pending_tags)->toBeNull()
        ->and($pending->fresh()->tag_synced_at)->not->toBeNull()
        ->and($givenUp->fresh()->pending_tags)->not->toBeNull()
        ->and(FakeShopifyTransport::tagsAdded())->toHaveCount(1);
});

it('queues the send job again for a scheduled row whose job got lost, at most every 15 minutes', function () {
    // The send job is slice W1's; a stand-in with its signature until the branches meet.
    if (! class_exists('App\\Confirmations\\Jobs\\SendOrderConfirmation')) {
        eval('namespace App\\Confirmations\\Jobs; final class SendOrderConfirmation implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { use \\Illuminate\\Foundation\\Bus\\Dispatchable, \\Illuminate\\Queue\\InteractsWithQueue, \\Illuminate\\Bus\\Queueable; public function __construct(public readonly int $confirmationId) {} public function handle(): void {} }');
    }

    sweepSender();
    Queue::fake();
    $lost = OrderConfirmation::factory()->create(['send_at' => now()->subMinutes(20)]);
    OrderConfirmation::whereKey($lost->id)->update(['updated_at' => now()->subMinutes(20)]);
    $justDue = OrderConfirmation::factory()->create(['send_at' => now()->subMinutes(5)]);
    $ancient = OrderConfirmation::factory()->create(['send_at' => now()->subDays(3)]);
    OrderConfirmation::whereKey($ancient->id)->update(['updated_at' => now()->subDays(3)]);

    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();
    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();

    Queue::assertPushed('App\\Confirmations\\Jobs\\SendOrderConfirmation', 1);
    Queue::assertPushed('App\\Confirmations\\Jobs\\SendOrderConfirmation', fn ($job) => $job->confirmationId === $lost->id);
    expect($justDue->fresh()->status)->toBe(OrderConfirmationStatus::Scheduled);
});

it('does not remind or move orders to the call list while the feature is off, but still writes owed tags', function () {
    $sender = sweepSender();
    app(ConfirmationSettings::class)->save(['enabled' => false]);
    $due = sentHoursAgo(7);
    $silent = sentHoursAgo(25);
    $pending = sentHoursAgo(1, ['status' => OrderConfirmationStatus::Confirmed, 'pending_tags' => ['tags' => ['wa-confirmed'], 'attempts' => 1]]);

    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();

    expect($sender->calls)->toBe([])
        ->and($due->fresh()->reminded_at)->toBeNull()
        ->and($silent->fresh()->status)->toBe(OrderConfirmationStatus::Sent)
        ->and($pending->fresh()->tag_synced_at)->not->toBeNull();
});

it('closes a cancelled order as skipped instead of reminding or calling', function () {
    $sender = sweepSender();
    $remind = sentHoursAgo(7);
    $remind->order->update(['cancelled_at' => now()]);
    $silent = sentHoursAgo(25);
    $silent->order->update(['status' => OrderStatus::Cancelled]);

    $this->artisan('crm:order-confirmations:sweep')->assertSuccessful();

    expect($sender->calls)->toBe([])
        ->and($remind->fresh())->status->toBe(OrderConfirmationStatus::Skipped)->skip_reason->toBe('cancelled')
        ->and($silent->fresh())->status->toBe(OrderConfirmationStatus::Skipped)->skip_reason->toBe('cancelled')
        ->and(FakeShopifyTransport::tagsAdded())->toBe([]);
});

it('schedules the sweep every five minutes on one server without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'crm:order-confirmations:sweep'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
